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

    /** Capacités suivies par la matrice (ordre d'affichage). */
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
            'test_connection' => self::IMPLEMENTED,     // GET /balances réel (PawaPayAdapter)
            'balance'         => self::IMPLEMENTED,     // getBalance() réel
            'quote'           => self::IMPLEMENTED,     // getQuote() réel
            'payout'          => self::IMPLEMENTED,     // createPayment() réel (ExecutionEngine)
            'refund'          => self::NOT_SUPPORTED,   // doc : un payout accepté est terminal (cancelPayment lève)
            'webhook'         => self::IMPLEMENTED,     // callbacks RFC-9421 + clé publique (verifyCallback)
            'reconciliation'  => self::IMPLEMENTED,     // ProviderReconciliationService (pollable pawapay)
            'account'         => self::CONFIG_REQUIRED, // provider_accounts (slug/env/devise)
        ],
        'stripe' => [
            'test_connection' => self::IMPLEMENTED,     // GET /v1/balance réel
            'balance'         => self::IMPLEMENTED,     // getBalance() réel
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::NOT_IMPLEMENTED, // Stripe Payouts non câblé
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::IMPLEMENTED,     // Stripe-Signature HMAC-SHA256 + tolérance timestamp
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
        'sumsub' => [
            'test_connection' => self::IMPLEMENTED,     // GET /resources/applicants/-;status signé
            'balance'         => self::NOT_SUPPORTED,   // provider KYC : pas de balance
            'quote'           => self::NOT_SUPPORTED,
            'payout'          => self::NOT_SUPPORTED,
            'refund'          => self::NOT_SUPPORTED,
            'webhook'         => self::IMPLEMENTED,     // X-Payload-Digest (HMAC) + idempotence
            'reconciliation'  => self::NOT_SUPPORTED,
            'account'         => self::NOT_SUPPORTED,
        ],
        'western_union' => [
            'test_connection' => self::NOT_IMPLEMENTED,
            'balance'         => self::NOT_IMPLEMENTED,
            'quote'           => self::NOT_IMPLEMENTED,
            'payout'          => self::NOT_IMPLEMENTED,
            'refund'          => self::NOT_IMPLEMENTED,
            'webhook'         => self::CONFIG_REQUIRED,
            'reconciliation'  => self::NOT_IMPLEMENTED,
            'account'         => self::NOT_IMPLEMENTED,
        ],
    ];

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
}
