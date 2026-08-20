<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Services\ProviderCatalog;

/**
 * WebhookRegistry — déclarations webhook par provider (§7, §8).
 *
 * Chaque provider déclare SON mécanisme :
 *   - webhook_path          : endpoint dédié ;
 *   - signature_type        : rfc9421 | hmac_sha256_stripe | hmac_sha256_digest |
 *                             hmac_nexus (générique X-Nexus-Signature) ;
 *   - verification_key_type : public_key | webhook_secret ;
 *   - timestamp_validation  : anti-replay (tolerance) ou non ;
 *   - event_id_field        : identité de l'événement ;
 *   - idempotency_field     : mécanisme d'idempotence (table + contrainte).
 *
 * Un webhook N'EST JAMAIS vérifié par une logique générique unique : le
 * contrôleur route vers la vérification déclarée par le provider, et rejette
 * un webhook dont la signature ne correspond pas à SA déclaration.
 */
final class WebhookRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const DECLARED = [
        'pawapay' => [
            'webhook_path'          => '/api/providers/webhook/pawapay',
            'signature_type'        => 'rfc9421',
            'verification_key_type' => 'public_key',
            'timestamp_validation'  => ['enabled' => true, 'note' => 'Signature-Input date/@created, fenêtre de tolérance'],
            'event_id_field'        => 'payoutId:status (chaque transition est un événement)',
            'idempotency_field'     => 'provider_webhook_events — UNIQUE(provider, environment, event_id)',
            'implementation'        => 'IMPLEMENTED',
        ],
        'stripe' => [
            'webhook_path'          => '/api/providers/webhook/stripe',
            'signature_type'        => 'hmac_sha256_stripe_signature',
            'verification_key_type' => 'webhook_secret',
            'timestamp_validation'  => ['enabled' => true, 'note' => 'En-tête Stripe-Signature t=..., tolérance 300 s'],
            'event_id_field'        => 'id (événement Stripe)',
            'idempotency_field'     => 'provider_webhook_events — UNIQUE(provider, environment, event_id)',
            'implementation'        => 'IMPLEMENTED',
        ],
        'sumsub' => [
            'webhook_path'          => '/api/kyc/webhook',
            'signature_type'        => 'hmac_sha256_digest',
            'verification_key_type' => 'webhook_secret',
            'timestamp_validation'  => ['enabled' => false, 'note' => 'Digest HMAC seul (X-Payload-Digest) ; rejeu géré par idempotence'],
            'event_id_field'        => 'applicantId:reviewResult.type',
            'idempotency_field'     => 'kyc_webhook_events — UNIQUE(provider, environment, event_id)',
            'implementation'        => 'IMPLEMENTED',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Déclaration webhook d'un provider (défaut honnête pour les autres).
     *
     * @return array<string, mixed>
     */
    public static function for(string $slug): array
    {
        if (isset(self::DECLARED[$slug])) {
            return self::DECLARED[$slug];
        }

        return [
            'webhook_path'          => '/api/providers/webhook/' . $slug,
            'signature_type'        => 'hmac_nexus',
            'verification_key_type' => 'webhook_secret',
            'timestamp_validation'  => ['enabled' => false, 'note' => 'Pipeline HMAC générique X-Nexus-Signature'],
            'event_id_field'        => 'event_id | id (payload)',
            'idempotency_field'     => 'provider_webhook_events — UNIQUE(provider, environment, event_id)',
            'implementation'        => 'CONFIG_REQUIRED',
        ];
    }

    /**
     * Registre complet.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        $out = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $out[$slug] = self::for($slug);
        }
        return $out;
    }
}
