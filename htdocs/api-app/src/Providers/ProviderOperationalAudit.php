<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;
use Throwable;

/**
 * ProviderOperationalAudit — classification opérationnelle HONNÊTE (§1, §24–§26).
 *
 * Distingue strictement :
 *   IMPLEMENTED / NOT_IMPLEMENTED
 *   CONFIGURED / CREDENTIALS_NOT_CONFIGURED
 *   CONNECTED / CONNECTION_FAILED / BLOCKED
 *   SANDBOX_TESTED / PRODUCTION_NOT_TESTED / NOT_TESTED
 *   AVAILABLE (uniquement adapter + credentials + connection + capability)
 *
 * Aucun mock, aucun succès inventé : un appel réel est requis pour CONNECTED.
 */
final class ProviderOperationalAudit
{
    private function __construct()
    {
    }

    /**
     * Audit d'un provider pour un environnement.
     *
     * @param bool $attemptConnection Si true et credentials présents, appelle
     *        testConnection() réellement. Sinon la connexion reste NOT_TESTED
     *        (ou BLOCKED si credentials absents).
     *
     * @return array<string, mixed>
     */
    public static function audit(string $slug, string $environment = 'sandbox', bool $attemptConnection = true): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';

        if (!ProviderCatalog::exists($slug)) {
            return [
                'provider'              => $slug,
                'environment'           => $env,
                'in_catalog'            => false,
                'implementation'        => 'NOT_IMPLEMENTED',
                'adapter'               => 'NONE',
                'credentials'           => 'CREDENTIALS_NOT_CONFIGURED',
                'connection'            => 'BLOCKED',
                'sandbox'               => $env === 'sandbox' ? 'NOT_TESTED' : 'PRODUCTION_NOT_TESTED',
                'production'            => 'PRODUCTION_NOT_TESTED',
                'available'             => false,
                'capability_payout'     => ProviderCapabilityMatrix::NOT_IMPLEMENTED,
                'integration'           => ProviderCapabilityMatrix::NOT_IMPLEMENTED,
                'test_status'           => null,
                'test_message'          => 'Provider inconnu du catalogue.',
                'missing_required'      => [],
                'schema_verified'       => false,
                'priority'              => self::priority($slug),
            ];
        }

        $adapterClass = self::adapterClass($slug);
        $caps         = ProviderCapabilityMatrix::for($slug);
        $integration  = ProviderCapabilityMatrix::integrationStatus($slug);
        $hasRealTest  = $caps['test_connection'] === ProviderCapabilityMatrix::IMPLEMENTED;

        $credState = self::credentialsState($slug, $env);
        $connection = 'BLOCKED';
        $testStatus = null;
        $testMessage = null;
        $sandboxState = $env === 'sandbox' ? 'NOT_TESTED' : 'PRODUCTION_NOT_TESTED';
        $productionState = 'PRODUCTION_NOT_TESTED';

        if ($credState['status'] !== 'CONFIGURED') {
            $connection = 'BLOCKED';
            $testStatus = 'PROVIDER_NOT_CONFIGURED';
            $testMessage = 'Credentials absentes : aucun appel sortant.';
        } elseif (!$hasRealTest) {
            // Credentials présentes mais pas d'implémentation réelle de testConnection.
            $connection = 'BLOCKED';
            $testStatus = 'CONFIGURATION_ERROR';
            $testMessage = 'testConnection() non implémenté pour ce provider — aucun succès déclaré.';
        } elseif ($attemptConnection) {
            try {
                $result = ProviderRegistry::adapter($slug)->testConnection($env);
                $testStatus = (string) ($result['status'] ?? 'UNKNOWN');
                $testMessage = (string) ($result['message'] ?? '');
                $connection = match ($testStatus) {
                    'CONNECTION_SUCCESS' => 'CONNECTED',
                    'PROVIDER_NOT_CONFIGURED' => 'BLOCKED',
                    'INVALID_CREDENTIALS', 'UNAUTHORIZED',
                    'PROVIDER_UNAVAILABLE', 'TIMEOUT', 'CONFIGURATION_ERROR' => 'CONNECTION_FAILED',
                    default => 'CONNECTION_FAILED',
                };
            } catch (Throwable $e) {
                $connection = 'CONNECTION_FAILED';
                $testStatus = 'CONFIGURATION_ERROR';
                $testMessage = 'Exception pendant testConnection (détail non secret).';
            }
        } else {
            $connection = 'NOT_TESTED';
            $testStatus = null;
            $testMessage = 'Connexion non tentée (--no-connect).';
        }

        if ($env === 'sandbox') {
            $sandboxState = $connection === 'CONNECTED' ? 'SANDBOX_TESTED' : 'NOT_TESTED';
        } else {
            $productionState = $connection === 'CONNECTED' ? 'CONNECTED' : 'PRODUCTION_NOT_TESTED';
            $sandboxState = 'NOT_TESTED';
        }

        // AVAILABLE uniquement si adapter réel + credentials + connection + capacité payout (ou test).
        $available = $integration === ProviderCapabilityMatrix::IMPLEMENTED
            && $credState['status'] === 'CONFIGURED'
            && $connection === 'CONNECTED'
            && (
                $caps['payout'] === ProviderCapabilityMatrix::IMPLEMENTED
                || $caps['test_connection'] === ProviderCapabilityMatrix::IMPLEMENTED
            );

        return [
            'provider'              => $slug,
            'name'                  => (string) (ProviderCatalog::get($slug)['name'] ?? $slug),
            'category'              => (string) (ProviderCatalog::get($slug)['category'] ?? ''),
            'environment'           => $env,
            'in_catalog'            => true,
            'implementation'        => $integration,
            'adapter'               => $adapterClass,
            'credentials'           => $credState['status'],
            'credentials_source'    => $credState['source'],
            'connection'            => $connection,
            'sandbox'               => $sandboxState,
            'production'            => $productionState,
            'available'             => $available,
            'capability_payout'     => $caps['payout'],
            'capabilities'          => $caps,
            'integration'           => $integration,
            'test_status'           => $testStatus,
            'test_message'          => $testMessage,
            'missing_required'      => $credState['missing'],
            'schema_verified'       => ProviderCredentialSchema::isVerified($slug),
            'priority'              => self::priority($slug),
            'env_var_prefix'        => 'PROVIDER_' . strtoupper($slug) . '_SANDBOX_',
        ];
    }

    /**
     * Audit de tous les providers du catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public static function auditAll(string $environment = 'sandbox', bool $attemptConnection = true): array
    {
        $rows = [];
        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            $rows[] = self::audit($slug, $environment, $attemptConnection);
        }
        return $rows;
    }

    /** Priorité organisationnelle P1–P4 (informational, n'altère pas le routing). */
    public static function priority(string $slug): string
    {
        return match ($slug) {
            'pawapay', 'onfriq', 'stripe', 'stripe_issuing', 'maplerad', 'bridge' => 'P1',
            'thunes', 'nium', 'currencycloud' => 'P2',
            'wise', 'yellow_card', 'bvnk' => 'P3',
            'dlocal', 'ebanx', 'tazapay', '2c2p', 'xendit' => 'P4',
            default => 'P?',
        };
    }

    /** Classe d'adaptateur résolue (sans secrets). */
    public static function adapterClass(string $slug): string
    {
        return match ($slug) {
            'stripe' => 'StripeAdapter',
            'stripe_issuing' => 'StripeIssuingAdapter',
            'maplerad' => 'MapleradIssuingAdapter',
            'pawapay' => 'PawaPayAdapter',
            'western_union' => 'WesternUnionAdapter',
            'moneygram' => 'MoneyGramAdapter',
            default => 'ConfigDrivenProviderAdapter',
        };
    }

    /**
     * @return array{status: string, source: string, missing: list<string>}
     */
    private static function credentialsState(string $slug, string $environment): array
    {
        $missing = [];
        $provider = ProviderCatalog::get($slug) ?? [];
        foreach (($provider['credentials'] ?? []) as $field) {
            if (!($field['required'] ?? false)) {
                continue;
            }
            $key = (string) $field['key'];
            if (ProviderConfig::credential($slug, $key, $environment) === null) {
                $missing[] = $key;
            }
        }

        // Env scopé complet (tous required présents) — même si ENABLED=false.
        if ($missing === []) {
            $anyRequired = false;
            foreach (($provider['credentials'] ?? []) as $field) {
                if ($field['required'] ?? false) {
                    $anyRequired = true;
                    break;
                }
            }
            if ($anyRequired) {
                return ['status' => 'CONFIGURED', 'source' => 'env', 'missing' => []];
            }
        }

        // Credentials plateforme chiffrées en base.
        try {
            $row = ProviderCredentialService::findPlatformRow(
                Database::getConnection(),
                $slug,
                $environment
            );
            if ($row !== null
                && ($row['credentials_enc'] ?? null) !== null
                && ($row['credentials_enc'] ?? '') !== ''
                && !in_array((string) ($row['status'] ?? ''), ['error', 'disabled'], true)
            ) {
                return ['status' => 'CONFIGURED', 'source' => 'platform_db', 'missing' => []];
            }
        } catch (Throwable) {
            // Base indisponible : on reste sur l'état env.
        }

        return [
            'status'  => 'CREDENTIALS_NOT_CONFIGURED',
            'source'  => 'none',
            'missing' => $missing !== [] ? $missing : self::requiredFieldNames($slug),
        ];
    }

    /** @return list<string> */
    private static function requiredFieldNames(string $slug): array
    {
        $out = [];
        foreach ((ProviderCatalog::get($slug)['credentials'] ?? []) as $field) {
            if ($field['required'] ?? false) {
                $out[] = (string) $field['key'];
            }
        }
        return $out;
    }
}
