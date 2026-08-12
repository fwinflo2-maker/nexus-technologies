<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Services\PolicyEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests du PolicyEngine - Phase C1.
 *
 * Base utilisee : nexus_test (isolee, JAMAIS nexus).
 * Strategie d isolation : chaque test cree ses propres fixtures (user +
 * wallets) identifies par un suffixe unique (timestamp + compteur +
 * aleatoire). Le tearDown supprime exactement les IDs crees par chaque test.
 *
 * PHP     : 8.2.12
 * PHPUnit : ^10.0
 */
final class PolicyEngineTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds: list<int>, walletIds: list<int>} */
    private array $created = [
        'userIds'   => [],
        'walletIds' => [],
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". '
                . 'Les tests PolicyEngineTest doivent utiliser nexus_test uniquement.'
            );
        }

        $this->created = ['userIds' => [], 'walletIds' => []];
    }

    protected function tearDown(): void
    {
        try {
            if (!empty($this->created['walletIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)");
                $stmt->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)");
                $stmt->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[PolicyEngineTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    private function uniqueSuffix(): string
    {
        self::$counter++;
        return sprintf('%d_%d_%s', time(), self::$counter, bin2hex(random_bytes(3)));
    }

    private function createUser(string $suffix, string $status = 'ACTIVE', string $kyc = 'standard'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:name, :email, :pwd, :type, :status, :kyc)'
        );
        $stmt->execute([
            'name'   => 'PolicyUser ' . $suffix,
            'email'  => 'policy_' . $suffix . '@nexus-test.local',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => $status,
            'kyc'    => $kyc,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(
        int    $userId,
        string $currency,
        string $balance,
        string $hold        = '0.00'
    ): int {
        $available = bcsub($balance, $hold, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets
                (user_id, currency, balance, available_balance, hold_balance,
                 pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, :hold, 0, 0, 0)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'cur'     => $currency,
            'bal'     => $balance,
            'avail'   => $available,
            'hold'    => $hold,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    // ---- Tests -------------------------------------------------------------

    /**
     * PolicyEngine accepte si le solde disponible est suffisant.
     */
    public function test_evaluate_approuve_si_solde_disponible_suffisant(): void
    {
        $s  = $this->uniqueSuffix();
        $uid = $this->createUser($s, 'ACTIVE', 'standard');
        $this->createWallet($uid, 'EUR', '500.00', '100.00'); // disponible = 400.00

        $user = [
            'id'           => $uid,
            'status'       => 'ACTIVE',
            'kyc_level'    => 'standard',
            'account_type' => 'personal',
        ];

        // Demande 300.00 EUR (doit etre approuve car disponible 400.00)
        $intent = [
            'amount'         => 300.0,
            'sourceCurrency' => 'EUR',
            'destCountry'    => 'FR',
        ];

        $res = PolicyEngine::evaluate($user, $intent, 300.0);

        $this->assertSame('APPROVED', $res['decision']);
        $this->assertSame(400.0, $res['details']['wallet_available']);
    }

    /**
     * PolicyEngine refuse si le solde disponible est insuffisant
     * (meme si le balance total est superieur au montant demande).
     */
    public function test_evaluate_refuse_si_solde_disponible_insuffisant(): void
    {
        $s   = $this->uniqueSuffix();
        $uid = $this->createUser($s, 'ACTIVE', 'standard');
        $this->createWallet($uid, 'EUR', '500.00', '250.00'); // disponible = 250.00

        $user = [
            'id'           => $uid,
            'status'       => 'ACTIVE',
            'kyc_level'    => 'standard',
            'account_type' => 'personal',
        ];

        // Demande 300.00 EUR (decline car disponible 250.00, bien que balance = 500.00)
        $intent = [
            'amount'         => 300.0,
            'sourceCurrency' => 'EUR',
            'destCountry'    => 'FR',
        ];

        try {
            PolicyEngine::evaluate($user, $intent, 300.0);
            $this->fail('HttpException non levée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('POLICY_DECLINED', $e->errorCode());
            $this->assertStringContainsString('Fonds insuffisants', $e->getMessage());
        }
    }

    /**
     * PolicyEngine refuse si aucun wallet n existe.
     */
    public function test_evaluate_refuse_si_wallet_absent(): void
    {
        $s   = $this->uniqueSuffix();
        $uid = $this->createUser($s, 'ACTIVE', 'standard');

        $user = [
            'id'           => $uid,
            'status'       => 'ACTIVE',
            'kyc_level'    => 'standard',
            'account_type' => 'personal',
        ];

        $intent = [
            'amount'         => 10.0,
            'sourceCurrency' => 'EUR',
            'destCountry'    => 'FR',
        ];

        try {
            PolicyEngine::evaluate($user, $intent, 10.0);
            $this->fail('HttpException non levée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('POLICY_DECLINED', $e->errorCode());
            $this->assertStringContainsString('Fonds insuffisants', $e->getMessage());
        }
    }
}
