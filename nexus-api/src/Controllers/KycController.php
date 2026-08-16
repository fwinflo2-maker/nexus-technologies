<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\SumsubAdapter;
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

        $status = KycService::statusFor(
            Database::getConnection(),
            $provider,
            (int) $user['id'],
            self::subjectTypeFor($user)
        );

        // Projection du flag KYB distinct pour les comptes Business : c'est
        // `users.kyb_status` (et non le statut du dossier) que le Policy Engine
        // consulte pour bloquer/débloquer les paiements.
        if (($user['account_type'] ?? 'personal') === 'business') {
            $status['kyb_status'] = $user['kyb_status'] ?? 'none';
            $status['kyb_verified_at'] = $user['kyb_verified_at'] ?? null;
            // Niveau de risque KYB (approche basée sur le risque, FATF).
            $status['risk_level'] = $user['risk_level'] ?? null;
        }

        // §32 : statut, action attendue, type — jamais de secret ni de document.
        Response::success($status + ['configured' => $provider->isConfigured()]);
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
        $profile = ['email' => $user['email'] ?? null, 'phone' => $user['phone'] ?? null];

        // KYB : on transmet les données d'identification de l'entreprise,
        // collectées à l'inscription (users.company_name, etc.), pour permettre
        // le contrôle registre Sumsub. On évalue aussi le niveau de risque.
        if (self::subjectTypeFor($user)->isCompany()) {
            $profile = array_merge($profile, [
                'company_name'         => $user['company_name'] ?? null,
                'registration_number'  => $user['company_registration_number'] ?? null,
                'country'              => $user['country_of_residence'] ?? null,
            ]);

            // Approche basée sur le risque : level low/medium/high persisté
            // avant le démarrage (déterministe, auditable).
            KycService::persistRiskLevel($pdo, (int) $user['id'], $user);
        } else {
            // KYC : on pré-remplit les données d'identité individuelles pour la
            // vérification croisée (fixedInfo firstName/lastName/dob/country).
            $profile = array_merge($profile, [
                'full_name'  => $user['full_name'] ?? null,
                'birth_date' => $user['birth_date'] ?? null,
                'country'    => $user['country_of_residence'] ?? null,
                'gender'     => $user['gender'] ?? null,
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
