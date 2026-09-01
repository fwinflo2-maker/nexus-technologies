<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Services\ProviderCatalog;

/**
 * ProviderCapabilityMatrix — vérité sur les capacités RÉELLES de chaque
 * provider (§1, §16, §21).
 *
 * Un provider déclaré dans le catalogue n'est pas un provider intégré. Pour
 * chaque capacité, la matrice distingue :
 *
 *   IMPLEMENTED        — code réel, testé, derrière l'adaptateur ;
 *   NOT_IMPLEMENTED    — non câblé (aucun appel réel, aucune simulation) ;
 *   NOT_SUPPORTED      — la documentation officielle du provider ne l'offre
 *                        pas (ex. pawaPay : pas d'annulation de payout) ;
 *   CONFIG_REQUIRED    — le code existe mais exige une configuration
 *                        (credentials, clé de webhook, compte provider).
 *
 * RÈGLE : on n'affiche JAMAIS IMPLEMENTED pour un adaptateur vide ou générique.
 * La matrice est la source de vérité affichée par le Control Center et
 * consommée par le Capability Engine pour ne promettre que du réel.
 */
final class ProviderCapabilityMatrix
{
    public const IMPLEMENTED     = 'IMPLEMENTED';
    public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    public const NOT_SUPPORTED   = 'NOT_SUPPORTED';
    public const CONFIG_REQUIRED = 'CONFIG_REQUIRED';

    /** États corridor (Milestone 2) — aucune donnée inventée par défaut. */
    public const STATE_UNKNOWN           = 'UNKNOWN';
    public const STATE_AVAILABLE         = 'AVAILABLE';
    public const STATE_UNAVAILABLE       = 'UNAVAILABLE';
    public const STATE_DISABLED          = 'DISABLED';
    public const STATE_TESTED            = 'TESTED';
    public const STATE_PRODUCTION_READY  = 'PRODUCTION_READY';

    /** Dimensions d'une capacité corridor. */
    public const ROUTE_DIMENSIONS = [
        'provider',
        'operation',
        'source_currency',
        'destination_currency',
        'source_country',
        'destination_country',
        'channel',
        'status',
    ];
    public const CAPABILITIES = [
        'test_connection',
        'balance',
        'quote',
        'payout',
        'refund',
        'webhook',
        'reconciliation',
        'account',
    ];

    /**
     * Déclarations par slug. Les providers ABSENTS reçoivent le défaut
     * honnête (NOT_IMPLEMENTED partout, webhook CONFIG_REQUIRED via le
     * pipeline HMAC générique).
     *
     * @var array<string, array<string, string>>
     */
    private const DECLARED = [
        'pawapay' => [
            // Merchant API v2 : payout + polling réellement câblés. Sans
            // token, le runtime retourne CREDENTIALS_NOT_CONFIGURED.
            'test_connection' => self::IMPLEMENTED,
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::IMPLEMENTED,
            'refund'          => self::NOT_SUPPORTED,   // doc : payout accepté terminal
            'webhook'         => self::CONFIG_REQUIRED, // code RFC-9421 réel ; token + signed callbacks requis
            'reconciliation'  => self::IMPLEMENTED,     // GET /v2/payouts/{payoutId}
            'account'         => self::CONFIG_REQUIRED,
        ],
        'stripe' => [
            'test_connection' => self::IMPLEMENTED,     // GET /v1/balance réel
            'balance'         => self::NOT_IMPLEMENTED, // pas de getBalance() exposé
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::NOT_IMPLEMENTED,
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::CONFIG_REQUIRED, // Stripe-Signature natif ; whsec requis
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
        'stripe_issuing' => [
            // GET /v1/issuing/cardholders + POST cardholders/cards (virtual).
            'test_connection' => self::IMPLEMENTED,
            'balance'         => self::NOT_SUPPORTED,
            'quote'           => self::NOT_SUPPORTED,
            'payout'          => self::NOT_SUPPORTED,
            'refund'          => self::NOT_SUPPORTED,
            'webhook'         => self::CONFIG_REQUIRED, // événements Issuing ; whsec Stripe requis
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::CONFIG_REQUIRED, // cardholder Issuing
        ],
        'maplerad' => [
            // GET /v1/wallets + POST /v1/customers + POST /v1/issuing.
            'test_connection' => self::IMPLEMENTED,
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_SUPPORTED,
            'payout'          => self::NOT_SUPPORTED,
            'refund'          => self::NOT_SUPPORTED,
            'webhook'         => self::CONFIG_REQUIRED,
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::CONFIG_REQUIRED,
        ],
        'sumsub' => [
            'test_connection' => self::IMPLEMENTED, // sonde HMAC réelle via ProviderAuthProbe / SumsubAdapter
            'balance'         => self::NOT_SUPPORTED,
            'quote'           => self::NOT_SUPPORTED,
            'payout'          => self::NOT_SUPPORTED,
            'refund'          => self::NOT_SUPPORTED,
            'webhook'         => self::IMPLEMENTED,     // /api/kyc/webhook + digest HMAC
            'reconciliation'  => self::NOT_SUPPORTED,
            'account'         => self::NOT_SUPPORTED,
        ],
        'western_union' => [
            // Mass Payments : Ping mTLS réel ; quote/payout E2E partenaire requis.
            'test_connection' => self::IMPLEMENTED,     // GET /Ping (mTLS)
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_IMPLEMENTED, // getQuote() existe mais hors CapabilityEngine E2E
            'payout'          => self::NOT_IMPLEMENTED,
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::CONFIG_REQUIRED,
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
        'moneygram' => [
            // OAuth client credentials réel ; disbursement/transfer payout NOT_IMPLEMENTED
            // jusqu'à E2E partenaire (agentPartnerId + modules).
            'test_connection' => self::IMPLEMENTED,     // GET /oauth/accesstoken
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::NOT_IMPLEMENTED,
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::CONFIG_REQUIRED,
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
        'cashramp' => [
            'test_connection' => self::NOT_IMPLEMENTED,
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::NOT_IMPLEMENTED,
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::NOT_IMPLEMENTED,
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
    ];

    /**
     * Capacités corridor explicites (vide au Milestone 2 — modèle prêt).
     *
     * @var list<array<string, string>>
     */
    private const ROUTE_DECLARED = [];

    private function __construct()
    {
    }

    /**
     * Capacités réelles d'un provider (défaut honnête si non déclaré).
     *
     * @return array<string, string>
     */
    public static function for(string $slug): array
    {
        $declared = self::DECLARED[$slug] ?? [];

        $row = [];
        foreach (self::CAPABILITIES as $capability) {
            if (isset($declared[$capability])) {
                $row[$capability] = $declared[$capability];
                continue;
            }
            // Sonde HTTP réelle via ProviderAuthProbe → test_connection IMPLEMENTED.
            if ($capability === 'test_connection' && ProviderAuthProbe::supports($slug)) {
                $row[$capability] = self::IMPLEMENTED;
                continue;
            }
            // Défaut honnête : jamais IMPLEMENTED pour un adaptateur générique.
            $row[$capability] = $capability === 'webhook'
                ? self::CONFIG_REQUIRED // pipeline HMAC X-Nexus-Signature générique
                : self::NOT_IMPLEMENTED;
        }
        return $row;
    }

    /**
     * Capacités réellement opérationnelles (IMPLEMENTED).
     *
     * @return list<string>
     */
    public static function implemented(string $slug): array
    {
        $out = [];
        foreach (self::for($slug) as $capability => $status) {
            if ($status === self::IMPLEMENTED) {
                $out[] = $capability;
            }
        }
        return $out;
    }

    /** Intégration globale : IMPLEMENTED dès qu'une capacité est réelle. */
    public static function integrationStatus(string $slug): string
    {
        return self::implemented($slug) !== [] ? self::IMPLEMENTED : self::NOT_IMPLEMENTED;
    }

    /**
     * Matrice complète du catalogue.
     *
     * @return list<array<string, string>>
     */
    public static function matrix(): array
    {
        $rows = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $rows[] = [
                'provider'   => $slug,
                'category'   => (string) $provider['category'],
                'capability' => self::for($slug),
                'integration'=> self::integrationStatus($slug),
            ];
        }
        return $rows;
    }

    /** @return list<string> */
    public static function routeDimensions(): array
    {
        return self::ROUTE_DIMENSIONS;
    }

    /**
     * Statut corridor pour une intention (UNKNOWN tant qu'aucune donnée réelle).
     *
     * @param array<string, mixed> $intent
     */
    public static function routeStatus(string $slug, array $intent): string
    {
        $operation = (string) ($intent['operation'] ?? 'payout');
        $key = self::routeKey(
            $slug,
            $operation,
            strtoupper((string) ($intent['sourceCurrency'] ?? '')),
            strtoupper((string) ($intent['destCurrency'] ?? '')),
            strtoupper((string) ($intent['sourceCountry'] ?? '')),
            strtoupper((string) ($intent['destCountry'] ?? '')),
            (string) ($intent['receivingMethod'] ?? ''),
        );

        foreach (self::ROUTE_DECLARED as $row) {
            if (($row['key'] ?? '') === $key) {
                return (string) ($row['status'] ?? self::STATE_UNKNOWN);
            }
        }

        return self::STATE_UNKNOWN;
    }

    public static function routeKey(
        string $provider,
        string $operation,
        string $sourceCurrency,
        string $destinationCurrency,
        string $sourceCountry,
        string $destinationCountry,
        string $channel,
    ): string {
        return implode('|', [
            strtolower($provider),
            strtolower($operation),
            $sourceCurrency,
            $destinationCurrency,
            $sourceCountry,
            $destinationCountry,
            strtolower($channel),
        ]);
    }
}
