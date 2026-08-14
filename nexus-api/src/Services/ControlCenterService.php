<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Kyc\SumsubAdapter;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Providers\ProviderRegistry;
use PDO;

/**
 * ControlCenterService — agrégation des données du NEXUS CONTROL CENTER.
 *
 * RÈGLE ABSOLUE (§25, §37) : toute valeur exposée provient d'une mesure réelle
 * (base de données, configuration d'environnement, introspection des
 * adaptateurs). Aucun chiffre inventé, aucun score de fiabilité fabriqué,
 * aucun taux de succès simulé.
 *
 * Lorsqu'une information n'est pas connue, elle vaut `null` / 'unknown' et
 * l'interface doit l'afficher comme telle — jamais une valeur plausible
 * inventée pour « remplir » un tableau de bord.
 */
final class ControlCenterService
{
    /**
     * Opérations métier du contrat ProviderAdapter.
     *
     * Sert à mesurer honnêtement ce qui est réellement implémenté : un
     * adaptateur qui hérite de l'implémentation abstraite lève
     * ProviderOperationNotImplemented (§30).
     */
    public const PROVIDER_OPERATIONS = [
        'getQuote',
        'createPayment',
        'getPaymentStatus',
        'cancelPayment',
        'getBalance',
        'verifyWebhook',
    ];

    private function __construct()
    {
    }

    /**
     * Une opération est-elle RÉELLEMENT implémentée par l'adaptateur ?
     *
     * Mesure par introspection : si la méthode est déclarée par la classe
     * abstraite (qui lève NotImplemented) et non surchargée par l'adaptateur
     * concret, l'opération n'est pas disponible.
     */
    public static function operationImplemented(string $slug, string $operation): bool
    {
        try {
            $adapter = ProviderRegistry::adapter($slug);
        } catch (\Throwable) {
            return false;
        }

        try {
            $method = new \ReflectionMethod($adapter, $operation);
        } catch (\ReflectionException) {
            return false;
        }

        $declaring = $method->getDeclaringClass()->getName();

        // Déclarée par la classe abstraite → non implémentée concrètement.
        return $declaring !== \Nexus\Providers\AbstractProviderAdapter::class;
    }

    /**
     * Matrice des opérations réellement supportées par un provider (§21).
     *
     * @return array<string,bool>
     */
    public static function operationMatrix(string $slug): array
    {
        $out = [];
        foreach (self::PROVIDER_OPERATIONS as $op) {
            $out[$op] = self::operationImplemented($slug, $op);
        }
        return $out;
    }

    /**
     * Statut de vérification documentaire d'un provider (§22).
     *
     * « verified » signifie : confirmé sur la documentation officielle.
     * Tout le reste est 'unknown' — jamais une supposition optimiste.
     *
     * @return array<string,string>
     */
    public static function documentationStatus(string $slug): array
    {
        $schemaVerified = ProviderCredentialSchema::isVerified($slug);
        $provider       = ProviderCatalog::get($slug) ?? [];

        // Une URL sandbox distincte de la production est une preuve faible mais
        // factuelle que les deux environnements ont été identifiés.
        $sandboxUrl = (string) ($provider['sandbox_url'] ?? '');
        $prodUrl    = (string) ($provider['base_url'] ?? '');

        $webhookVerified = false;
        foreach (ProviderCredentialSchema::for($slug) ?? [] as $def) {
            if ($def->usage === \Nexus\Providers\CredentialDefinition::USAGE_WEBHOOK) {
                $webhookVerified = true;
            }
        }

        $publicKeyVerified = $schemaVerified
            ? (ProviderCredentialSchema::frontendExposableFields($slug) !== [] ? 'verified' : 'none_exposable')
            : 'unknown';

        return [
            'documentation'  => $schemaVerified ? 'verified' : 'unknown',
            'authentication' => $schemaVerified ? 'verified' : 'unknown',
            'public_key'     => $publicKeyVerified,
            'webhook'        => $schemaVerified ? ($webhookVerified ? 'verified' : 'not_documented') : 'unknown',
            'sandbox'        => $sandboxUrl !== '' ? 'declared' : 'unknown',
            'production'     => $prodUrl !== '' ? 'declared' : 'unknown',
        ];
    }

    /**
     * Fiche complète d'un provider pour le Control Center (§3, §4).
     * NE CONTIENT JAMAIS de valeur de credential.
     */
    public static function providerCard(PDO $pdo, int $userId, string $slug): array
    {
        $provider = ProviderCatalog::get($slug) ?? [];
        $adapter  = ProviderRegistry::adapter($slug);
        $config   = $adapter->validateConfiguration();

        // État des credentials PAR ENVIRONNEMENT (§10) : jamais un état global.
        $environments = [];
        foreach (['sandbox', 'production'] as $env) {
            // Credential de PLATEFORME d'abord : le Control Center doit refléter
            // l'état réel de l'infrastructure, pas celui du compte qui consulte.
            $row = ProviderCredentialService::findEffectiveRow($pdo, $userId, $slug, $env);
            $environments[$env] = [
                'configured'     => $row !== null && ($row['credentials_enc'] ?? null) !== null,
                'status'         => $row['status'] ?? 'not_configured',
                'last_tested_at' => $row['last_tested_at'] ?? null,
                'last_error'     => $row['last_error'] ?? null,
                'updated_at'     => $row['updated_at'] ?? null,
                'base_url'       => ProviderConfig::baseUrl($slug, $env),
            ];
        }

        $operations = self::operationMatrix($slug);

        return [
            'slug'            => $slug,
            'name'            => $provider['name'] ?? $slug,
            'category'        => $provider['category'] ?? 'unknown',
            'icon'            => $provider['icon'] ?? null,
            'doc_url'         => $provider['doc_url'] ?? null,
            'countries'       => $provider['countries'] ?? [],
            'active_environment' => ProviderConfig::activeEnvironment($slug),
            'enabled'         => ProviderConfig::isEnabled($slug),
            'status'          => $config['status']->value,
            'missing_required'=> $config['missing'],
            'reason'          => $config['reason'],
            'environments'    => $environments,
            // Rails de paiement déclarés (≠ opérations implémentées).
            'payment_rails'   => $adapter->getCapabilities()['supported_methods'],
            // Opérations RÉELLEMENT implémentées (§30).
            'operations'      => $operations,
            'operations_enabled' => in_array(true, $operations, true),
            'credential_schema'  => ProviderCredentialSchema::describe($slug),
            'documentation'   => self::documentationStatus($slug),
        ];
    }

    /**
     * Vue d'ensemble du Control Center (§25).
     *
     * Tous les compteurs sont issus de mesures réelles.
     */
    public static function overview(PDO $pdo, int $userId): array
    {
        $catalog = ProviderCatalog::all();
        $slugs   = array_keys($catalog);

        $configured = 0;
        $enabled    = 0;
        $withOps    = 0;
        foreach ($slugs as $slug) {
            if (ProviderConfig::isEnabled($slug)) {
                $enabled++;
            }
            if (ProviderRegistry::isConfigured($slug)) {
                $configured++;
            }
            if (in_array(true, self::operationMatrix($slug), true)) {
                $withOps++;
            }
        }

        // Credentials réellement enregistrées, par environnement.
        $credStmt = $pdo->prepare(
            'SELECT environment, COUNT(*) AS n
             FROM provider_credentials WHERE user_id = :uid GROUP BY environment'
        );
        $credStmt->execute(['uid' => $userId]);
        $credentials = ['sandbox' => 0, 'production' => 0];
        foreach ($credStmt->fetchAll() as $row) {
            $credentials[(string) $row['environment']] = (int) $row['n'];
        }

        return [
            'environment'  => ProviderConfig::defaultEnvironment(),
            'is_production'=> ProviderConfig::isProduction(),
            'strict_mode'  => ProviderRegistry::isStrictMode(),
            'providers'    => [
                'total'                => count($slugs),
                'enabled'              => $enabled,
                'configured'           => $configured,
                'schema_verified'      => count(array_filter($slugs, [ProviderCredentialSchema::class, 'isVerified'])),
                'with_operations'      => $withOps,
            ],
            'credentials'  => $credentials,
            'kyc'          => self::kycCounters($pdo),
            'webhooks'     => self::webhookCounters($pdo),
            'security'     => self::securityCounters($pdo),
        ];
    }

    /** Compteurs KYC/KYB réels (§17, §18). */
    public static function kycCounters(PDO $pdo): array
    {
        $out = [
            'individual' => [],
            'company'    => [],
            'total'      => 0,
        ];

        $stmt = $pdo->query(
            'SELECT subject_type, status, COUNT(*) AS n
             FROM kyc_verifications GROUP BY subject_type, status'
        );
        foreach ($stmt->fetchAll() as $row) {
            $type = (string) $row['subject_type'];
            $out[$type][(string) $row['status']] = (int) $row['n'];
            $out['total'] += (int) $row['n'];
        }

        $provider = new SumsubAdapter();
        $out['provider'] = [
            'slug'        => $provider->slug(),
            'configured'  => $provider->isConfigured(),
            'environment' => $provider->environment(),
        ];

        return $out;
    }

    /** Compteurs de webhooks réellement reçus (§19). */
    public static function webhookCounters(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT provider, environment, COUNT(*) AS n
             FROM kyc_webhook_events GROUP BY provider, environment'
        );
        $rows = $stmt->fetchAll();

        $total = 0;
        $byProvider = [];
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $total += $n;
            $byProvider[] = [
                'provider'    => $row['provider'],
                'environment' => $row['environment'],
                'processed'   => $n,
            ];
        }

        return ['processed_total' => $total, 'by_provider' => $byProvider];
    }

    /** Compteurs de sécurité réels (§26). */
    public static function securityCounters(PDO $pdo): array
    {
        $audit = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $since = $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)"
        )->fetchColumn();

        return [
            'audit_events_total' => $audit,
            'audit_events_24h'   => (int) $since,
        ];
    }

    /**
     * Registre des clés publiques (§8).
     *
     * Distingue explicitement FRONTEND SAFE et BACKEND ONLY.
     * Ne renvoie AUCUNE valeur de clé — uniquement la classification.
     */
    public static function publicKeyRegistry(PDO $pdo, int $userId): array
    {
        $rows = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $defs = ProviderCredentialSchema::for($slug);
            if ($defs === null) {
                continue; // schéma non vérifié → rien à déclarer
            }
            // Un seul déchiffrement par (provider, environnement) : on n'en
            // retient QUE la liste des champs renseignés, jamais les valeurs.
            // Les valeurs déchiffrées ne quittent pas cette portée.
            $present = [];
            foreach (['sandbox', 'production'] as $env) {
                $present[$env] = [];
                if (ProviderCredentialService::findEffectiveRow($pdo, $userId, $slug, $env) !== null) {
                    $creds = ProviderCredentialService::resolve($pdo, $userId, $slug, $env);
                    foreach ($creds as $name => $value) {
                        if (is_string($value) && $value !== '') {
                            $present[$env][$name] = true;
                        }
                    }
                    unset($creds);
                }
            }

            foreach ($defs as $def) {
                foreach (['sandbox', 'production'] as $env) {
                    $configured = isset($present[$env][$def->name]);
                    $rows[] = [
                        'provider'           => $slug,
                        'provider_name'      => $provider['name'] ?? $slug,
                        'key'                => $def->name,
                        'label'              => $def->label,
                        'environment'        => $env,
                        'sensitivity'        => $def->sensitivity,
                        'frontend_exposable' => $def->frontendExposable,
                        'exposure'           => $def->frontendExposable ? 'frontend' : 'backend',
                        'usage'              => $def->usage,
                        'configured'         => $configured,
                        'justification'      => $def->justification,
                    ];
                }
            }
        }
        return $rows;
    }
}
