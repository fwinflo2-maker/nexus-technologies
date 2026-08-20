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
use Nexus\Providers\ProviderCredentialService;
use Nexus\Providers\WebhookVerifier;
use Nexus\Services\FundingProposalService;
use Nexus\Services\FundingService;
use Nexus\Services\WalletService;
use Throwable;

/**
 * FundingController — entrée de fonds réelle via webhook provider (§6).
 *
 *   POST /api/funding/deposit
 *
 * Route PUBLIQUE (le provider n'a pas de session utilisateur) : authentifiée
 * par SIGNATURE HMAC (`X-Nexus-Signature`, secret de webhook du provider),
 * vérifiée AVANT toute interprétation du contenu.
 *
 * Contrat du payload :
 *   {
 *     "user_id": 42,                // utilisateur Nexus à créditer
 *     "currency": "EUR",
 *     "amount": "100.00",
 *     "provider": "pawapay",        // slug
 *     "provider_reference": "dep_…",// référence unique du dépôt chez le provider
 *     "environment": "sandbox"      // optionnel — mismatch = refus
 *   }
 *
 * Garanties :
 *   - JAMAIS `UPDATE wallets SET balance = balance + X` sans posting ledger :
 *     le crédit passe par LedgerService::postFundingCredit (PROVIDER_ASSET /
 *     USER_POSITION) dans la même transaction ;
 *   - idempotence par (provider, provider_reference) — un rejeu est acquitté
 *     sans double crédit ;
 *   - l'utilisateur est crédité UNIQUEMENT si un wallet existant correspond ;
 *   - le montant entre dans le bucket `pending` (disponible après
 *     settlement — politique conservatrice).
 *
 * NOTE production : le rattachement utilisateur passera par un dépôt INTENT
 * pré-créé (référence liée au compte à créditer) pour qu'un webhook forgé ne
 * puisse pas désigner n'importe quel user_id. Documenté, pas implémenté ici.
 */
final class FundingController
{
    private const SIGNATURE_HEADER = 'X-Nexus-Signature';

    public static function deposit(Request $request): void
    {
        $raw = $request->rawBody();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::error('Payload de dépôt invalide.', 400, 'INVALID_DEPOSIT_PAYLOAD');
        }

        $provider = substr((string) ($payload['provider'] ?? 'pawapay'), 0, 50);
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
        if (!WebhookVerifier::verify($raw, $signature, $secret)) {
            Response::error('Signature de webhook invalide.', 401, 'INVALID_WEBHOOK_SIGNATURE');
        }

        // 2) Environnement déclaré.
        $declaredEnv = ProviderCredentialService::normalizeEnvironment((string) ($payload['environment'] ?? ''));
        if ($declaredEnv !== null && $declaredEnv !== $env) {
            Response::error('Environnement de webhook incohérent.', 409, 'WEBHOOK_ENVIRONMENT_MISMATCH');
        }

        // 3) Contenu : utilisateur, devise, montant, référence provider.
        $userId   = (int) ($payload['user_id'] ?? 0);
        $currency = strtoupper(substr((string) ($payload['currency'] ?? ''), 0, 5));
        $amount   = (string) ($payload['amount'] ?? '');
        $ref      = substr((string) ($payload['provider_reference'] ?? ''), 0, 190);
        if ($userId <= 0 || $currency === '' || $ref === '') {
            Response::error('Champs manquants (user_id, currency, provider_reference).', 400, 'INVALID_DEPOSIT_PAYLOAD');
        }
        if ($amount === '' || bccomp($amount, '0', 8) <= 0) {
            Response::error('Montant de dépôt invalide.', 422, 'INVALID_DEPOSIT_AMOUNT');
        }

        // 4) Le wallet doit EXISTER (pas de création silencieuse de compte).
        $wallet = WalletService::getWallet($userId, $currency);
        if ($wallet === null) {
            Response::error(
                sprintf('Aucun wallet %s pour cet utilisateur : dépôt refusé.', $currency),
                422,
                'WALLET_NOT_FOUND'
            );
        }

        // 5) Idempotence par (provider, référence) + posting double entrée.
        $idemKey = 'deposit:' . $provider . ':' . $ref;
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::fromString($env));

        try {
            $result = FundingService::recordDeposit(
                $userId,
                (int) $wallet['id'],
                $currency,
                $amount,
                $provider,
                $idemKey,
                $ref,
                ['source' => 'provider_webhook'],
                $context
            );
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        } catch (Throwable $e) {
            error_log('[NEXUS funding] ' . $e->getMessage());
            Response::error('Erreur interne lors de l\'enregistrement du dépôt.', 500, 'DEPOSIT_INTERNAL_ERROR');
        }

        Response::success([
            'deposit' => [
                'operation_id' => $result['operation_id'],
                'status'       => $result['status'],
                'balance'      => $result['balance'],
            ],
            'message' => 'Dépôt enregistré — disponible après settlement.',
        ]);
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
