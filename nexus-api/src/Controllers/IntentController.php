<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\FundingSourceEngine;
use Nexus\Services\IntentEngine;

/**
 * Intent Controller — endpoints pour le formulaire guidé de transfert /send.
 *
 *  - GET /api/intent/countries          → couverture DESTINATION mondiale
 *                                         (pays supportés par les providers,
 *                                          expansion EU, devises, modes de
 *                                          réception, opérateurs, taux).
 *  - GET /api/intent/authorized-origins → origines AUTORISÉES pour
 *                                         l'utilisateur connecté (calculées
 *                                         côté backend à partir des sources
 *                                         de financement vérifiées).
 *
 * La couverture destination reste centralisée côté backend (IntentEngine).
 * Les origines autorisées sont calculées par FundingSourceEngine.
 */
final class IntentController
{
    /**
     * GET /api/intent/countries
     *
     * Retourne la couverture DESTINATION complète :
     *  - pays supportés (union de tous les providers, expansion EU)
     *  - par pays : devises, modes de réception, providers
     *  - devises source (wallets de l'utilisateur)
     *  - réseaux crypto
     *  - taux de conversion de référence
     *  - pourcentage de frais estimés
     *
     * Note : cette couverture concerne les PAYS DE DESTINATION.
     * Les origines autorisées sont sur /api/intent/authorized-origins.
     */
    public static function countries(Request $request): void
    {
        AuthMiddleware::handle($request);

        $coverage = IntentEngine::coverage();

        Response::success($coverage);
    }

    /**
     * GET /api/intent/authorized-origins
     *
     * Retourne les origines autorisées pour l'utilisateur authentifié.
     *
     * Ces origines sont calculées côté backend à partir de :
     *   - sources de financement vérifiées de l'utilisateur
     *   - pays supportés par les providers
     *   - règles de conformité
     *   - restrictions géographiques
     *
     * Le frontend NE DOIT JAMAIS décider lui-même des origines disponibles.
     * Le pays de résidence KYC est inclus à titre informatif.
     */
    public static function authorizedOrigins(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $origins = FundingSourceEngine::getAuthorizedOrigins($userId, $user);

        Response::success([
            'origins'              => $origins,
            'total'                => count($origins),
            'country_of_residence' => $user['country_of_residence'] ?? null,
            'kyc_level'            => $user['kyc_level'] ?? 'none',
        ]);
    }
}
