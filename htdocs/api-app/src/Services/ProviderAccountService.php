<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use PDO;

/**
 * ProviderAccountService — comptes de Nexus chez les providers (modèle cible).
 *
 * Un provider account est l'ANCRE de la réponse « où est l'argent » :
 *   User Position → legs PROVIDER_ASSET/PROVIDER_SETTLEMENT → provider account
 *   → external_account_id chez le partenaire → provider_balances (observations).
 *
 * Règles :
 *   - UN compte par (provider_slug, environment, currency) — contrainte
 *     UNIQUE en base, jamais deux comptes concurrents pour la même devise.
 *   - L'environnement est strict : un compte sandbox n'est jamais utilisé
 *     par une opération production (et réciproquement).
 *   - La création est une décision de configuration : en production, les
 *     comptes sont pré-créés par l'admin ; en sandbox (développement), la
 *     résolution peut créer le compte manquant pour que le rail fonctionne.
 */
final class ProviderAccountService
{
    private function __construct()
    {
    }

    /**
     * Résout le compte provider pour (slug, environnement, devise) : retourne
     * le compte existant actif, ou le crée si absent (sandbox/développement).
     *
     * @param string $providerSlug   'cashramp', ...
     * @param string $environment    sandbox|production
     * @param string $currency       devise du compte
     * @param string $accountType    safeguarding|settlement|operating|pool
     */
    public static function resolve(
        string $providerSlug,
        string $environment,
        string $currency,
        string $accountType = 'settlement'
    ): int {
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new HttpException(422, 'Environnement invalide.', 'INVALID_ENVIRONMENT');
        }
        if (!in_array($accountType, ['safeguarding', 'settlement', 'operating', 'pool'], true)) {
            throw new HttpException(422, "Type de compte invalide.", 'INVALID_ACCOUNT_TYPE');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            "SELECT id FROM provider_accounts
             WHERE provider_slug = :slug AND environment = :env AND currency = :cur
             LIMIT 1"
        );
        $stmt->execute(['slug' => $providerSlug, 'env' => $environment, 'cur' => strtoupper($currency)]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        // Création (idempotente sous verrou : un seul compte par devise).
        // Rejoint la transaction du caller si elle existe (jamais de
        // beginTransaction imbriqué).
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM provider_accounts
                 WHERE provider_slug = :slug AND environment = :env AND currency = :cur
                 FOR UPDATE"
            );
            $stmt->execute(['slug' => $providerSlug, 'env' => $environment, 'cur' => strtoupper($currency)]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return (int) $existing;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO provider_accounts
                    (provider_slug, environment, external_account_id, currency, account_type, status, label)
                 VALUES (:slug, :env, NULL, :cur, :type, :status, :label)'
            );
            $stmt->execute([
                'slug'   => $providerSlug,
                'env'    => $environment,
                'cur'    => strtoupper($currency),
                'type'   => $accountType,
                'status' => 'active',
                'label'  => sprintf('%s %s %s (%s)', ucfirst($providerSlug), strtoupper($currency), $environment, $accountType),
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Solde observé attendu (PROVIDER_ASSET) pour un compte : la somme des
     * legs debit − credit portant ce compte, dans le GL courant (is_legacy=0).
     * C'est la valeur NEXUS attendue, confrontée aux provider_balances par la
     * réconciliation.
     */
    public static function expectedAssetBalance(int $providerAccountId, string $providerSlug, string $currency): string
    {
        $pdo = Database::getConnection();
        $accountCode = 'PROVIDER_ASSET.' . $providerSlug . '.' . strtoupper($currency);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN entry_type = 'debit'  THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END), 0)
             FROM ledger_entries
            WHERE account_code = :acc AND is_legacy = 0"
        );
        $stmt->execute(['acc' => $accountCode]);
        $balance = (string) $stmt->fetchColumn();
        // Valeur attendue : l'asset doit être ≥ 0 ; un négatif signale un
        // payout non couvert par du funding (réconciliation le détecte).
        return bcadd($balance, '0', 8);
    }

    /** @return list<array<string,mixed>> Comptes (pour l'admin/rapports). */
    public static function list(string $environment = ''): array
    {
        $pdo = Database::getConnection();
        if ($environment !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM provider_accounts WHERE environment = :env ORDER BY provider_slug, currency'
            );
            $stmt->execute(['env' => $environment]);
        } else {
            $stmt = $pdo->query('SELECT * FROM provider_accounts ORDER BY provider_slug, currency');
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
