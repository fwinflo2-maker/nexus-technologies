<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;
use Nexus\Services\ProviderCredentialService;
use Nexus\Providers\WebhookVerifier;
use Nexus\Services\FundingProposalService;
use Nexus\Services\FundingIntentService;
use Nexus\Services\FundingService;
use Nexus\Services\WalletService;
use Throwable;

/**
 * FundingController — entrée de fonds réelle via webhook provider (§6).
 *
 *   POST /api/funding/deposit
 *
 * Route PUBLIQUE (le provider n'a pas de session utilisateur) : authentifiée
 * par SIGNATURE HMAC HORODATÉE (`X-Nexus-Signature: t=<unix>,v1=<hex>`,
 * secret de webhook du provider), vérifiée AVANT toute interprétation du
 * contenu. Ce flux est un contrat entrant DÉFINI PAR NEXUS (générique,
 * provider-agnostique) : aucun mécanisme natif provider n'existe pour lui,
 * d'où le schéma horodaté propre (Cycle 5, P1 anti-rejeu).
 *
 * Contrat du payload :
 *   {
 *     "currency": "EUR",
 *     "amount": "100.00",
 *     "provider": "cashramp",       // slug
 *     "provider_reference": "dep_…",// référence unique du dépôt chez le provider
 *     "environment": "sandbox",     // optionnel — mismatch = refus
 *     "event_id": "evt_…"           // optionnel — sinon dérivé (référence:statut)
 *   }
 *
 * Garanties :
 *   - signature `v1 = HMAC-SHA256(t . "." . corps_brut)` : le timestamp est
 *     signé ; fenêtre de validité ±300 s ; une signature capturée puis
 *     rejouée plus tard est refusée (`WEBHOOK_SIGNATURE_STALE`) ;
 *   - rejeu détecté par event_id (`provider_webhook_events`, UNIQUE
 *     provider+environment+event_id, namespace `funding:`) — un duplicata est
 *     acquitté sans retraitement destructif ;
 *   - JAMAIS `UPDATE wallets SET balance = balance + X` sans posting ledger :
 *     le crédit passe par LedgerService::postFundingCredit (PROVIDER_ASSET /
 *     USER_POSITION) dans la même transaction ;
 *   - idempotence métier par (provider, provider_reference) — un rejeu est
 *     acquitté sans double crédit même si l'event_id diffère ;
 *   - l'utilisateur est crédité UNIQUEMENT si un wallet existant correspond ;
 *   - le montant entre dans le bucket `pending` (disponible après
 *     settlement — politique conservatrice).
 *
 * Le propriétaire vient exclusivement d'un funding_intent pré-créé. Tout
 * user_id dans le payload est ignoré.
 */
final class FundingController
{
    private const SIGNATURE_HEADER = 'X-Nexus-Signature';

    /** Fenêtre de validité du timestamp signé (secondes). */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public static function deposit(Request $request): void
    {
        $raw = $request->rawBody();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::error('Payload de dépôt invalide.', 400, 'INVALID_DEPOSIT_PAYLOAD');
        }

        $provider = substr((string) ($payload['provider'] ?? ''), 0, 50);
        $env = ProviderConfig::activeEnvironment($provider);

        // 1) Signature AVANT toute interprétation (fail-closed).
        $creds  = ProviderCredentialService::resolvePlatform(Database::getConnection(), $provider, $env) ?? [];
        $secret = $creds['webhook_secret'] ?? ProviderConfig::credential($provider, 'WEBHOOK_SECRET', $env);
        if ($secret === null || $secret === '') {
            Response::error(
                'Aucun secret de webhook configuré pour ce provider : les dépôts entrants sont refusés.',
                501,
                'WEBHOOK_NOT_CONFIGURED'
            );
        }
        $signature = (string) ($request->header(self::SIGNATURE_HEADER) ?? '');
        $verdict = WebhookVerifier::verifyTimestamped(
            $raw,
            $signature,
            $secret,
            self::SIGNATURE_TOLERANCE_SECONDS
        );
        if (!$verdict['valid']) {
            self::auditWebhook($request, $provider, $env, null, 'rejected', (string) $verdict['reason']);
            if ($verdict['reason'] === 'stale_timestamp') {
                Response::error(
                    'Timestamp de webhook hors fenêtre de validité.',
                    401,
                    'WEBHOOK_SIGNATURE_STALE'
                );
            }
            Response::error('Signature de webhook invalide.', 401, 'INVALID_WEBHOOK_SIGNATURE');
        }

        // 2) Environnement déclaré.
        $declaredEnv = ProviderCredentialService::normalizeEnvironment((string) ($payload['environment'] ?? ''));
        if ($declaredEnv !== null && $declaredEnv !== $env) {
            Response::error('Environnement de webhook incohérent.', 409, 'WEBHOOK_ENVIRONMENT_MISMATCH');
        }

        // 3) Contenu financier + référence provider. L'identité utilisateur
        //    n'est jamais lue depuis le webhook.
        $currency = strtoupper(substr((string) ($payload['currency'] ?? ''), 0, 5));
        $amount   = (string) ($payload['amount'] ?? '');
        $ref      = substr((string) ($payload['provider_reference'] ?? ''), 0, 190);
        $providerStatus = strtoupper((string) ($payload['status'] ?? 'COMPLETED'));
        if ($currency === '' || $ref === '') {
            Response::error('Champs manquants (currency, provider_reference).', 400, 'INVALID_DEPOSIT_PAYLOAD');
        }
        if ($amount === '' || bccomp($amount, '0', 8) <= 0) {
            Response::error('Montant de dépôt invalide.', 422, 'INVALID_DEPOSIT_AMOUNT');
        }

        // 4) Rejeu par event_id — namespace `funding:` pour ne jamais entrer
        //    en collision avec les événements payout du même provider. Le
        //    duplicata est journalisé puis acquitté via le chemin idempotent :
        //    on NE saute PAS confirm() (une première livraison échouée en
        //    interne doit rester rejouable), l'idempotence métier garantit
        //    l'absence de double crédit.
        $suppliedEventId = substr(trim((string) ($payload['event_id'] ?? '')), 0, 150);
        $eventId = 'funding:' . ($suppliedEventId !== '' ? $suppliedEventId : $ref . ':' . $providerStatus);
        $duplicateEvent = self::persistEvent($provider, $env, $eventId);

        // 5) Attribution sûre + idempotence + posting double entrée.
        try {
            $result = FundingIntentService::confirm(
                $provider,
                $ref,
                $currency,
                $amount,
                $env,
                $providerStatus
            );
        } catch (HttpException $e) {
            self::markEvent($provider, $env, $eventId, 'rejected');
            self::auditWebhook($request, $provider, $env, $eventId, 'rejected', $e->errorCode());
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (Throwable $e) {
            error_log('[NEXUS funding] ' . $e->getMessage());
            Response::error('Erreur interne lors de l\'enregistrement du dépôt.', 500, 'DEPOSIT_INTERNAL_ERROR');
        }

        self::markEvent($provider, $env, $eventId, $result['status'] === 'completed' ? 'settled' : 'processing');
        self::auditWebhook($request, $provider, $env, $eventId, $duplicateEvent ? 'duplicate' : 'received', null);

        Response::success([
            'deposit' => [
                'operation_id' => $result['operation_id'],
                'status'       => $result['status'],
                'balance'      => $result['balance'],
            ],
            'duplicate' => $duplicateEvent,
            'message'   => 'Dépôt enregistré — disponible après settlement.',
        ]);
    }

    /**
     * Insère l'événement webhook (UNIQUE provider+environment+event_id).
     *
     * @return bool true si l'event_id a déjà été vu (rejeu détecté).
     */
    private static function persistEvent(string $provider, string $env, string $eventId): bool
    {
        $stmt = Database::getConnection()->prepare(
            'INSERT INTO provider_webhook_events (provider, environment, event_id, event_type, status)
             VALUES (:p, :e, :i, :t, :s)'
        );
        try {
            $stmt->execute([
                'p' => $provider,
                'e' => $env,
                'i' => $eventId,
                't' => 'funding.deposit',
                's' => 'received',
            ]);
            return false;
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
            throw $e;
        }
    }

    private static function markEvent(string $provider, string $env, string $eventId, string $status): void
    {
        try {
            Database::getConnection()->prepare(
                'UPDATE provider_webhook_events SET status = :status
                 WHERE provider = :provider AND environment = :environment AND event_id = :event'
            )->execute([
                'status' => $status,
                'provider' => $provider,
                'environment' => $env,
                'event' => $eventId,
            ]);
        } catch (Throwable $e) {
            error_log('[NEXUS funding] markEvent: ' . $e->getMessage());
        }
    }

    /** Audit sans payload ni secret : provider, event_id, décision, raison. */
    private static function auditWebhook(
        Request $request,
        string $provider,
        string $env,
        ?string $eventId,
        string $decision,
        ?string $reason
    ): void {
        try {
            $meta = [
                'provider'   => $provider,
                'event_id'   => $eventId,
                'request_id' => \Nexus\Core\Correlation::id(),
            ];
            if ($reason !== null) {
                $meta['reason'] = $reason;
            }
            Database::getConnection()->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address, environment, created_at)
                 VALUES (NULL, :act, :etype, NULL, :meta, :ip, :env, NOW())'
            )->execute([
                'act'   => 'funding.webhook.' . $decision,
                'etype' => 'provider_webhook_events',
                'meta'  => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip'    => $request->ipAddress(),
                'env'   => $env,
            ]);
        } catch (Throwable $e) {
            error_log('[NEXUS audit] ' . $e->getMessage());
        }
    }

    /** POST /api/funding/intents — lie une référence provider à l'utilisateur authentifié. */
    public static function createIntent(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];
        $provider = strtolower(trim((string) $request->input('provider', '')));
        $reference = trim((string) $request->input('provider_reference', ''));
        $currency = strtoupper(trim((string) $request->input('currency', '')));
        $amount = trim((string) $request->input('amount', ''));
        $wallet = WalletService::getWallet($userId, $currency);
        if ($wallet === null) {
            Response::error('Wallet de dépôt introuvable.', 404, 'WALLET_NOT_FOUND');
        }
        $context = ExecutionContext::fromRequest($request, is_array($user) ? $user : []);
        try {
            $intent = FundingIntentService::create(
                $userId,
                (int) $wallet['id'],
                $provider,
                $reference,
                $currency,
                $amount,
                $context
            );
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        }
        Response::success(['intent' => $intent], 201);
    }

    /**
     * GET /api/funding/proposals?currency=EUR
     * Propositions de dépôt filtrées par pays d’enregistrement (auth).
     */
    public static function proposals(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $currency = strtoupper(trim((string) $request->query('currency', '')));

        $data = FundingProposalService::listForUser(
            is_array($user) ? $user : [],
            $currency !== '' ? $currency : null
        );
        Response::success($data);
    }

    /**
     * GET /api/funding/payment-methods?country=FR
     * Modes / kinds autorisés pour un pays (défaut = pays d’enregistrement).
     */
    public static function paymentMethods(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $country = strtoupper(trim((string) $request->query('country', '')));
        if ($country === '' || strlen($country) !== 2) {
            $country = FundingProposalService::registrationCountry(is_array($user) ? $user : []) ?? '';
        }
        if ($country === '' || strlen($country) !== 2) {
            Response::success([
                'country'          => null,
                'methods'          => [],
                'account_kinds'    => ['source' => [], 'destination' => []],
                'default_currency' => 'EUR',
                'has_mobile_money' => false,
                'message'          => 'Complétez le pays d’enregistrement (KYC) pour adapter les modes de paiement.',
            ]);
            return;
        }
        $data = FundingProposalService::availablePaymentModes($country);
        $data['message'] = null;
        $data['deposit_currencies'] = FundingProposalService::depositCurrenciesForCountry($country);
        Response::success($data);
    }

    /**
     * POST /api/funding/collect
     * Collecte sandbox via une proposal : crédit wallet immédiat (ledger).
     * Production : pending (pas de faux succès argent réel).
     *
     * Body : { proposal_id, currency, amount, account_reference?, idempotency_key? }
     */
    public static function collect(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $proposalId = trim((string) $request->input('proposal_id', ''));
        $currency   = strtoupper(trim((string) $request->input('currency', '')));
        $amount     = trim((string) $request->input('amount', ''));
        $reference  = trim((string) $request->input('account_reference', ''));
        $idemKey    = trim((string) $request->input('idempotency_key', ''));

        if ($proposalId === '') {
            Response::error('proposal_id requis.', 422, 'PROPOSAL_REQUIRED');
        }
        if ($currency === '' || !preg_match('/^[A-Z]{3,5}$/', $currency)) {
            Response::error('Devise invalide.', 422, 'INVALID_CURRENCY');
        }
        $regCountry = FundingProposalService::registrationCountry(is_array($user) ? $user : []);
        if ($regCountry !== null && !FundingProposalService::isDepositCurrencyAllowed($regCountry, $currency)) {
            Response::error(
                'Cette devise n’est pas disponible pour le pays d’enregistrement ' . $regCountry . '.',
                422,
                'CURRENCY_NOT_FOR_COUNTRY'
            );
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            Response::error('Le montant doit être un nombre strictement positif.', 422, 'INVALID_AMOUNT');
        }
        if (bccomp($amount, '100000', 8) > 0) {
            Response::error('Montant trop élevé (max 100 000).', 422, 'AMOUNT_TOO_HIGH');
        }

        try {
            $proposal = FundingProposalService::resolveForUser(is_array($user) ? $user : [], $proposalId);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        }

        if (!empty($proposal['requires_reference']) && $reference === '') {
            Response::error(
                'Une référence (téléphone / IBAN) est requise pour ce rail.',
                422,
                'REFERENCE_REQUIRED'
            );
        }

        $appEnv = defined('APP_ENV') ? (string) APP_ENV : (string) (getenv('APP_ENV') ?: '');
        $isProd = strtolower(trim($appEnv)) === 'production' || ProviderConfig::defaultEnvironment() === 'production';

        if ($isProd) {
            // Pas de crédit immédiat en production — intent à brancher (phase 2).
            Response::success([
                'collect' => [
                    'status'      => 'pending',
                    'proposal_id' => $proposalId,
                    'currency'    => $currency,
                    'amount'      => $amount,
                ],
                'message' => 'Collecte initiée — confirmation provider en attente (production).',
            ], 202);
        }

        $context = ExecutionContext::fromRequest($request, is_array($user) ? $user : []);
        if ($context->environmentValue() !== 'sandbox') {
            Response::error('La collecte instantanée n’est autorisée qu’en sandbox.', 403, 'COLLECT_SANDBOX_ONLY');
        }

        if ($idemKey === '') {
            $idemKey = 'funding-collect:' . $userId . ':' . $proposalId . ':' . bin2hex(random_bytes(8));
        }

        $wallet = WalletService::ensureWallet($userId, $currency);
        $providerSlug = (string) ($proposal['provider_slug'] ?? 'nexus_sandbox');

        try {
            $result = FundingService::recordDeposit(
                $userId,
                (int) $wallet['id'],
                $currency,
                $amount,
                $providerSlug,
                $idemKey,
                'collect_' . $proposalId . '_' . bin2hex(random_bytes(4)),
                [
                    'source'             => 'funding_collect',
                    'proposal_id'        => $proposalId,
                    'account_reference'  => $reference !== '' ? substr($reference, 0, 64) : null,
                    'method'             => $proposal['method'] ?? null,
                ],
                $context
            );
            FundingService::settleDeposit($result['operation_id'], $userId, $context);
            $updated = WalletService::getWallet($userId, $currency);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (Throwable $e) {
            error_log('[NEXUS funding/collect] ' . $e->getMessage());
            Response::error('Erreur lors de la collecte.', 500, 'COLLECT_INTERNAL_ERROR');
        }

        Response::success([
            'collect' => [
                'operation_id' => $result['operation_id'],
                'status'       => 'completed',
                'proposal_id'  => $proposalId,
                'currency'     => $currency,
                'amount'       => $amount,
                'provider'     => $providerSlug,
            ],
            'wallet'  => $updated,
            'message' => 'Fonds crédités via ' . ($proposal['label'] ?? $proposalId) . '.',
        ]);
    }
}
