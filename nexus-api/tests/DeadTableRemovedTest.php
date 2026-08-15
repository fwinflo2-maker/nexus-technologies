<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE B — RÉGRESSION STRUCTURELLE DU SCHÉMA.
 *
 * Vérifie sur la base de test réelle que :
 *   1. la table morte `oauth_identities` (Google OAuth, jamais référencée par
 *      le code) a bien été supprimée par la migration 0.21 ;
 *   2. les tables critiques du modèle financier existent toujours ;
 *   3. les invariants clés du modèle sont présents :
 *        - wallets.UNIQUE(user_id, currency)          → un wallet par (user, devise)
 *        - idempotency_keys.UNIQUE(key, user, env)    → anti-race d'idempotence
 *        - types monétaires DECIMAL (jamais FLOAT/DOUBLE).
 *
 * Ce test part du schéma réellement installé (Database::getConnection() pointe
 * sur nexus_test), il ne lit aucun fichier.
 */
final class DeadTableRemovedTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
    }

    public function testDeadOauthIdentitiesTableIsRemoved(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'oauth_identities'"
        );
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(
            0,
            $count,
            'La table morte `oauth_identities` doit avoir été supprimée par la migration 0.21.'
        );
    }

    public function testCoreFinancialTablesExist(): void
    {
        $required = [
            'users', 'wallets', 'wallet_operations', 'ledger_entries',
            'transactions', 'payment_accounts', 'provider_credentials',
            'idempotency_keys', 'revoked_tokens', 'quotes',
        ];
        $stmt = $this->pdo->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        );
        $stmt->execute();
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($required as $t) {
            $this->assertContains($t, $tables, "Table critique absente : {$t}");
        }
    }

    public function testWalletUniquenessPerUserCurrency(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'wallets'
               AND non_unique = 0
               AND (index_name LIKE 'uq_wallets%' OR index_name LIKE 'unique%')"
        );
        $stmt->execute();
        $uniqueIndexes = (int) $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $uniqueIndexes, 'wallets doit avoir une contrainte UNIQUE.');

        // Vérifie la contrainte sur (user_id, currency) via KEY_COLUMN_USAGE.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'wallets'
               AND constraint_name = 'uq_wallets_user_currency'
               AND column_name IN ('user_id','currency')"
        );
        $stmt->execute();
        $this->assertGreaterThanOrEqual(
            2,
            (int) $stmt->fetchColumn(),
            'wallets doit contraindre UNIQUE(user_id, currency).'
        );
    }

    public function testIdempotencyUniqueKeyCoversUserAndEnv(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'idempotency_keys'
               AND constraint_name = 'uq_idem_key_env'
               AND column_name IN ('idempotency_key','user_id','environment')"
        );
        $stmt->execute();
        $this->assertGreaterThanOrEqual(
            3,
            (int) $stmt->fetchColumn(),
            'idempotency_keys doit contraindre UNIQUE(idempotency_key, user_id, environment).'
        );
    }

    public function testMonetaryColumnsAreDecimalNotFloat(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT table_name, column_name, column_type
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND column_name IN ('balance','available_balance','pending_balance',
                                   'in_transit_balance','settlement_balance','hold_balance',
                                   'amount','fee','fx_rate')
               AND (column_type LIKE 'float%' OR column_type LIKE 'double%')"
        );
        $stmt->execute();
        $bad = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertEmpty(
            $bad,
            'Aucun montant financier ne doit être stocké en FLOAT/DOUBLE. Trouvé : ' . json_encode($bad)
        );
    }
}
