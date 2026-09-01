<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Providers\CashrampAdapter;
use Nexus\Providers\ProviderRegistry;
use PDO;

/**
 * Comptes fiat Cashramp (virtual bank accounts USD — docs.cashramp.co).
 */
final class CashrampAccountService
{
    private function __construct()
    {
    }

    /**
     * @return array<string,mixed>
     */
    public static function requestUsdVirtualAccount(int $userId, string $environment): array
    {
        $customer = ProviderCustomerService::getCustomer($userId, 'cashramp', $environment);
        if ($customer === null) {
            throw new HttpException(409, 'Customer Cashramp requis avant la création de compte.', 'PROVIDER_CUSTOMER_REQUIRED');
        }

        $adapter = ProviderRegistry::get('cashramp');
        if (!$adapter instanceof CashrampAdapter) {
            throw new HttpException(500, 'Adaptateur Cashramp indisponible.', 'PROVIDER_ERROR');
        }

        $providerAccount = $adapter->requestVirtualBankAccount(
            (string) $customer['provider_customer_id'],
            $environment
        );

        $pdo = Database::getConnection();
        self::persistProviderAccount($pdo, $userId, $environment, $providerAccount);

        return $providerAccount;
    }

    /**
     * @param array<string,mixed> $providerAccount
     */
    private static function persistProviderAccount(
        PDO $pdo,
        int $userId,
        string $environment,
        array $providerAccount,
    ): void {
        $externalId = (string) ($providerAccount['id'] ?? '');
        if ($externalId === '') {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO provider_user_accounts
                (user_id, provider_slug, environment, external_account_id, currency, account_type, status, metadata_json)
             VALUES (:uid, :slug, :env, :ext, :cur, :type, :status, :meta)
             ON DUPLICATE KEY UPDATE status = VALUES(status), metadata_json = VALUES(metadata_json), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'uid'    => $userId,
            'slug'   => 'cashramp',
            'env'    => $environment,
            'ext'    => $externalId,
            'cur'    => 'USD',
            'type'   => 'virtual_bank',
            'status' => (string) ($providerAccount['status'] ?? 'requested'),
            'meta'   => json_encode($providerAccount, JSON_THROW_ON_ERROR),
        ]);
    }
}
