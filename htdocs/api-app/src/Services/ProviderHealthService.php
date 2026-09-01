<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Providers\ProviderCapabilityMatrix;
use PDO;

/**
 * ProviderHealthService — état de santé consolidé par provider et par
 * environnement (§6).
 *
 * Combine :
 *   - la présence/statut des credentials (provider_credentials) ;
 *   - le résultat du dernier test de connexion RÉEL (CONNECTION_SUCCESS ou
 *     échec avec code) ;
 *   - les capacités réellement implémentées (matrice).
 *
 * États exposés :
 *   disabled     — aucune credential, ou credential désactivée ;
 *   configured   — credentials présentes, jamais testées ;
 *   connected    — dernier test RÉEL réussi ;
 *   degraded     — credentials présentes mais dernier test en échec ;
 *   unavailable  — credentials en erreur bloquante (statut 'error').
 *
 * AUCUN secret n'est jamais renvoyé : uniquement statuts et horodatages.
 */
final class ProviderHealthService
{
    private function __construct()
    {
    }

    /**
     * Santé d'un provider pour un environnement donné.
     *
     * @return array<string, mixed>
     */
    public static function healthFor(PDO $pdo, string $slug, string $environment): array
    {
        $row = ProviderCredentialService::findPlatformRow($pdo, $slug, $environment);

        if ($row === null || ($row['credentials_enc'] ?? null) === null) {
            return [
                'provider'              => $slug,
                'environment'           => $environment,
                'configured'            => false,
                'connection'            => 'NOT_CONFIGURED',
                'last_successful_test'  => null,
                'last_failed_test'      => null,
                'last_error_code'       => null,
                'last_checked'          => null,
            ];
        }

        $dbStatus = (string) ($row['status'] ?? 'not_configured');
        $lastError = (string) ($row['last_error'] ?? '');
        $lastTested = $row['last_tested_at'] !== null
            ? self::toIso8601((string) $row['last_tested_at'])
            : null;

        // Statut bloquant en base (test réel en échec) → dégradé / indisponible.
        if ($dbStatus === 'error') {
            return [
                'provider'              => $slug,
                'environment'           => $environment,
                'configured'            => true,
                'connection'            => $lastError !== '' ? 'degraded' : 'unavailable',
                'last_successful_test'  => null,
                'last_failed_test'      => $lastTested,
                'last_error_code'       => $lastError !== '' ? $lastError : 'CONNECTION_FAILED',
                'last_checked'          => $lastTested,
            ];
        }

        if ($dbStatus === 'sandbox_only' || $dbStatus === 'active') {
            return [
                'provider'              => $slug,
                'environment'           => $environment,
                'configured'            => true,
                'connection'            => $lastTested !== null ? 'connected' : 'configured',
                'last_successful_test'  => $lastTested,
                'last_failed_test'      => null,
                'last_error_code'       => null,
                'last_checked'          => $lastTested,
            ];
        }

        return [
            'provider'              => $slug,
            'environment'           => $environment,
            'configured'            => false,
            'connection'            => 'NOT_CONFIGURED',
            'last_successful_test'  => null,
            'last_failed_test'      => null,
            'last_error_code'       => null,
            'last_checked'          => null,
        ];
    }

    /**
     * Santé de TOUS les providers du catalogue, dans les deux environnements.
     *
     * @return list<array<string, mixed>>
     */
    public static function summary(PDO $pdo): array
    {
        $rows = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            foreach (['sandbox', 'production'] as $env) {
                $health = self::healthFor($pdo, $slug, $env);
                $health['name']       = (string) $provider['name'];
                $health['category']   = (string) $provider['category'];
                $health['capabilities'] = ProviderCapabilityMatrix::for($slug);
                $rows[] = $health;
            }
        }
        return $rows;
    }

    private static function toIso8601(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '') {
            return null;
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }
}
