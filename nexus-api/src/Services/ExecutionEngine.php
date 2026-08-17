<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\EnvironmentGuard;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderOperationNotImplemented;
use Nexus\Providers\ProviderRegistry;
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
        ?string $idempotencyKey = null,
        ?ExecutionContext $context = null
    ): array {
        $pdo          = Database::getConnection();
        $useIdem      = $idempotencyKey !== null && $idempotencyKey !== '';
        $operationId  = self::uuid();

        if ($useIdem) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId, $context?->environmentValue());
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

            // §9 — la quote appartient à un environnement ; l'exécution doit
            // se dérouler dans le MÊME. Jamais de correction automatique :
            // ni réécriture de la quote, ni génération silencieuse d'une
            // nouvelle quote dans l'autre environnement.
            if ($context !== null) {
                EnvironmentGuard::assertMatches(
                    (string) ($quote['environment'] ?? ''),
                    $context,
                    'Cette quote'
                );
            }

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
            $txId  = self::executeTransferInternal($userId, $spec, $pdo, $operationId, $startedAt, $context);

            // ── 4. Transition de la quote ─────────────────────────────────
            self::markQuoteExecuted($pdo, $quoteId, $routeId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($useIdem) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId, $context?->environmentValue());
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
            IdempotencyService::complete($idempotencyKey, $userId, $tx, $operationId, $context?->environmentValue());
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
    public static function executeTransfer(
        int $userId,
        array $spec,
        ?string $idempotencyKey = null,
        ?ExecutionContext $context = null
    ): array {
        $pdo         = Database::getConnection();
        $useIdem     = $idempotencyKey !== null && $idempotencyKey !== '';
        $operationId = self::uuid();

        if ($useIdem) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId, $context?->environmentValue());
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

            $txId = self::executeTransferInternal($userId, $spec, $pdo, $operationId, $startedAt, $context);

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
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId, $context?->environmentValue());
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
            IdempotencyService::complete($idempotencyKey, $userId, $tx, $operationId, $context?->environmentValue());
        }
        return $tx;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Saga interne (exécutée DANS une transaction ouverte)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $spec
     */
    private static function executeTransferInternal(int $userId, array $spec, PDO $pdo, string $operationId, float $startedAt, ?ExecutionContext $context = null): int
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
            ['operation_id' => $operationId, 'kind' => $type, 'metadata' => $metadata],
            $context
        );

        // ── 2bis. Appel provider RÉEL : hold → provider → capture (§6) ──
        // Le pipeline n'est jamais « hold → capture → succès » sans passer
        // par le provider. Sans provider configuré pour cet environnement,
        // l'exécution REFUSE (NO_AVAILABLE_PROVIDER) et aucune transaction
        // n'est créée. Avec un provider configuré, la capture ne s'exécute
        // que sur une réponse réelle du provider ; toute intégration non
        // implémentée ou en échec fait échouer l'opération (PROVIDER_ERROR).
        self::callProvider($provider, $sourceCurrency, $destCurrency, $amountSent, $destination, $operationId, $context);

        WalletService::captureHold((string) $hold['operation_id'], $userId, $captureIdemKey, $context);

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
            $startedAt,
            $context
        );

        // ── 4. Notification ───────────────────────────────────────────
        self::notify($pdo, $userId, $sourceCurrency, $destCurrency, $destAmount, $provider !== '' ? $provider : null);

        return $txId;
    }

    /**
     * Vérrou provider + appel réel avant règlement (§6).
     *
     * Exécuté DANS la transaction PDO ouverte : un échec (provider non
     * configuré, intégration non implémentée, refus de l'API) fait échouer
     * l'opération et restaure l'état initial — aucun hold ne subsiste, aucun
     * ledger n'est écrit, aucune transaction n'est créée.
     *
     * @throws HttpException NO_AVAILABLE_PROVIDER / PROVIDER_ERROR
     */
    private static function callProvider(
        string $provider,
        string $sourceCurrency,
        string $destCurrency,
        string $amountSent,
        string $destination,
        string $operationId,
        ?ExecutionContext $context
    ): void {
        if ($provider === '') {
            throw new HttpException(
                409,
                'Aucun provider n\'est associé à cette route : l\'opération ne peut pas être exécutée.',
                'NO_AVAILABLE_PROVIDER'
            );
        }

        if (!ProviderRegistry::isConfigured($provider)) {
            throw new HttpException(
                409,
                sprintf(
                    'Le provider « %s » n\'est pas configuré pour l\'environnement « %s » : aucune transaction ne sera créée.',
                    $provider,
                    $context?->environmentValue() ?? ProviderConfig::defaultEnvironment()
                ),
                'NO_AVAILABLE_PROVIDER'
            );
        }

        $params = [
            'operation_id' => $operationId,
            'amount'       => $amountSent,
            'currency'     => $sourceCurrency,
            'dest_currency'=> $destCurrency,
            'destination'  => $destination,
            'environment'  => $context?->environmentValue() ?? ProviderConfig::defaultEnvironment(),
        ];

        try {
            ProviderRegistry::adapter($provider)->createPayment($params);
        } catch (ProviderOperationNotImplemented $e) {
            // Intégration pas encore câblée : refus explicite, jamais de
            // succès simulé.
            throw new HttpException(
                503,
                sprintf('L\'intégration du provider « %s » n\'est pas encore implémentée.', $provider),
                'PROVIDER_ERROR'
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[NEXUS execution] provider ' . $provider . ' : ' . $e->getMessage());
            throw new HttpException(
                502,
                sprintf('Le provider « %s » a refusé ou échoué l\'opération. Aucune transaction n\'a été créée.', $provider),
                'PROVIDER_ERROR'
            );
        }
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

        // Les frais du barème sont libellés en EUR : leur conversion dans la
        // devise source exige un taux EUR→devise RÉEL (§7). Sans taux, la
        // quote ne peut pas être réglée : refus explicite. L'environnement
        // de la quote est la référence (jamais le défaut serveur).
        $feeEur  = (float) ($route['feesNum'] ?? 0);
        $env     = ExecutionEnvironment::fromString((string) ($quote['environment'] ?? ProviderConfig::defaultEnvironment()));
        $rateRef = FXService::rateToRef($sourceCurrency, $env);
        if ($rateRef === null && strtoupper($sourceCurrency) !== 'EUR') {
            throw new HttpException(
                503,
                sprintf('Aucun taux de change disponible pour %s : frais non calculables.', $sourceCurrency),
                'FX_RATE_UNAVAILABLE'
            );
        }
        $rateRef ??= 1.0;
        // 1 EUR = X devise → feeSource = feeEur × X.
        $feeSource = $feeEur > 0.0 ? bcmul((string) $feeEur, (string) $rateRef, 8) : '0.00000000';

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
        float $startedAt,
        ?ExecutionContext $context = null
    ): int {
        // Projections de référence (§7, §9) : calculées avec les taux RÉELS
        // de l'environnement. Quand aucune conversion n'est disponible, les
        // colonnes NOT NULL conservent leur défaut 0.00 (projection interne
        // d'agrégation — le montant réel reste `amount`/`currency`).
        $env       = $context?->environmentValue() ?? ProviderConfig::defaultEnvironment();
        $execEnv   = ExecutionEnvironment::fromString($env);
        $rateRef   = FXService::rateToRef($sourceCurrency, $execEnv);
        $rateXaf   = FXService::rateToXaf($sourceCurrency, $execEnv);
        $amountRef = $rateRef !== null ? round((float) $amountSent / $rateRef, 2) : 0.0;
        $amountXaf = $rateXaf !== null ? round((float) $amountSent * $rateXaf, 2) : 0.0;
        $fee2      = round((float) $feeSource, 2);
        $execSec   = max(1, (int) round(microtime(true) - $startedAt));

        $description = substr(sprintf('Route %s · %s', $routeId !== '' ? $routeId : '-', $provider !== '' ? $provider : 'Nexus'), 0, 255);

        $stmt = $pdo->prepare(
            'INSERT INTO transactions
                (quote_id, route_id, user_id, type, direction, label, description,
                 amount, currency, amount_ref, ref_currency, amount_xaf,
                 dest_amount, dest_currency, fx_rate, fee, fee_currency,
                 status, provider, destination, execution_time_seconds, environment)
             VALUES
                (:qid, :rid, :uid, :type, :dir, :label, :desc,
                 :amount, :cur, :aref, :refcur, :axaf,
                 :damount, :dcur, :fxr, :fee, :feecur,
                 :status, :prov, :dest, :execsec, :env)'
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
            // L'environnement vient du contexte d'exécution, jamais du DEFAULT
            // SQL (`production`), qui n'existe que pour les lignes historiques.
            // Sans contexte (appel interne hérité), on retombe sur le défaut
            // serveur — `sandbox` sauf déploiement de production : une ligne
            // non attribuable ne doit pas être présumée « argent réel ».
            'env'     => $context?->environmentValue() ?? ProviderConfig::defaultEnvironment(),
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
