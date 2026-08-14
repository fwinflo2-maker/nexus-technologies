<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ExecutionEngine — orchestration de l'exécution d'une intention financière.
 *
 * Saga déterministe, idempotente et auditée :
 *
 *   validation → réservation (hold) → règlement (capture) → écriture
 *   comptable `transactions` → transition (quote / paiement) → notification.
 *
 * Le tout est ATOMIQUE : une transaction PDO englobe l'ensemble, si bien
 * qu'un échec à n'importe quelle étape restaure l'état initial (rollback).
 *
 * Deux points d'entrée :
 *   - execute()           : exécution d'une route de quote (Send Personal).
 *   - executeTransfer()   : saga générique (réutilisée par les paiements Business).
 *
 * Invariants :
 *   - available_balance = balance - hold_balance (préservé par WalletService).
 *   - Aucun solde modifié sans écriture comptable (hold→capture → ledger).
 *   - Double exécution impossible (verrou FOR UPDATE + idempotence).
 */
final class ExecutionEngine
{
    /**
     * Exécute une route d'une quote persistée pour l'utilisateur.
     *
     * @return array<string,mixed> La transaction enregistrée.
     */
    public static function execute(
        int $userId,
        string $quoteId,
        string $routeId,
        ?string $idempotencyKey = null
    ): array {
        $pdo          = Database::getConnection();
        $useIdem      = $idempotencyKey !== null && $idempotencyKey !== '';
        $operationId  = self::uuid();

        if ($useIdem) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return (array) ($state['response_json'] ?? []);
                }
                if ($state['status'] === 'error') {
                    throw new HttpException(409, (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.'), 'IDEMPOTENCY_ERROR');
                }
                throw new HttpException(409, 'Une exécution est déjà en cours pour cette clé.', 'IDEMPOTENCY_IN_PROGRESS');
            }
        }

        $startedAt = microtime(true);
        $txId      = 0;

        $pdo->beginTransaction();
        try {
            // ── 1. Quote (verrou FOR UPDATE : bloque la double exécution) ──
            $quote = self::loadQuoteForUpdate($pdo, $userId, $quoteId);
            self::assertQuoteExecutable($quote);

            // ── 2. Re-validation de l'origine (défense en profondeur) ─────
            if (!empty($quote['origin_country'])) {
                $user        = self::loadUser($pdo, $userId);
                $originCheck = FundingSourceEngine::validateOrigin($userId, $user, (string) $quote['origin_country']);
                if (!$originCheck['authorized']) {
                    throw new HttpException(403, $originCheck['reason'] ?? 'Cette origine n\'est plus disponible pour votre compte.', 'ORIGIN_FORBIDDEN');
                }
            }

            // ── 3. Route sélectionnée ────────────────────────────────────
            $route = self::findRoute($quote, $routeId);
            if ($route === null) {
                throw new HttpException(422, 'Route introuvable dans la quote.', 'ROUTE_NOT_FOUND');
            }

            $spec  = self::buildSpecFromQuote($quote, $route, $routeId);
            $txId  = self::executeTransferInternal($userId, $spec, $pdo, $operationId, $startedAt);

            // ── 4. Transition de la quote ─────────────────────────────────
            self::markQuoteExecuted($pdo, $quoteId, $routeId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($useIdem) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable) {
                    // Meilleur effort : l'erreur métier prime.
                }
            }
            throw $e;
        }

        $tx = self::loadTransaction($pdo, $txId, $userId);
        if ($tx === null) {
            throw new RuntimeException('Transaction introuvable après exécution.');
        }
        if ($useIdem) {
            IdempotencyService::complete($idempotencyKey, $userId, $tx, $operationId);
        }
        return $tx;
    }

    /**
     * Saga générique d'exécution d'un transfert (utilisée par les paiements Business).
     *
     * @param array<string,mixed> $spec Champs :
     *   source_currency, dest_currency, amount (chaîne décimale),
     *   fee (chaîne décimale, devise source), dest_amount (float),
     *   fx_rate (?float), provider (?string), route_id (?string),
     *   destination (?string), label (string), type (string, défaut 'send'),
     *   quote_id (?string) — si présent, la quote est validée + marquée EXECUTED.
     *
     * @return array<string,mixed> La transaction enregistrée.
     */
    public static function executeTransfer(int $userId, array $spec, ?string $idempotencyKey = null): array
    {
        $pdo         = Database::getConnection();
        $useIdem     = $idempotencyKey !== null && $idempotencyKey !== '';
        $operationId = self::uuid();

        if ($useIdem) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return (array) ($state['response_json'] ?? []);
                }
                if ($state['status'] === 'error') {
                    throw new HttpException(409, (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.'), 'IDEMPOTENCY_ERROR');
                }
                throw new HttpException(409, 'Une exécution est déjà en cours pour cette clé.', 'IDEMPOTENCY_IN_PROGRESS');
            }
        }

        $startedAt = microtime(true);
        $txId      = 0;

        $pdo->beginTransaction();
        try {
            $quoteId = isset($spec['quote_id']) && $spec['quote_id'] !== '' ? (string) $spec['quote_id'] : null;
            if ($quoteId !== null) {
                $quote = self::loadQuoteForUpdate($pdo, $userId, $quoteId);
                self::assertQuoteExecutable($quote);
            }

            $txId = self::executeTransferInternal($userId, $spec, $pdo, $operationId, $startedAt);

            if ($quoteId !== null) {
                self::markQuoteExecuted($pdo, $quoteId, (string) ($spec['route_id'] ?? ''));
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($useIdem) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable) {
                }
            }
            throw $e;
        }

        $tx = self::loadTransaction($pdo, $txId, $userId);
        if ($tx === null) {
            throw new RuntimeException('Transaction introuvable après exécution.');
        }
        if ($useIdem) {
            IdempotencyService::complete($idempotencyKey, $userId, $tx, $operationId);
        }
        return $tx;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Saga interne (exécutée DANS une transaction ouverte)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $spec
     */
    private static function executeTransferInternal(int $userId, array $spec, PDO $pdo, string $operationId, float $startedAt): int
    {
        $sourceCurrency = strtoupper((string) ($spec['source_currency'] ?? ''));
        $destCurrency   = strtoupper((string) ($spec['dest_currency'] ?? ''));
        $amountSent     = (string) ($spec['amount'] ?? '0');
        $feeSource      = (string) ($spec['fee'] ?? '0.00000000');
        $destAmount     = (float) ($spec['dest_amount'] ?? 0);
        $fxRate         = isset($spec['fx_rate']) ? (float) $spec['fx_rate'] : 0.0;
        $provider       = (string) ($spec['provider'] ?? '');
        $routeId        = (string) ($spec['route_id'] ?? '');
        $destination    = (string) ($spec['destination'] ?? '');
        $label          = (string) ($spec['label'] ?? '');
        $type           = (string) ($spec['type'] ?? 'send');
        $quoteId        = (string) ($spec['quote_id'] ?? '');
        $metadata       = $spec['metadata'] ?? null;

        if ($sourceCurrency === '' || $destCurrency === '' || bccomp($amountSent, '0', 8) <= 0) {
            throw new HttpException(422, 'Montant ou devises invalides.', 'INVALID_TRANSFER_SPEC');
        }

        // ── 1. Wallet source + solde disponible ──────────────────────
        $wallet = WalletService::getWallet($userId, $sourceCurrency);
        if ($wallet === null) {
            throw new HttpException(422, sprintf('Aucun wallet %s. Fondez d\'abord votre compte.', $sourceCurrency), 'WALLET_NOT_FOUND');
        }

        $totalDebit = bcadd($amountSent, $feeSource, 8);
        $available  = (string) $wallet['available_balance'];
        if (bccomp($available, $totalDebit, 8) < 0) {
            throw new HttpException(
                422,
                sprintf('Solde disponible insuffisant : %s %s disponible, %s %s requis.', self::fmt($available), $sourceCurrency, self::fmt($totalDebit), $sourceCurrency),
                'INSUFFICIENT_FUNDS'
            );
        }

        // ── 2. Saga : hold → capture (règlement réel) ─────────────────
        $holdIdemKey    = 'op:' . $operationId . ':hold';
        $captureIdemKey = 'op:' . $operationId . ':capture';

        $hold = WalletService::createHold(
            $userId,
            (int) $wallet['id'],
            $totalDebit,
            $sourceCurrency,
            $holdIdemKey,
            $label !== '' ? $label : sprintf('Envoi %s → %s', $sourceCurrency, $destCurrency),
            ['operation_id' => $operationId, 'kind' => $type, 'metadata' => $metadata]
        );

        WalletService::captureHold((string) $hold['operation_id'], $userId, $captureIdemKey);

        // ── 3. Écriture comptable dashboard (table transactions) ─────
        $txId = self::insertTransaction(
            $pdo,
            $userId,
            $label !== '' ? $label : sprintf('Envoi %s → %s', $sourceCurrency, $destCurrency),
            $provider,
            $routeId,
            $quoteId,
            $destination,
            $amountSent,
            $sourceCurrency,
            $destAmount,
            $destCurrency,
            $feeSource,
            $fxRate,
            $type,
            $operationId,
            $startedAt
        );

        // ── 4. Notification ───────────────────────────────────────────
        self::notify($pdo, $userId, $sourceCurrency, $destCurrency, $destAmount, $provider !== '' ? $provider : null);

        return $txId;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers — lecture / validation
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function loadQuoteForUpdate(PDO $pdo, int $userId, string $quoteId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = :id AND user_id = :uid FOR UPDATE');
        $stmt->execute(['id' => $quoteId, 'uid' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'Quote introuvable.', 'QUOTE_NOT_FOUND');
        }
        return $row;
    }

    private static function assertQuoteExecutable(array $quote): void
    {
        $status = (string) $quote['status'];
        if ($status === 'EXECUTED') {
            throw new HttpException(409, 'Cette quote a déjà été exécutée.', 'QUOTE_ALREADY_EXECUTED');
        }
        if ($status !== 'QUOTED' && $status !== 'SELECTED') {
            throw new HttpException(409, sprintf('Quote non exécutable (statut %s).', $status), 'QUOTE_NOT_EXECUTABLE');
        }
        $expiresAt = strtotime((string) $quote['expires_at'] . ' UTC');
        if ($expiresAt === false || $expiresAt <= time()) {
            throw new HttpException(410, 'La quote a expiré. Demandez de nouvelles routes.', 'QUOTE_EXPIRED');
        }
    }

    /** @return array<string,mixed>|null */
    private static function findRoute(array $quote, string $routeId): ?array
    {
        $routes = json_decode((string) $quote['routes_json'], true);
        if (!is_array($routes)) {
            return null;
        }
        foreach ($routes as $route) {
            if (($route['id'] ?? null) === $routeId) {
                return $route;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function loadUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'Utilisateur introuvable.', 'USER_NOT_FOUND');
        }
        return $row;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers — construction du spec / écritures
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    private static function buildSpecFromQuote(array $quote, array $route, string $routeId): array
    {
        $sourceCurrency = strtoupper((string) $quote['source_currency']);
        $destCurrency   = strtoupper((string) $quote['dest_currency']);
        $amountSent     = (string) $quote['amount_sent'];

        $feeEur  = (float) ($route['feesNum'] ?? 0);
        $rateRef = Currency::rateToRef($sourceCurrency);
        $feeSource = $rateRef > 0.0 ? bcdiv((string) $feeEur, (string) $rateRef, 8) : '0.00000000';

        return [
            'source_currency' => $sourceCurrency,
            'dest_currency'   => $destCurrency,
            'amount'          => $amountSent,
            'fee'             => $feeSource,
            'dest_amount'     => (float) ($route['receivedNum'] ?? 0),
            'fx_rate'         => (float) ($route['rate'] ?? 0),
            'provider'        => (string) ($route['provider'] ?? ''),
            'route_id'        => $routeId,
            'destination'     => self::destinationLabel($quote),
            'label'           => sprintf('Envoi %s → %s', $sourceCurrency, $destCurrency),
            'type'            => 'send',
            'quote_id'        => (string) $quote['id'],
        ];
    }

    /**
     * @param array<string,mixed> $quote
     */
    private static function destinationLabel(array $quote): string
    {
        $methodLabels = [
            'mobile_money' => 'Mobile Money',
            'bank'         => 'Virement bancaire',
            'crypto'       => 'Crypto',
            'cash_pickup'  => 'Retrait en espèces',
        ];
        $method  = strtolower((string) $quote['receiving_method']);
        $country = strtoupper((string) $quote['dest_country']);
        return substr($country . ' · ' . ($methodLabels[$method] ?? $method), 0, 190);
    }

    private static function insertTransaction(
        PDO $pdo,
        int $userId,
        string $label,
        string $provider,
        string $routeId,
        string $quoteId,
        string $destination,
        string $amountSent,
        string $sourceCurrency,
        float $received,
        string $destCurrency,
        string $feeSource,
        float $fxRate,
        string $type,
        string $operationId,
        float $startedAt
    ): int {
        $rateRef   = Currency::rateToRef($sourceCurrency);
        $rateXaf   = Currency::rateToXaf($sourceCurrency);
        $amountRef = round((float) $amountSent * $rateRef, 2);
        $amountXaf = round((float) $amountSent * $rateXaf, 2);
        $fee2      = round((float) $feeSource, 2);
        $execSec   = max(1, (int) round(microtime(true) - $startedAt));

        $description = substr(sprintf('Route %s · %s', $routeId !== '' ? $routeId : '-', $provider !== '' ? $provider : 'Nexus'), 0, 255);

        $stmt = $pdo->prepare(
            'INSERT INTO transactions
                (quote_id, route_id, user_id, type, direction, label, description,
                 amount, currency, amount_ref, ref_currency, amount_xaf,
                 dest_amount, dest_currency, fx_rate, fee, fee_currency,
                 status, provider, destination, execution_time_seconds)
             VALUES
                (:qid, :rid, :uid, :type, :dir, :label, :desc,
                 :amount, :cur, :aref, :refcur, :axaf,
                 :damount, :dcur, :fxr, :fee, :feecur,
                 :status, :prov, :dest, :execsec)'
        );

        $stmt->execute([
            'qid'     => $quoteId !== '' ? $quoteId : null,
            'rid'     => $routeId !== '' ? $routeId : null,
            'uid'     => $userId,
            'type'    => $type,
            'dir'     => 'out',
            'label'   => substr($label, 0, 190),
            'desc'    => $description,
            'amount'  => round((float) $amountSent, 2),
            'cur'     => $sourceCurrency,
            'aref'    => $amountRef,
            'refcur'  => Currency::REF,
            'axaf'    => $amountXaf,
            'damount' => $received > 0 ? round($received, 2) : null,
            'dcur'    => $destCurrency !== '' ? $destCurrency : null,
            'fxr'     => $fxRate > 0 ? number_format($fxRate, 8, '.', '') : null,
            'fee'     => $fee2,
            'feecur'  => $sourceCurrency,
            'status'  => 'completed',
            'prov'    => $provider !== '' ? substr($provider, 0, 50) : null,
            'dest'    => $destination !== '' ? substr($destination, 0, 190) : null,
            'execsec' => $execSec,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function markQuoteExecuted(PDO $pdo, string $quoteId, string $routeId): void
    {
        $stmt = $pdo->prepare("UPDATE quotes SET selected_route_id = :rid, status = 'EXECUTED' WHERE id = :id");
        $stmt->execute(['rid' => $routeId, 'id' => $quoteId]);
    }

    private static function notify(PDO $pdo, int $userId, string $sourceCurrency, string $destCurrency, float $received, ?string $provider): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message)
             VALUES (:uid, :type, :title, :msg)'
        );
        $stmt->execute([
            'uid'   => $userId,
            'type'  => 'transfert',
            'title' => 'Transfert exécuté',
            'msg'   => sprintf('Envoi %s → %s réglé via %s. Montant reçu : %s %s.', $sourceCurrency, $destCurrency, $provider ?? 'Nexus', self::fmt((string) $received), $destCurrency),
        ]);
    }

    /** @return array<string,mixed>|null */
    private static function loadTransaction(PDO $pdo, int $txId, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $txId, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::formatTransaction($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function formatTransaction(array $row): array
    {
        $intFields   = ['id', 'execution_time_seconds'];
        $floatFields = ['amount', 'amount_ref', 'amount_xaf', 'fee', 'dest_amount', 'fx_rate'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        foreach ($floatFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (float) $row[$field];
            }
        }
        return $row;
    }

    private static function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, ',', ' ');
    }

    private static function uuid(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffffffffffff)
        );
    }
}
