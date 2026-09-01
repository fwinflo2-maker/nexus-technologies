<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Providers\CashrampAdapter;
use Nexus\Providers\ProviderRegistry;

/**
 * Provisionne un customer Cashramp via ProviderCustomerService (idempotent).
 */
final class CashrampCustomerProvisioner
{
    private function __construct()
    {
    }

    /**
     * @param array{email:string,firstName:string,lastName:string,countryId:string} $profile
     * @return array<string,mixed>
     */
    public static function provision(
        int $userId,
        string $environment,
        array $profile,
    ): array {
        $adapter = ProviderRegistry::get('cashramp');
        if (!$adapter instanceof CashrampAdapter) {
            throw new HttpException(500, 'Adaptateur Cashramp indisponible.', 'PROVIDER_ERROR');
        }

        return ProviderCustomerService::getOrCreateCustomer(
            $userId,
            'cashramp',
            $environment,
            static function () use ($adapter, $environment, $profile): array {
                $created = $adapter->createCustomer($profile, $environment);

                return [
                    'provider_customer_id' => (string) ($created['id'] ?? ''),
                    'status'               => ProviderCustomerService::STATUS_ACTIVE,
                    'metadata'             => [
                        'email'     => (string) ($created['email'] ?? $profile['email']),
                        'first_name'=> (string) ($created['firstName'] ?? $profile['firstName']),
                        'last_name' => (string) ($created['lastName'] ?? $profile['lastName']),
                    ],
                ];
            }
        );
    }
}
