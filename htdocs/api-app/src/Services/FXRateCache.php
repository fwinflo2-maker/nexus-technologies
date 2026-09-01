<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Models\FXRate;
use PDO;
use RuntimeException;

/**
 * Accès au cache `fx_rates_cache`, TOUJOURS scopé par environnement.
 *
 * POURQUOI L'ENVIRONNEMENT EST OBLIGATOIRE ICI
 * ────────────────────────────────────────────
 * `lookup()` ne filtrait que sur la paire de devises et l'expiration. Toutes
 * les autres couches financières du projet sont pourtant isolées — ledger,
 * quotes, payments, transactions, wallet_operations, fiabilité, latence — et
 * ce cache était le dernier maillon partagé.
 *
 * Vérifié en HTTP avant correctif : un taux `EUR→XAF = 100` de source
 * « audit_sandbox » a été servi à une quote demandée en PRODUCTION, et un
 * taux « audit_production » à 200 a été servi en sandbox, sur Send comme sur
 * Convert. La contamination allait donc dans les deux sens.
 *
 * L'environnement n'est pas un paramètre optionnel : il est exigé à la
 * lecture comme à l'écriture. Un défaut implicite rouvrirait exactement la
 * faille que cette classe doit fermer.
 */
final class FXRateCache
{
    private function __construct() {}

    /**
     * Dernier taux non expiré d'une paire, DANS l'environnement demandé.
     *
     * Aucun repli inter-environnement : si la sandbox ne connaît pas la
     * paire, on ne va pas la chercher en production, et réciproquement.
     *
     * @return FXRate|null
     */
    public static function lookup(
        string $baseCurrency,
        string $quoteCurrency,
        ExecutionEnvironment $environment
    ): ?FXRate {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at
            FROM fx_rates_cache
            WHERE base_currency = :base AND quote_currency = :quote
              AND environment = :env
              AND expires_at > NOW()
            ORDER BY fetched_at DESC
            LIMIT 1'
        );
        $stmt->execute([
            'base'  => strtoupper($baseCurrency),
            'quote' => strtoupper($quoteCurrency),
            'env'   => $environment->value,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return new FXRate(
            $row['base_currency'],
            $row['quote_currency'],
            $row['rate'],
            $row['spread_pct'],
            $row['source'],
            new \DateTimeImmutable($row['fetched_at']),
            new \DateTimeImmutable($row['expires_at'])
        );
    }

    /**
     * Enregistre un taux DANS un environnement donné.
     *
     * L'environnement est explicite et sans valeur par défaut : écrire un
     * taux sans dire à quel monde il appartient est précisément ce qui a
     * permis à un taux de test de coter de l'argent réel.
     */
    public static function store(FXRate $rate, ExecutionEnvironment $environment): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
            VALUES
                (:base, :quote, :rate, :spread, :source, :env, :fetched, :expires)'
        );
        $stmt->execute([
            'base'    => $rate->getBaseCurrency(),
            'quote'   => $rate->getQuoteCurrency(),
            'rate'    => $rate->getRate(),
            'spread'  => $rate->getSpreadPct(),
            'source'  => $rate->getSource(),
            'env'     => $environment->value,
            'fetched' => $rate->getFetchedAt()->format('Y-m-d H:i:s'),
            'expires' => $rate->getExpiresAt()->format('Y-m-d H:i:s'),
        ]);
    }
}
