<?php

declare(strict_types=1);

namespace Nexus\Providers\Cashramp;

/**
 * Mappe les statuts Cashramp documentés vers le modèle Nexus.
 *
 * @see https://docs.cashramp.co/cashramp/introduction/webhooks.md
 */
final class CashrampStatusMapper
{
    /** @var array<string, string> */
    private const PAYMENT_REQUEST = [
        'created'   => 'pending',
        'picked_up' => 'processing',
        'pending'   => 'pending',
        'paid'      => 'processing',
        'accepted'  => 'processing',
        'completed' => 'completed',
        'canceled'  => 'cancelled',
        'cancelled' => 'cancelled',
        'failed'    => 'failed',
    ];

    /** @var array<string, string> */
    private const ONCHAIN = [
        'pending'    => 'pending',
        'processing' => 'processing',
        'completed'  => 'completed',
        'failed'     => 'failed',
        'canceled'   => 'cancelled',
        'cancelled'  => 'cancelled',
    ];

    public static function mapPaymentRequest(string $providerStatus): string
    {
        $key = strtolower(trim($providerStatus));
        return self::PAYMENT_REQUEST[$key] ?? 'pending';
    }

    public static function mapOnchain(string $providerStatus): string
    {
        $key = strtolower(trim($providerStatus));
        return self::ONCHAIN[$key] ?? 'pending';
    }

    public static function mapWebhookEventType(string $eventType): ?string
    {
        return match ($eventType) {
            'payment_request.updated' => 'payment',
            'onchain_tx.updated'        => 'onchain_withdrawal',
            'onchain_payment.received'  => 'onchain_deposit',
            'chargeback.initiated'      => 'chargeback',
            default                     => null,
        };
    }
}
