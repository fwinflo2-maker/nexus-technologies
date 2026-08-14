<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * BOUCLE 1 — ISOLATION MULTI-TENANT DES OPÉRATIONS DE WALLET.
 *
 * `createHold()` reçoit un `wallet_id` fourni par le client et un `user_id`
 * issu du jeton. Si la propriété du wallet n'est pas vérifiée, un utilisateur
 * authentifié peut geler — puis capturer — les fonds du wallet d'autrui en
 * devinant ou en énumérant un identifiant numérique.
 *
 * Ces tests prouvent l'exploitabilité de bout en bout, plutôt que de conclure
 * sur la seule lecture du SQL.
 */
final class WalletTenantIsolationTest extends TestCase
{
    private PDO $pdo;
    private int $victimId = 0;
    private int $attackerId = 0;
    private int $victimWalletId = 0;
    private int $attackerWalletId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        putenv('PROVIDERS_ENV');

        $this->victimId   = $this->createUser('victim');
        $this->attackerId = $this->createUser('attacker');

        $this->victimWalletId   = $this->createWallet($this->victimId, 'EUR', '1000.00');
        $this->attackerWalletId = $this->createWallet($this->attackerId, 'EUR', '0.00');
    }

    protected function tearDown(): void
    {
        foreach ([$this->victimId, $this->attackerId] as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = ?)'
            )->execute([$uid]);
            foreach (['wallet_operations', 'idempotency_keys', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->victimId = $this->attackerId = 0;
    }

    private function createUser(string $tag): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => ucfirst($tag),
            'e' => $tag . '_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance)
             VALUES (:u, :c, :a1, :a2)'
        );
        $stmt->execute(['u' => $userId, 'c' => $currency, 'a1' => $balance, 'a2' => $balance]);

        return (int) $this->pdo->lastInsertId();
    }

    private function context(int $userId): ExecutionContext
    {
        return ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
    }

    private function victimAvailable(): string
    {
        $stmt = $this->pdo->prepare('SELECT available_balance FROM wallets WHERE id = ?');
        $stmt->execute([$this->victimWalletId]);

        return (string) $stmt->fetchColumn();
    }

    // ══ 1. L'attaque : geler le wallet d'autrui ════════════════════════════

    /**
     * L'attaquant est authentifié sous SON identité mais désigne le wallet de
     * la victime. Le hold doit être refusé.
     */
    public function test_a_user_cannot_hold_funds_on_another_users_wallet(): void
    {
        $before = $this->victimAvailable();

        try {
            WalletService::createHold(
                $this->attackerId,
                $this->victimWalletId,   // wallet d'autrui
                '500.00000000',
                'EUR',
                null,
                'tentative cross-tenant',
                null,
                $this->context($this->attackerId)
            );
            $this->fail('Un utilisateur ne doit pas pouvoir geler les fonds du wallet d\'un autre compte.');
        } catch (HttpException | RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Le solde de la victime est intact.
        $this->assertSame(
            $before,
            $this->victimAvailable(),
            'Le solde disponible de la victime ne doit pas avoir bougé.'
        );

        // Aucune opération n'a été créée sur le wallet de la victime.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_operations WHERE source_wallet_id = ?');
        $stmt->execute([$this->victimWalletId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune opération ne doit viser le wallet de la victime.');
    }

    // ══ 2. Le cas légitime reste possible ══════════════════════════════════

    /**
     * Un refus n'a de valeur que si l'usage normal fonctionne : sans ce test,
     * un contrôle qui bloque tout passerait pour correct.
     */
    public function test_a_user_can_hold_funds_on_their_own_wallet(): void
    {
        $ownWallet = $this->createWallet($this->attackerId, 'USD', '300.00');

        $res = WalletService::createHold(
            $this->attackerId,
            $ownWallet,
            '100.00000000',
            'USD',
            null,
            'hold légitime',
            null,
            $this->context($this->attackerId)
        );

        $this->assertNotSame('', (string) ($res['operation_id'] ?? ''));

        $stmt = $this->pdo->prepare('SELECT user_id FROM wallet_operations WHERE id = ?');
        $stmt->execute([$res['operation_id']]);
        $this->assertSame($this->attackerId, (int) $stmt->fetchColumn());
    }

    // ══ 3. La capture d'une opération d'autrui ═════════════════════════════

    /**
     * Second volet : même si un hold existe légitimement chez la victime,
     * un autre utilisateur ne doit pas pouvoir le capturer.
     */
    public function test_a_user_cannot_capture_another_users_hold(): void
    {
        $hold = WalletService::createHold(
            $this->victimId,
            $this->victimWalletId,
            '200.00000000',
            'EUR',
            null,
            'hold de la victime',
            null,
            $this->context($this->victimId)
        );
        $opId = (string) $hold['operation_id'];

        try {
            WalletService::captureHold($opId, $this->attackerId, null, $this->context($this->attackerId));
            $this->fail('Un utilisateur ne doit pas pouvoir capturer le hold d\'un autre compte.');
        } catch (HttpException | RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // L'opération de la victime n'a pas été capturée.
        $stmt = $this->pdo->prepare('SELECT status, user_id FROM wallet_operations WHERE id = ?');
        $stmt->execute([$opId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->victimId, (int) $op['user_id']);
        $this->assertNotSame('captured', $op['status'], 'Le hold de la victime ne doit pas être capturé.');
    }

    /** Idem pour la libération d'un hold appartenant à autrui. */
    public function test_a_user_cannot_release_another_users_hold(): void
    {
        $hold = WalletService::createHold(
            $this->victimId,
            $this->victimWalletId,
            '150.00000000',
            'EUR',
            null,
            'hold de la victime',
            null,
            $this->context($this->victimId)
        );
        $opId = (string) $hold['operation_id'];

        try {
            WalletService::releaseHold($opId, $this->attackerId, null, $this->context($this->attackerId));
            $this->fail('Un utilisateur ne doit pas pouvoir libérer le hold d\'un autre compte.');
        } catch (HttpException | RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $stmt = $this->pdo->prepare('SELECT status FROM wallet_operations WHERE id = ?');
        $stmt->execute([$opId]);
        $this->assertNotSame('released', (string) $stmt->fetchColumn());
    }

    // ══ 4. Le contrôle de propriété repose-t-il sur un typage fiable ? ═════

    /**
     * `captureHold`/`releaseHold` comparent `$op['user_id'] !== $userId` en
     * strict. PDO peut restituer un entier MySQL sous forme de chaîne selon
     * la configuration : dans ce cas la comparaison stricte serait TOUJOURS
     * vraie et refuserait aussi le propriétaire légitime — un contrôle qui
     * « passe » pour la mauvaise raison.
     *
     * Ce test verrouille le comportement attendu des deux côtés : le
     * propriétaire réussit, l'étranger échoue.
     */
    public function test_ownership_check_accepts_the_owner_and_rejects_others(): void
    {
        $hold = WalletService::createHold(
            $this->victimId,
            $this->victimWalletId,
            '10.00000000',
            'EUR',
            null,
            'hold typage',
            null,
            $this->context($this->victimId)
        );
        $opId = (string) $hold['operation_id'];

        // L'étranger échoue.
        try {
            WalletService::releaseHold($opId, $this->attackerId, null, $this->context($this->attackerId));
            $this->fail('L\'étranger ne doit pas pouvoir libérer ce hold.');
        } catch (HttpException | RuntimeException) {
            // attendu
        }

        // Le propriétaire réussit : preuve que le contrôle ne rejette pas tout
        // le monde à cause d'une comparaison de types mal alignée.
        $res = WalletService::releaseHold($opId, $this->victimId, null, $this->context($this->victimId));
        // Le service marque l'opération 'cancelled' lors d'une libération.
        $this->assertSame(
            'cancelled',
            (string) ($res['status'] ?? ''),
            'Le propriétaire doit pouvoir libérer son hold.'
        );
    }
}
