<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\PolicyEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * §12/§13 — KYC → CAPABILITY : le statut KYC/KYB issu de Sumsub (via le
 * webhook vérifié) alimente réellement le PolicyEngine, et le BACKEND
 * bloque ou autorise l'opération. Le frontend n'est jamais la source de
 * décision : il ne fait qu'afficher l'état.
 *
 * Matrice exigée :
 *   - KYC non vérifié (none/basic) + montant > seuil réglementaire → refus ;
 *   - KYC standard → opération autorisée (politique satisfaite) ;
 *   - compte Business sans KYB verified → refus total ;
 *   - compte PENDING / bloqué → refus total ;
 *   - plafond mensuel du niveau KYC atteint → refus.
 */
final class KycCapabilityGatingTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $walletIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        // Liste de sanctions réelle (vide pour FR) : sans elle, le verdict
        // serait REVIEW_REQUIRED pour « contrôle réglementaire manquant » et
        // ne testerait pas le KYC. La liste est retirée en tearDown.
        putenv('NEXUS_SANCTIONS_COUNTRIES=KP,IR,SY,CU');
    }

    protected function tearDown(): void
    {
        putenv('NEXUS_SANCTIONS_COUNTRIES');
        try {
            if ($this->walletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->walletIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->walletIds);
            }
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[KycCapabilityGatingTest::tearDown] ' . $e->getMessage() . PHP_EOL);
        }
    }

    private function createUser(string $kyc, string $status = 'ACTIVE', string $type = 'personal', string $kyb = 'none'): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level, kyb_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['KYC Gate ' . $suffix, 'kycgate_' . $suffix . '@nexus.test', '', $type, $status, $kyc, $kyb]);
        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance,
                                  pending_balance, in_transit_balance, settlement_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, 'EUR', $balance, $balance, '0.00', '0.00', '0.00', '0.00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->walletIds[] = $id;
        return $id;
    }

    /**
     * Évalue la politique pour un utilisateur. Un refus lève HttpException 403.
     */
    private function evaluate(int $userId, array $user, float $amount): array
    {
        $intent = [
            'amount'         => $amount,
            'sourceCurrency' => 'EUR',
            'destCountry'    => 'FR',
        ];
        return PolicyEngine::evaluate($user, $intent, $amount, ExecutionEnvironment::SANDBOX);
    }

    /** Assert qu'un refus HTTP 403 est levé. */
    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
        } catch (\Nexus\Core\HttpException $e) {
            self::assertSame(403, $e->statusCode(), 'Un refus de politique est HTTP 403.');
            return;
        }
        self::fail('L\'opération aurait dû être refusée (HttpException 403).');
    }

    // ── KYC non vérifié → opération refusée au-delà du seuil réglementaire ──

    public function test_kyc_none_gros_montant_est_bloque(): void
    {
        $uid = $this->createUser('none');
        $this->createWallet($uid, '5000.00');

        // KYC none : plafond mensuel 250 EUR (5e directive AML, e-money sans
        // due diligence). 1500 EUR dépasse largement → refus 403. Le backend
        // bloque : le frontend n'est pas la décision.
        $this->assertRefused(fn () => $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'none', 'account_type' => 'personal',
        ], 1500.0));
    }

    public function test_kyc_basic_gros_montant_est_bloque(): void
    {
        $uid = $this->createUser('basic');
        $this->createWallet($uid, '5000.00');

        // KYC basic : plafond mensuel 1000 EUR (règlement UE 2015/847).
        // 1500 EUR dépasse → refus 403.
        $this->assertRefused(fn () => $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'basic', 'account_type' => 'personal',
        ], 1500.0));
    }

    // ── KYC vérifié → opération autorisée ──────────────────────────────────

    public function test_kyc_standard_montant_superieur_au_seuil_est_approuve(): void
    {
        $uid = $this->createUser('standard');
        $this->createWallet($uid, '5000.00');

        $result = $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'standard', 'account_type' => 'personal',
        ], 1500.0);

        self::assertSame('APPROVED', $result['decision'], 'KYC standard satisfait la politique.');
    }

    // ── KYB obligatoire pour les comptes Business ──────────────────────────

    public function test_entreprise_sans_kyb_verified_est_refusee(): void
    {
        $uid = $this->createUser('advanced', 'ACTIVE', 'business', 'in_progress');
        $this->createWallet($uid, '5000.00');

        $this->assertRefused(fn () => $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'advanced',
            'account_type' => 'business', 'kyb_status' => 'in_progress',
        ], 500.0));
    }

    public function test_entreprise_kyb_verified_est_autorisee(): void
    {
        $uid = $this->createUser('advanced', 'ACTIVE', 'business', 'verified');
        $this->createWallet($uid, '5000.00');

        $result = $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'advanced',
            'account_type' => 'business', 'kyb_status' => 'verified',
        ], 500.0);

        self::assertSame('APPROVED', $result['decision'], 'KYB verified satisfait la politique.');
    }

    // ── Compte en attente / bloqué → refus total ───────────────────────────

    public function test_compte_pending_est_refuse(): void
    {
        $uid = $this->createUser('standard', 'PENDING');
        $this->createWallet($uid, '5000.00');

        $this->assertRefused(fn () => $this->evaluate($uid, [
            'id' => $uid, 'status' => 'PENDING', 'kyc_level' => 'standard', 'account_type' => 'personal',
        ], 100.0));
    }

    // ── Plafond mensuel du niveau KYC atteint → refus ──────────────────────

    public function test_plafond_mensuel_kyc_none_atteint_est_refuse(): void
    {
        $uid = $this->createUser('none');
        $this->createWallet($uid, '5000.00');

        // Plafond none = 250 EUR (5e directive AML). 300 EUR dépasse → refus.
        $this->assertRefused(fn () => $this->evaluate($uid, [
            'id' => $uid, 'status' => 'ACTIVE', 'kyc_level' => 'none', 'account_type' => 'personal',
        ], 300.0));
    }
}
