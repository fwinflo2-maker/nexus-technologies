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
 *   validation quote → re-validation origine → réservation (hold)
 *   → règlement (capture) → écriture comptable `transactions`
 *   → transition quote (EXECUTED) → notification.
 *
 * Le tout est ATOMIQUE : une transaction PDO englobe l'ensemble, si bien
 * qu'un échec à n'importe quelle étape restaure l'état initial (rollback).
 *
 * Invariants respectés :
 *   - Le Ledger est la source de vérité : WalletService::createHold/captureHold
 *     écrivent `wallet_operations` + `ledger_entries` ; aucun solde n'est
 *     modifié sans écriture comptable.
 *   - available_balance = balance - hold_balance est préservé par WalletService.
 *   - L'origine des fonds est re-vérifiée côté serveur (jamais de confiance
 *     au frontend).
 *   - La double exécution d'une quote est impossible (verrou FOR UPDATE +
 *     transition de statut + clé d'idempotence).
 */
final class ExecutionEngine
{
    /**
     * Exécute une route d'une quote persistée pour l'utilisateur.
     *
     * @param int         $userId         Identifiant de l'utilisateur authentifié.
     * @param string      $quoteId        Identifiant de la quote persistée.
     * @param string      $routeId        Identifiant de la route (A, B, C…).
     * @param string|null $idempotencyKey Clé d'idempotence (rejeu sûr).
     *
     * @return array<string,mixed> La transaction enregistrée.
     *
     * @throws HttpException Pour les erreurs métier attendues (4xx).
     */
    public static function execute(
        int $userId,
        string $quoteId,
        string $routeId,
        ?string $idempotencyKey = null
    ): array {
        $pdo = Database::getConnection();

        // ── 0. Idempotence : réservation de la clé avant tout travail ──────
        $useIdempotency = $idempotencyKey !== null && $idempotencyKey !== '';
        $operationId    = self::uuid();

        if ($useIdempotency) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return (array) ($state['response_json'] ?? []);
                }
                if ($state['status'] === 'error') {
                    throw new HttpException(
                        409,
                        (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.'),
                        'IDEMPOTENCY_ERROR'
                    );
                }
                throw new HttpException(409, 'Une exécution est déjà en cours pour cette clé.', 'IDEMPOTENCY_IN_PROGRESS');
            }
        }

        $startedAt = microtime(true);
        $txId      = 0;

        $pdo->beginTransaction();
        try {
            // ── 1. Quote (verrou FOR UPDATE : verrouille la double exécution) ──
            $quote = self::loadQuoteForUpdate($pdo, $userId, $quoteId);
            self::assertQuoteExecutable($quote);

            // ── 2. Re-validation de l'origine (défense en profondeur) ─────
            if (!empty($quote['origin_country'])) {
                $user        = self::loadUser($pdo, $userId);
                $originCheck = FundingSourceEngine::validateOrigin($userId, $user, (string) $quote['origin_country']);
                if (!$originCheck['authorized']) {
                    throw new HttpException(
                        403,
                        $originCheck['reason'] ?? 'Cette origine n\'est plus disponible pour votre compte.',
                        'ORIGIN_FORBIDDEN'
                    );
                }
            }

            // ── 3. Route sélectionnée ────────────────────────────────────
            $route = self::findRoute($quote, $routeId);
            if ($route === null) {
                throw new HttpException(422, 'Route introuvable dans la quote.', 'ROUTE_NOT_FOUND');
            }

            // ── 4. Montants & frais ──────────────────────────────────────
            $sourceCurrency = strtoupper((string) $quote['source_currency']);
            $destCurrency   = strtoupper((string) $quote['dest_currency']);
            $amountSent     = (string) $quote['amount_sent'];
            $feeEur         = (float) ($route['feesNum'] ?? 0);
            $received       = (float) ($route['receivedNum'] ?? 0);
            $fxRate         = (float) ($route['rate'] ?? 0);

            // Frais convertis dans la devise source (débit du wallet source).
            $rateRef    = Currency::rateToRef($sourceCurrency);
            $feeSource  = $rateRef > 0.0 ? bcdiv((string) $feeEur, (string) $rateRef, 8) : '0.00000000';
            $totalDebit = bcadd($amountSent, $feeSource, 8);

            // ── 5. Wallet source + solde disponible ──────────────────────
            $wallet = WalletService::getWallet($userId, $sourceCurrency);
            if ($wallet === null) {
                throw new HttpException(
                    422,
                    sprintf('Aucun wallet %s. Fondez d\'abord votre compte.', $sourceCurrency),
                    'WALLET_NOT_FOUND'
                );
            }
            $available = (string) $wallet['available_balance'];
            if (bccomp($available, $totalDebit, 8) < 0) {
                throw new HttpException(
                    422,
                    sprintf(
                        'Solde disponible insuffisant : %s %s disponible, %s %s requis.',
                        self::fmt($available), $sourceCurrency,
                        self::fmt($totalDebit), $sourceCurrency
                    ),
                    'INSUFFICIENT_FUNDS'
                );
            }

            // ── 6. Saga : hold → capture (règlement réel) ─────────────────
            $holdIdemKey    = $useIdempotency ? substr($idempotencyKey . ':hold', 0, 64) : null;
            $captureIdemKey = $useIdempotency ? substr($idempotencyKey . ':capture', 0, 64) : null;

            $hold = WalletService::createHold(
                $userId,
                (int) $wallet['id'],
                $totalDebit,
                $sourceCurrency,
                $holdIdemKey,
                sprintf('Envoi %s → %s', $sourceCurrency, $destCurrency),
                ['quote_id' => $quoteId, 'route_id' => $routeId, 'kind' => 'send']
            );

            WalletService::captureHold((string) $hold['operation_id'], $userId, $captureIdemKey);

            // ── 7. Écriture comptable dashboard (table transactions) ─────
            $txId = self::insertTransaction(
                $pdo,
                $userId,
                $quote,
                $route,
                $amountSent,
                $sourceCurrency,
                $destCurrency,
                $feeSource,
                $received,
                $fxRate,
                $operationId,
                $startedAt
            );

            // ── 8. Transition de la quote ─────────────────────────────────
            self::markQuoteExecuted($pdo, $quoteId, $routeId);

            // ── 9. Notification ───────────────────────────────────────────
            self::notify($pdo, $userId, $sourceCurrency, $destCurrency, $received, $route);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($useIdempotency) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable) {
                    // Meilleur effort : l'erreur métier prime.
                }
            }
            throw $e;
        }

        // ── 10. Relecture + clôture idempotente ───────────────────────────
        $tx = self::loadTransaction($pdo, $txId, $userId);
        if ($tx === null) {
            throw new RuntimeException('Transaction introuvable après exécution.');
        }

        if ($useIdempotency) {
            IdempotencyService::complete($idempotencyKey, $userId, $tx, $operationId);
        }

        return $tx;
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
    // Helpers — écritures
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $route
     */
    private static function insertTransaction(
        PDO $pdo,
        int $userId,
        array $quote,
        array $route,
        string $amountSent,
        string $sourceCurrency,
        string $destCurrency,
        string $feeSource,
        float $received,
        float $fxRate,
        string $operationId,
        float $startedAt
    ): int {
        $rateRef   = Currency::rateToRef($sourceCurrency);
        $rateXaf   = Currency::rateToXaf($sourceCurrency);
        $amountRef = round((float) $amountSent * $rateRef, 2);
        $amountXaf = round((float) $amountSent * $rateXaf, 2);
        $fee2      = round((float) $feeSource, 2);
        $execSec   = max(1, (int) round(microtime(true) - $startedAt));

        $provider    = substr((string) ($route['provider'] ?? ''), 0, 50);
        $description = substr(sprintf('Route %s · %s', (string) ($route['id'] ?? ''), $provider), 0, 255);

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
            'qid'     => (string) $quote['id'],
            'rid'     => (string) ($route['id'] ?? ''),
            'uid'     => $userId,
            'type'    => 'send',
            'dir'     => 'out',
            'label'   => sprintf('Envoi %s → %s', $sourceCurrency, $destCurrency),
            'desc'    => $description !== '' ? $description : null,
            'amount'  => round((float) $amountSent, 2),
            'cur'     => $sourceCurrency,
            'aref'    => $amountRef,
            'refcur'  => Currency::REF,
            'axaf'    => $amountXaf,
            'damount' => round($received, 2),
            'dcur'    => $destCurrency,
            'fxr'     => $fxRate > 0 ? number_format($fxRate, 8, '.', '') : null,
            'fee'     => $fee2,
            'feecur'  => $sourceCurrency,
            'status'  => 'completed',
            'prov'    => $provider !== '' ? $provider : null,
            'dest'    => self::destinationLabel($quote),
            'execsec' => $execSec,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function markQuoteExecuted(PDO $pdo, string $quoteId, string $routeId): void
    {
        $stmt = $pdo->prepare("UPDATE quotes SET selected_route_id = :rid, status = 'EXECUTED' WHERE id = :id");
        $stmt->execute(['rid' => $routeId, 'id' => $quoteId]);
    }

    /**
     * @param array<string,mixed> $route
     */
    private static function notify(
        PDO $pdo,
        int $userId,
        string $sourceCurrency,
        string $destCurrency,
        float $received,
        array $route
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message)
             VALUES (:uid, :type, :title, :msg)'
        );
        $stmt->execute([
            'uid'   => $userId,
            'type'  => 'transfert',
            'title' => 'Transfert exécuté',
            'msg'   => sprintf(
                'Envoi %s → %s réglé via %s. Montant reçu : %s %s.',
                $sourceCurrency,
                $destCurrency,
                (string) ($route['provider'] ?? 'Nexus'),
                self::fmt((string) $received),
                $destCurrency
            ),
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
     * Normalise les types numériques pour la sérialisation JSON.
     *
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

    /** @param array<string,mixed> $quote */
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
