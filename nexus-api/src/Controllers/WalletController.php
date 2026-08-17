<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Models\TransferRequest;
use Nexus\Execution\ExecutionContext;
use Nexus\Core\HttpException;
use Nexus\Core\Response;
use Nexus\Services\FXService;
use Nexus\Services\IdempotencyService;
use Nexus\Services\WalletService;

/**
 * Wallet : portefeuille multi-devises branché sur MySQL.
 *
 *  - GET /api/wallets                        → soldes de l'utilisateur par devise,
 *                                              ventilés par état (available / pending /
 *                                              in_transit / settlement) + totaux en EUR.
 *  - GET /api/wallets/rates                  → taux de conversion EUR de référence
 *                                              (taux fixe MVP : 1 EUR = 655,957 XAF).
 *  - GET /api/wallets/{currency}/transactions → 10 dernières transactions de la devise.
 *
 * Aucune valeur n'est codée en dur : les soldes proviennent de la table `wallets`,
 * l'historique de la table `transactions`.
 */
final class WalletController
{
    /** Nombre de transactions renvoyées par l'historique rapide. */
    private const TX_LIMIT = 10;

    /**
     * POST /api/wallets/convert
     * Conversion entre deux wallets de l'utilisateur.
     *
     * LE DÉFAUT CORRIGÉ (faux succès)
     * ───────────────────────────────
     * `WalletService::transferMultiCurrency()` est complet et lourdement
     * testé, mais n'était appelé QUE par les tests : aucune route ne
     * l'exposait. Côté interface, « Convertir » exécutait un `setTimeout` de
     * deux secondes puis vidait le formulaire — l'utilisateur voyait une
     * conversion réussie alors qu'aucun argent n'avait bougé.
     *
     * Cette route relie l'interface au moteur réel. Elle n'invente aucune
     * règle : montants, taux, atomicité, idempotence et écritures comptables
     * restent entièrement la responsabilité de `transferMultiCurrency`.
     */
    public static function convert(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $amount         = trim((string) $request->input('amount', ''));
        $sourceCurrency = strtoupper(trim((string) $request->input('source_currency', '')));
        $destCurrency   = strtoupper(trim((string) $request->input('dest_currency', '')));
        $idemKey        = trim((string) $request->input('idempotency_key', ''));
        // Liens vers la quote de conversion (optionnel) : quand il est fourni,
        // l'exécution honore le TAUX VERROUILLÉ de la quote au lieu de
        // re-résoudre le taux courant (garantie « taux vu = taux appliqué »).
        $quoteId        = trim((string) $request->input('quote_id', ''));
        $routeId        = trim((string) $request->input('route_id', ''));

        // Validation d'entrée : 422, jamais 500. Ces refus sont la faute du
        // client, et le message doit lui permettre de corriger.
        if ($sourceCurrency === '' || $destCurrency === '') {
            Response::error('Les devises source et destination sont requises.', 422, 'CURRENCY_REQUIRED');
        }

        if ($sourceCurrency === $destCurrency) {
            Response::error(
                'Les devises source et destination doivent être différentes.',
                422,
                'SAME_CURRENCY'
            );
        }

        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            Response::error('Le montant doit être un nombre strictement positif.', 422, 'INVALID_AMOUNT');
        }

        // Les wallets sont résolus À PARTIR DU JETON, jamais d'un identifiant
        // fourni par le client. L'utilisateur désigne une DEVISE — un concept
        // qui lui appartient — et le serveur retrouve le wallet correspondant.
        // Aucun identifiant de wallet ne transite, donc aucune énumération
        // possible : on ne peut pas cibler le wallet d'autrui.
        $sourceWallet = WalletService::getWallet($userId, $sourceCurrency);
        if ($sourceWallet === null) {
            Response::error(
                sprintf('Aucun wallet %s sur ce compte.', $sourceCurrency),
                404,
                'WALLET_NOT_FOUND'
            );
        }

        // Le wallet de destination est créé au besoin : convertir vers une
        // devise que l'on ne détient pas encore est un usage normal.
        $destWallet = WalletService::ensureWallet($userId, $destCurrency);

        $sourceWalletId = (int) $sourceWallet['id'];
        $destWalletId   = (int) $destWallet['id'];

        if ($sourceWalletId === $destWalletId) {
            Response::error(
                'Les wallets source et destination doivent être différents.',
                422,
                'SAME_WALLET'
            );
        }

        // L'environnement vient du contexte de la requête, jamais de la
        // configuration du serveur au moment de l'exécution.
        $context = ExecutionContext::fromRequest($request, $user);

        // ── Exécution au taux garanti d'une quote (optionnel) ──
        $lockedRate = null;
        $fxSource   = null;
        if ($quoteId !== '') {
            // Un replay idempotent d'une conversion DÉJÀ exécutée doit
            // renvoyer la réponse d'origine, pas un 409 : la quote est certes
            // EXECUTED, mais l'utilisateur n'a rien fait de nouveau. Le
            // contrôle de statut ci-dessous ne s'applique qu'aux nouvelles
            // tentatives.
            if ($idemKey !== '') {
                $cached = IdempotencyService::check($idemKey, $userId, $context->environmentValue());
                if ($cached !== null && $cached['status'] === 'completed') {
                    Response::success(['conversion' => $cached['response_json']]);
                }
                if ($cached !== null && $cached['status'] === 'error') {
                    Response::error(
                        (string) ($cached['response_json']['error'] ?? 'Opération précédente en échec.'),
                        409,
                        'IDEMPOTENCY_ERROR'
                    );
                }
            }

            $pdo   = Database::getConnection();
            $stmt  = $pdo->prepare(
                'SELECT id, status, expires_at, amount_sent, source_currency, dest_currency, routes_json
                 FROM quotes
                 WHERE id = :id AND user_id = :uid AND environment = :env
                 LIMIT 1'
            );
            $stmt->execute([
                'id'  => $quoteId,
                'uid' => $userId,
                'env' => $context->environmentValue(),
            ]);
            $quote = $stmt->fetch();

            if ($quote === false) {
                Response::notFound('Quote introuvable.');
            }
            if ($quote['status'] === 'EXECUTED') {
                Response::error('Cette quote a déjà été exécutée.', 409, 'QUOTE_ALREADY_EXECUTED');
            }
            if (strtotime((string) $quote['expires_at'] . ' UTC') <= time()) {
                $pdo->prepare("UPDATE quotes SET status = 'EXPIRED' WHERE id = :id")
                    ->execute(['id' => $quoteId]);
                Response::error(
                    'Cette quote a expiré. Relancez une demande de conversion pour obtenir un taux actuel.',
                    409,
                    'QUOTE_EXPIRED'
                );
            }
            if ($quote['status'] !== 'QUOTED') {
                Response::error('Cette quote n\'est plus exécutable.', 409, 'QUOTE_NOT_EXECUTABLE');
            }

            // Cohérence : la conversion doit correspondre EXACTEMENT à la
            // quote (devises et montant). Une quote n'est valable que pour
            // l'intention qui l'a produite.
            if (strtoupper((string) $quote['source_currency']) !== $sourceCurrency
                || strtoupper((string) $quote['dest_currency']) !== $destCurrency
                || bccomp((string) $quote['amount_sent'], (string) $amount, 8) !== 0
            ) {
                Response::error(
                    'La demande ne correspond pas à la quote (devises ou montant différents).',
                    422,
                    'QUOTE_MISMATCH'
                );
            }

            $routes = json_decode((string) $quote['routes_json'], true);
            $route  = null;
            foreach ((array) $routes as $r) {
                if ($routeId === '' || (string) ($r['id'] ?? '') === $routeId) {
                    $route = $r;
                    break;
                }
            }
            if ($route === null) {
                Response::error('Route introuvable dans cette quote.', 404, 'ROUTE_NOT_FOUND');
            }

            $lockedRate = (string) ($route['locked_rate'] ?? $route['fx_rate'] ?? '');
            if ($lockedRate === '') {
                Response::error(
                    'Cette quote ne contient pas de taux exécutable.',
                    409,
                    'QUOTE_NOT_EXECUTABLE'
                );
            }
            $fxSource = 'convert_quote';
        }

        try {
            $result = WalletService::transferMultiCurrency(new TransferRequest(
                userId:         $userId,
                sourceWalletId: $sourceWalletId,
                destWalletId:   $destWalletId,
                sourceAmount:   $amount,
                sourceCurrency: $sourceCurrency,
                destCurrency:   $destCurrency,
                type:           'convert',
                idempotencyKey: $idemKey !== '' ? $idemKey : null,
                description:    (string) $request->input('description', 'Conversion de devises'),
                metadata:       null,
                fxSource:       $fxSource,
                context:        $context,
                fxRate:         $lockedRate
            ));

            // La quote a été honorée : elle ne peut plus être ré-exécutée.
            if ($quoteId !== '') {
                Database::getConnection()
                    ->prepare("UPDATE quotes SET selected_route_id = :rid, status = 'EXECUTED' WHERE id = :id")
                    ->execute(['rid' => $routeId !== '' ? $routeId : 'INT', 'id' => $quoteId]);
            }

        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (\Throwable $e) {
            error_log('[wallet] convert: ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Conversion impossible pour le moment.');
        }

        // La réponse est émise HORS du try : `Response::success()` lève une
        // ResponseSent (qui étend RuntimeException) pour interrompre le
        // traitement. Émise à l'intérieur, elle serait rattrapée par le
        // `catch (\Throwable)` ci-dessus et un SUCCÈS deviendrait un 500.
        Response::success(['conversion' => $result->toArray()]);
    }

    /**
     * POST /api/wallets/hold
     * Crée une réservation de fonds.
     */
    public static function hold(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $walletId = (int) $request->input('wallet_id', 0);
        $amount    = (string) $request->input('amount', '0');
        $currency  = (string) $request->input('currency', '');
        $idemKey   = (string) $request->input('idempotency_key', '');

        // Le hold est une opération financière : son environnement vient du
        // contexte de la requête, jamais de la configuration du serveur au
        // moment de l'exécution.
        $context = ExecutionContext::fromRequest($request, $user);

        try {
            $res = WalletService::createHold(
                $userId,
                $walletId,
                $amount,
                $currency,
                $idemKey !== '' ? $idemKey : null,
                (string) $request->input('description', ''),
                (array) $request->input('metadata', []),
                $context
            );
            Response::success($res);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (\Throwable $e) {
            // Exception NON MAÎTRISÉE : ni le message ni le statut ne doivent
            // renseigner le client. Un message PDO brut divulgue le SGBD et le
            // nom des colonnes ; un 400 ferait porter au client la faute d'une
            // panne serveur. Le détail part dans les journaux, pas en réponse.
            error_log('[wallet] ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Opération impossible pour le moment.');
        }
    }

    /**
     * POST /api/wallets/hold/capture
     * Capture un hold existant.
     */
    public static function captureHold(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $opId    = (string) $request->input('operation_id', '');
        $idemKey = (string) $request->input('idempotency_key', '');

        // Le contexte est résolu ici puis transmis : le service compare
        // l'environnement demandé à celui, authoritative, de l'opération.
        $context = ExecutionContext::fromRequest($request, $user);

        try {
            $res = WalletService::captureHold($opId, $userId, $idemKey !== '' ? $idemKey : null, $context);
            Response::success($res);
        } catch (HttpException $e) {
            // Un mismatch d'environnement est un conflit (409), pas une
            // requête malformée : ne jamais le rétrograder en 400.
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (\Throwable $e) {
            // Exception NON MAÎTRISÉE : ni le message ni le statut ne doivent
            // renseigner le client. Un message PDO brut divulgue le SGBD et le
            // nom des colonnes ; un 400 ferait porter au client la faute d'une
            // panne serveur. Le détail part dans les journaux, pas en réponse.
            error_log('[wallet] ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Opération impossible pour le moment.');
        }
    }

    /**
     * POST /api/wallets/hold/release
     * Libère un hold existant.
     */
    public static function releaseHold(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $opId    = (string) $request->input('operation_id', '');
        $idemKey = (string) $request->input('idempotency_key', '');

        // Le contexte est résolu ici puis transmis : le service compare
        // l'environnement demandé à celui, authoritative, de l'opération.
        $context = ExecutionContext::fromRequest($request, $user);

        try {
            $res = WalletService::releaseHold($opId, $userId, $idemKey !== '' ? $idemKey : null, $context);
            Response::success($res);
        } catch (HttpException $e) {
            // Un mismatch d'environnement est un conflit (409), pas une
            // requête malformée : ne jamais le rétrograder en 400.
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (\Throwable $e) {
            // Exception NON MAÎTRISÉE : ni le message ni le statut ne doivent
            // renseigner le client. Un message PDO brut divulgue le SGBD et le
            // nom des colonnes ; un 400 ferait porter au client la faute d'une
            // panne serveur. Le détail part dans les journaux, pas en réponse.
            error_log('[wallet] ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Opération impossible pour le moment.');
        }
    }

    /**
     * GET /api/wallets/holds?status=pending
     *
     * Retourne les holds de l'utilisateur authentifié, filtrés par statut
     * (défaut : pending). Un utilisateur ne voit JAMAIS les holds d'un autre
     * utilisateur : la requête est systématiquement scindée par user_id.
     *
     * Format de réponse (montants = strings décimales, jamais de float) :
     *   {
     *     "operation_id": 123,
     *     "wallet_id": 456,
     *     "amount": "100.00000000",
     *     "currency": "EUR",
     *     "status": "pending",
     *     "created_at": "2026-08-11 12:00:00",
     *     "expires_at": "2026-08-11 12:30:00",
     *     "remaining_seconds": 1200
     *   }
     */
    public static function pendingHolds(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $status = (string) $request->query('status', 'pending');
        $status = in_array($status, ['pending', 'completed', 'cancelled'], true) ? $status : 'pending';

        try {
            $pdo = \Nexus\Core\Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT id, source_wallet_id, source_currency, source_amount,
                        status, created_at, expires_at
                 FROM wallet_operations
                 WHERE user_id = :uid
                   AND type = 'hold'
                   AND status = :status
                 ORDER BY created_at DESC
                 LIMIT 100"
            );
            $stmt->execute(['uid' => $userId, 'status' => $status]);

            $now = time();
            $holds = [];
            while ($row = $stmt->fetch()) {
                $remaining = null;
                if ($row['expires_at'] !== null) {
                    // Les DATETIME MySQL sont stockés en UTC (session +00:00).
                    // On parse explicitement en UTC pour rester indépendant du
                    // fuseau horaire du process PHP.
                    $expiresTs = strtotime((string) $row['expires_at'] . ' UTC');
                    $remaining = $expiresTs === false
                        ? 0
                        : max(0, $expiresTs - $now);
                }
                $holds[] = [
                    'operation_id'      => (string) $row['id'],
                    'wallet_id'         => (int) $row['source_wallet_id'],
                    'amount'            => bcadd((string) $row['source_amount'], '0', 8),
                    'currency'          => (string) $row['source_currency'],
                    'status'            => (string) $row['status'],
                    'created_at'        => (string) $row['created_at'],
                    'expires_at'        => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
                    'remaining_seconds' => $remaining,
                ];
            }

            Response::success(['holds' => $holds]);
        } catch (Throwable $e) {
            error_log('[wallet] holds: ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Erreur lors de la récupération des holds.');
        }
    }

    /**
     * GET /api/wallets
     *
     * Retourne les 6 devises supportées (EUR, USD, GBP, XAF, USDT, USDC) avec
     * les soldes par état, l'équivalent EUR et les totaux agrégés.
     * Une devise absente de la table est renvoyée à zéro (grille complète).
     */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        try {
            // Récupère les soldes via WalletService (au lieu de requête SQL directe)
            $balances = WalletService::getAllBalances($userId);
        } catch (Throwable $e) {
            error_log('[wallet] balances: ' . $e::class . ': ' . $e->getMessage());
            Response::serverError('Erreur lors de la récupération des soldes.');
        }

        // Indexe par devise pour accès rapide
        $byCurrency = [];
        foreach ($balances as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        // L'environnement du contexte détermine les taux utilisables pour
        // les équivalents : un taux sandbox ne convertit pas une vue réelle.
        $context = ExecutionContext::fromRequest($request, $user);

        $wallets = [];
        $totals  = [
            'total_ref'      => 0.0,
            'available_ref'  => 0.0,
            'pending_ref'    => 0.0,
            'in_transit_ref' => 0.0,
            'settlement_ref' => 0.0,
        ];
        $currenciesWithFunds = 0;

        foreach (Currency::WALLET_CURRENCIES as $currency) {
            $wallet = $byCurrency[$currency] ?? null;

            // WalletService retourne des chaînes, on convertit en float
            $balance    = $wallet !== null ? (float) $wallet['balance'] : 0.0;
            $available  = $wallet !== null ? (float) $wallet['available_balance'] : 0.0;
            $pending    = $wallet !== null ? (float) $wallet['pending_balance'] : 0.0;
            $inTransit  = $wallet !== null ? (float) $wallet['in_transit_balance'] : 0.0;
            $settlement = $wallet !== null ? (float) $wallet['settlement_balance'] : 0.0;

            // Équivalent EUR : taux RÉEL de l'environnement, ou null si
            // indisponible — jamais une valeur inventée (§7, §9).
            $rateRef = FXService::rateToRef($currency, $context->environment);
            $refEquivalent = $rateRef !== null && $rateRef > 0.0
                ? round($balance / $rateRef, 2)
                : null;
            if ($rateRef !== null && $rateRef > 0.0) {
                $totals['total_ref']      += $balance / $rateRef;
                $totals['available_ref']  += $available / $rateRef;
                $totals['pending_ref']    += $pending / $rateRef;
                $totals['in_transit_ref'] += $inTransit / $rateRef;
                $totals['settlement_ref'] += $settlement / $rateRef;
            }
            if ($balance > 0) {
                $currenciesWithFunds++;
            }

            $wallets[] = [
                'currency'       => $currency,
                'balance'        => round($balance, 2),
                'available'      => round($available, 2),
                'pending'        => round($pending, 2),
                'in_transit'     => round($inTransit, 2),
                'settlement'     => round($settlement, 2),
                'ref_equivalent' => $refEquivalent,
                'has_funds'      => $balance > 0,
            ];
        }

        Response::success([
            'ref_currency' => Currency::REF,
            'totals'       => [
                'ref_currency'    => Currency::REF,
                'total_ref'       => round($totals['total_ref'], 2),
                'available_ref'   => round($totals['available_ref'], 2),
                'pending_ref'     => round($totals['pending_ref'], 2),
                'in_transit_ref'  => round($totals['in_transit_ref'], 2),
                'settlement_ref'  => round($totals['settlement_ref'], 2),
                'currencies'      => count(Currency::WALLET_CURRENCIES),
                'with_funds'      => $currenciesWithFunds,
            ],
            'wallets'      => $wallets,
        ]);
    }

    /**
     * GET /api/wallets/rates
     *
     * Taux EUR → XAF de la source FX RÉELLE, scopé par l'environnement de la
     * requête. Quand aucun taux n'est disponible, `fx_rate_xaf` vaut null et
     * `available` vaut false : le frontend affiche « indisponible » au lieu
     * d'une valeur inventée (§7).
     */
    public static function rates(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');

        $context = ExecutionContext::fromRequest($request, $user);
        $rate    = FXService::rate('EUR', 'XAF', $context->environment);

        if ($rate === null) {
            Response::success([
                'base'        => Currency::REF,
                'fx_rate_xaf' => null,
                'available'   => false,
                'updated_at'  => null,
            ]);
        }

        Response::success([
            'base'        => Currency::REF,
            'fx_rate_xaf' => $rate,
            'available'   => true,
            'updated_at'  => date(DATE_ATOM),
        ]);
    }

    /**
     * GET /api/wallets/{currency}/transactions
     *
     * Historique rapide : les 10 dernières transactions de la devise donnée.
     * Répond 404 si la devise n'est pas supportée.
     */
    public static function transactions(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $currency = strtoupper((string) $request->param('currency', ''));
        if (!in_array($currency, Currency::WALLET_CURRENCIES, true)) {
            Response::notFound('Devise inconnue');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, type, direction, label, description, amount, currency,
                    status, provider, destination, created_at
             FROM transactions
             WHERE user_id = :uid AND currency = :cur
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::TX_LIMIT
        );
        $stmt->execute(['uid' => $userId, 'cur' => $currency]);

        $items = array_map(
            static fn (array $tx): array => [
                'id'          => (int) $tx['id'],
                'type'        => $tx['type'],
                'direction'   => $tx['direction'],
                'label'       => $tx['label'],
                'description' => $tx['description'],
                'amount'      => round((float) $tx['amount'], 2),
                'currency'    => $tx['currency'],
                'status'      => $tx['status'],
                'provider'    => $tx['provider'],
                'destination' => $tx['destination'],
                'created_at'  => self::toIso8601($tx['created_at']),
            ],
            $stmt->fetchAll()
        );

        Response::success([
            'currency' => $currency,
            'limit'    => self::TX_LIMIT,
            'items'    => $items,
        ]);
    }

    /** Convertit une date MySQL en ISO 8601 avec fuseau (base en UTC). */
    private static function toIso8601(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }
}
