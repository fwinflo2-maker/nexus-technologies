<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use PDO;
use Throwable;

final class FundingIntentService
{
    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function create(
        int $userId,
        int $walletId,
        string $provider,
        string $providerReference,
        string $currency,
        string $expectedAmount,
        ExecutionContext $context
    ): array {
        if ($provider === '' || $providerReference === '' || !preg_match('/^[A-Z]{3,5}$/', $currency)
            || !preg_match('/^\d+(?:\.\d+)?$/', $expectedAmount)
            || bccomp($expectedAmount, '0', 8) <= 0) {
            throw new HttpException(422, 'Intent de dépôt invalide.', 'INVALID_FUNDING_INTENT');
        }
        $pdo = Database::getConnection();
        $wallet = $pdo->prepare('SELECT id, user_id, currency FROM wallets WHERE id = :id LIMIT 1');
        $wallet->execute(['id' => $walletId]);
        $row = $wallet->fetch();
        if ($row === false || (int) $row['user_id'] !== $userId || strtoupper((string) $row['currency']) !== $currency) {
            throw new HttpException(404, 'Wallet de dépôt introuvable.', 'WALLET_NOT_FOUND');
        }

        $id = self::uuid();
        try {
            $pdo->prepare(
                'INSERT INTO funding_intents
                    (id, user_id, wallet_id, provider, provider_reference, environment,
                     currency, expected_amount, status, expires_at)
                 VALUES (:id, :user, :wallet, :provider, :reference, :environment,
                         :currency, :amount, \'created\', DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
            )->execute([
                'id' => $id,
                'user' => $userId,
                'wallet' => $walletId,
                'provider' => $provider,
                'reference' => $providerReference,
                'environment' => $context->environmentValue(),
                'currency' => $currency,
                'amount' => bcadd($expectedAmount, '0', 8),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'Cette référence provider est déjà liée.', 'FUNDING_REFERENCE_EXISTS');
            }
            throw $e;
        }

        return [
            'id' => $id,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'currency' => $currency,
            'expected_amount' => bcadd($expectedAmount, '0', 8),
            'status' => 'created',
            'environment' => $context->environmentValue(),
        ];
    }

    /**
     * Résout exclusivement l'identité pré-liée à la référence provider.
     * Les champs user_id/wallet_id éventuellement présents dans le webhook
     * sont ignorés par construction.
     *
     * @return array{operation_id:string,status:string,balance:string,user_id:int}
     */
    public static function confirm(
        string $provider,
        string $providerReference,
        string $currency,
        string $amount,
        string $environment,
        string $providerStatus = 'COMPLETED'
    ): array {
        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM funding_intents
                 WHERE provider = :provider AND provider_reference = :reference
                   AND environment = :environment
                 FOR UPDATE'
            );
            $stmt->execute([
                'provider' => $provider,
                'reference' => $providerReference,
                'environment' => $environment,
            ]);
            $intent = $stmt->fetch();
            if ($intent === false) {
                throw new HttpException(409, 'Référence de dépôt non attribuée.', 'UNKNOWN_FUNDING_INTENT');
            }
            if ($intent['status'] === 'completed') {
                $opId = (string) ($intent['funding_operation_id'] ?? '');
                return self::existingResult($pdo, $intent, $opId, $ownsTransaction);
            }
            if (strtotime((string) $intent['expires_at'] . ' UTC') <= time()) {
                $pdo->prepare("UPDATE funding_intents SET status = 'expired' WHERE id = :id")
                    ->execute(['id' => $intent['id']]);
                throw new HttpException(410, 'Intent de dépôt expiré.', 'FUNDING_INTENT_EXPIRED');
            }
            if (strtoupper((string) $intent['currency']) !== strtoupper($currency)
                || bccomp((string) $intent['expected_amount'], $amount, 8) !== 0) {
                throw new HttpException(409, 'Montant ou devise différents de l’intent.', 'FUNDING_INTENT_MISMATCH');
            }

            $context = ExecutionContext::explicit(
                (int) $intent['user_id'],
                ExecutionEnvironment::fromString($environment)
            );
            $result = FundingService::recordDeposit(
                (int) $intent['user_id'],
                (int) $intent['wallet_id'],
                (string) $intent['currency'],
                (string) $intent['expected_amount'],
                $provider,
                'deposit:' . $provider . ':' . $providerReference,
                $providerReference,
                ['source' => 'provider_webhook', 'funding_intent_id' => (string) $intent['id']],
                $context
            );

            $terminal = in_array(strtoupper($providerStatus), ['COMPLETED', 'SETTLED', 'SUCCEEDED'], true);
            if ($terminal) {
                FundingService::settleDeposit($result['operation_id'], (int) $intent['user_id'], $context);
            }
            $pdo->prepare(
                'UPDATE funding_intents
                 SET status = :status, funding_operation_id = :operation
                 WHERE id = :id'
            )->execute([
                'status' => $terminal ? 'completed' : 'processing',
                'operation' => $result['operation_id'],
                'id' => $intent['id'],
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result + ['user_id' => (int) $intent['user_id']];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{operation_id:string,status:string,balance:string,user_id:int} */
    private static function existingResult(PDO $pdo, array $intent, string $opId, bool $ownsTransaction): array
    {
        $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE id = :id');
        $stmt->execute(['id' => $intent['wallet_id']]);
        $balance = (string) ($stmt->fetchColumn() ?: '0.00000000');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'operation_id' => $opId,
            'status' => 'completed',
            'balance' => $balance,
            'user_id' => (int) $intent['user_id'],
        ];
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
