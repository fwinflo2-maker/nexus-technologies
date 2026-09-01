<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Services\CompanyRegistryService;
use Nexus\Services\KycService;
use RuntimeException;

/**
 * KYC / KYB — vérification d'identité déléguée au provider officiel (§18-§26).
 *
 * Routes :
 *   GET  /api/kyc/status    → statut courant (sans donnée sensible)
 *   POST /api/kyc/session   → démarre la vérification, renvoie un token SDK
 *   POST /api/kyc/webhook   → webhook provider (PUBLIC, signé)
 *
 * PRINCIPE : le frontend ne peut JAMAIS déclarer un utilisateur vérifié.
 * Seul un webhook signé (ou une lecture serveur du statut) fait autorité.
 */
final class KycController
{
    /** Résout le provider KYC actif. */
    private static function provider(): SumsubAdapter
    {
        return new SumsubAdapter();
    }

    /** Type de sujet selon le type de compte : entreprise → KYB. */
    private static function subjectTypeFor(array $user): KycSubjectType
    {
        return ($user['account_type'] ?? 'personal') === 'business'
            ? KycSubjectType::COMPANY
            : KycSubjectType::INDIVIDUAL;
    }

    /**
     * GET /api/kyc/status
     */
    public static function status(Request $request): void
    {
        $request  = AuthMiddleware::handle($request);
        $user     = $request->attribute('user');
        $provider = self::provider();

        $type = self::subjectTypeFor($user);
        $status = $provider->isConfigured()
            ? KycService::syncFromProvider(
                Database::getConnection(),
                $provider,
                (int) $user['id'],
                $type
            )
            : KycService::statusFor(
                Database::getConnection(),
                $provider,
                (int) $user['id'],
                $type
            ) + ['account' => null];

        // Projection du flag KYB distinct pour les comptes Business : c'est
        // `users.kyb_status` (et non le statut du dossier) que le Policy Engine
        // consulte pour bloquer/débloquer les paiements.
        $isBusiness = ($user['account_type'] ?? 'personal') === 'business';
        if ($isBusiness) {
            $acct = is_array($status['account'] ?? null) ? $status['account'] : [];
            $status['kyb_status'] = $acct['kyb_status'] ?? $user['kyb_status'] ?? 'none';
            $status['kyb_verified_at'] = $acct['kyb_verified_at'] ?? $user['kyb_verified_at'] ?? null;
            // Niveau de risque KYB (approche basée sur le risque, FATF).
            $status['risk_level'] = $user['risk_level'] ?? null;

            // Si Sumsub n'a pas de dossier company mais le registre a vérifié,
            // aligne le statut exposé sur users.kyb_status.
            if (($status['kyb_status'] ?? '') === 'verified' && ($status['status'] ?? '') !== 'verified') {
                $status['status'] = 'verified';
                $status['required_action'] = 'none';
                $status['provider'] = 'opencorporates';
            }

            $pdo = Database::getConnection();
            $profile = KycService::verificationProfile($pdo, (int) $user['id']);
            $status['company_profile'] = [
                'company_name'                  => $profile['company_name'] ?? null,
                'company_registration_number'   => $profile['company_registration_number'] ?? null,
                'country_of_residence'          => $profile['country_of_residence'] ?? null,
                'jurisdiction_code'             => CompanyRegistryService::jurisdictionFromCountry(
                    (string) ($profile['country_of_residence'] ?? '')
                ),
            ];
        }

        $registry = new CompanyRegistryService();
        $sumsubConfigured = $provider->isConfigured();
        // Compte business : « configured » si Sumsub OU registre OC disponible.
        $configured = $sumsubConfigured || ($isBusiness && $registry->isConfigured());

        // §32 : statut, action attendue, type — jamais de secret ni de document.
        Response::success($status + [
            'configured'         => $configured,
            'sumsub_configured'  => $sumsubConfigured,
            'registry'           => [
                'available'  => $registry->isConfigured(),
                'provider'   => 'opencorporates',
                'preferred'  => $isBusiness && $registry->isFallbackPreferred(),
            ],
        ]);
    }

    /**
     * POST /api/kyc/session
     * Crée/reprend l'applicant et renvoie un token SDK à durée de vie courte.
     */
    public static function session(Request $request): void
    {
        $request  = AuthMiddleware::handle($request);
        $user     = $request->attribute('user');
        $provider = self::provider();

        if (!$provider->isConfigured()) {
            // Honnêteté (§37) : on ne simule pas une session de vérification.
            Response::error('Vérification d\'identité indisponible : provider KYC non configuré.', 503, 'KYC_PROVIDER_NOT_CONFIGURED');
        }

        $pdo = Database::getConnection();
        $dbUser = KycService::verificationProfile($pdo, (int) $user['id']);
        $profile = [
            'email' => $dbUser['email'] ?? $user['email'] ?? null,
            'phone' => $dbUser['phone'] ?? $user['phone'] ?? null,
        ];

        // KYB : données entreprise collectées à l'inscription (users.*).
        if (self::subjectTypeFor($user)->isCompany()) {
            $profile = array_merge($profile, [
                'company_name'        => $dbUser['company_name'] ?? null,
                'registration_number' => $dbUser['company_registration_number'] ?? null,
                'country'             => $dbUser['country_of_residence'] ?? null,
            ]);

            // Approche basée sur le risque : level low/medium/high persisté
            // avant le démarrage (déterministe, auditable).
            if ($dbUser !== []) {
                KycService::persistRiskLevel($pdo, (int) $user['id'], $dbUser);
            }
        } else {
            // KYC : identité individuelle pour fixedInfo Sumsub.
            $profile = array_merge($profile, [
                'full_name'  => $dbUser['full_name'] ?? $user['full_name'] ?? null,
                'birth_date' => $dbUser['birth_date'] ?? null,
                'country'    => $dbUser['country_of_residence'] ?? null,
                'gender'     => $dbUser['gender'] ?? null,
            ]);
        }

        try {
            $session = KycService::startVerification(
                $pdo,
                $provider,
                (int) $user['id'],
                self::subjectTypeFor($user),
                $profile
            );
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            // Message actionnable : le cas le plus fréquent en Business est un
            // niveau KYB Sumsub manquant (les clés KYC peuvent déjà marcher).
            if (str_contains($msg, 'SUMSUB_LEVEL_NAME_KYB') || str_contains($msg, 'level_name_kyb')) {
                Response::error(
                    'Vérification entreprise indisponible : configurez le niveau KYB Sumsub (level_name_kyb / SUMSUB_LEVEL_NAME_KYB) dans les credentials provider.',
                    503,
                    'KYC_KYB_LEVEL_NOT_CONFIGURED'
                );
                return;
            }
            error_log('[NEXUS KYC] session failed: ' . $msg);
            Response::error('Impossible de démarrer la vérification.', 502, 'KYC_SESSION_FAILED');
            return;
        }

        // Seul le token court est transmis au client — jamais la clé secrète.
        Response::success([
            'token'        => $session['token'],
            'expires_in'   => $session['expires_in'],
            'environment'  => $provider->environment(),
            'provider'     => $provider->slug(),
        ]);
    }

    /**
     * POST /api/kyc/webhook  (route PUBLIQUE — authentifiée par signature)
     *
     * Séquence imposée (§24) :
     *   Receive → Verify signature → Verify provider/environment
     *   → Extract event ID → Idempotency check → Persist → Update → Policy
     */
    public static function webhook(Request $request): void
    {
        $provider = self::provider();

        // 1) Corps BRUT : le HMAC porte sur les octets exacts reçus.
        $raw = $request->rawBody();

        // 2) Signature AVANT toute interprétation du contenu.
        if (!$provider->verifyWebhookSignature($raw, $request->headers())) {
            // Aucun détail : ne pas aider un attaquant à ajuster sa signature.
            Response::error('Signature de webhook invalide.', 401, 'INVALID_WEBHOOK_SIGNATURE');
            return;
        }

        try {
            $event = $provider->parseWebhook($raw);
        } catch (RuntimeException $e) {
            Response::error('Payload de webhook invalide.', 400, 'INVALID_WEBHOOK_PAYLOAD');
            return;
        }

        // 3) L'environnement de l'événement doit correspondre au nôtre.
        if ($event->environment !== $provider->environment()) {
            Response::error('Environnement de webhook incohérent.', 409, 'WEBHOOK_ENVIRONMENT_MISMATCH');
            return;
        }

        // 4) Idempotence + application de l'état.
        $result = KycService::handleVerifiedWebhook(Database::getConnection(), $event);

        // Un rejeu répond 200 : le provider ne doit pas réessayer indéfiniment.
        Response::success([
            'received'  => true,
            'processed' => $result['processed'],
            'duplicate' => $result['duplicate'],
        ]);
    }
}
