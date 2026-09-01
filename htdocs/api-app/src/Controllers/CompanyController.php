<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\CompanyRegistryService;
use Nexus\Services\KycService;
use RuntimeException;

/**
 * Registre d'entreprises — OpenCorporates.
 *
 * Routes :
 *   GET  /api/companies/search
 *   GET  /api/companies/{jurisdiction}/{number}
 *   POST /api/kyb/registry/verify
 */
final class CompanyController
{
    private static function service(): CompanyRegistryService
    {
        return new CompanyRegistryService();
    }

    /** GET /api/companies/search?q=&jurisdiction_code=&country_code=&page= */
    public static function search(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $svc = self::service();
        if (!$svc->isConfigured()) {
            Response::error(
                'Registre entreprises indisponible : OPENCORPORATES_API_TOKEN manquant.',
                503,
                'OPENCORPORATES_NOT_CONFIGURED'
            );
            return;
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            Response::error('Paramètre q requis.', 422, 'VALIDATION_ERROR');
            return;
        }

        try {
            $result = $svc->search([
                'q'                 => $q,
                'jurisdiction_code' => trim((string) $request->query('jurisdiction_code', '')),
                'country_code'      => trim((string) $request->query('country_code', '')),
                'page'              => (int) $request->query('page', 1),
                'per_page'          => (int) $request->query('per_page', 10),
                'order'             => 'score',
            ]);
        } catch (RuntimeException $e) {
            self::mapError($e);
            return;
        }

        Response::success($result + ['provider' => 'opencorporates']);
    }

    /** GET /api/companies/{jurisdiction}/{number} */
    public static function show(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $svc = self::service();
        if (!$svc->isConfigured()) {
            Response::error(
                'Registre entreprises indisponible : OPENCORPORATES_API_TOKEN manquant.',
                503,
                'OPENCORPORATES_NOT_CONFIGURED'
            );
            return;
        }

        $jurisdiction = (string) $request->param('jurisdiction');
        $number = (string) $request->param('number');

        try {
            $company = $svc->getCompany($jurisdiction, $number);
        } catch (RuntimeException $e) {
            self::mapError($e);
            return;
        }

        Response::success(['company' => $company, 'provider' => 'opencorporates']);
    }

    /**
     * POST /api/kyb/registry/verify
     * Body: { jurisdiction_code, company_number }
     *
     * Match strict contre le profil business (numéro + pays) → kyb_status=verified.
     */
    public static function verify(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $svc = self::service();

        if (!$svc->isConfigured()) {
            Response::error(
                'Registre entreprises indisponible : OPENCORPORATES_API_TOKEN manquant.',
                503,
                'OPENCORPORATES_NOT_CONFIGURED'
            );
            return;
        }

        if (($user['account_type'] ?? '') !== 'business') {
            Response::error('Réservé aux comptes entreprise.', 403, 'COMPANY_REGISTRY_NOT_BUSINESS');
            return;
        }

        $jurisdiction = trim((string) $request->input('jurisdiction_code', ''));
        $number = trim((string) $request->input('company_number', ''));
        if ($jurisdiction === '' || $number === '') {
            Response::error(
                'jurisdiction_code et company_number sont requis.',
                422,
                'VALIDATION_ERROR'
            );
            return;
        }

        $pdo = Database::getConnection();
        $profile = KycService::verificationProfile($pdo, (int) $user['id']);
        if ($profile !== [] && method_exists(KycService::class, 'persistRiskLevel')) {
            KycService::persistRiskLevel($pdo, (int) $user['id'], $profile);
        }

        try {
            $result = $svc->verifyForUser(
                $pdo,
                (int) $user['id'],
                $jurisdiction,
                $number,
                $request->ipAddress()
            );
        } catch (RuntimeException $e) {
            self::mapError($e);
            return;
        }

        Response::success($result + [
            'provider' => 'opencorporates',
            'note'     => 'Match registre OpenCorporates — pas une KYB UBO/représentants complète.',
        ]);
    }

    private static function mapError(RuntimeException $e): void
    {
        $code = $e->getMessage();
        $map = [
            'OPENCORPORATES_NOT_CONFIGURED'   => [503, 'Registre entreprises non configuré.'],
            'OPENCORPORATES_AUTH_FAILED'      => [502, 'Jeton OpenCorporates refusé.'],
            'OPENCORPORATES_RATE_LIMIT'       => [429, 'Quota OpenCorporates atteint.'],
            'OPENCORPORATES_COMPANY_NOT_FOUND'=> [404, 'Entreprise introuvable au registre.'],
            'OPENCORPORATES_SEARCH_Q_REQUIRED'=> [422, 'Paramètre q requis.'],
            'COMPANY_REGISTRY_USER_NOT_FOUND' => [404, 'Utilisateur introuvable.'],
            'COMPANY_REGISTRY_NOT_BUSINESS'   => [403, 'Réservé aux comptes entreprise.'],
            'COMPANY_REGISTRY_NUMBER_MISSING' => [422, 'Numéro d\'immatriculation manquant sur le profil.'],
            'COMPANY_REGISTRY_COUNTRY_MISSING'=> [422, 'Pays de résidence manquant sur le profil.'],
            'COMPANY_REGISTRY_MISMATCH'       => [422, 'L\'entreprise ne correspond pas au profil (numéro, pays ou statut).'],
        ];

        if (isset($map[$code])) {
            [$http, $msg] = $map[$code];
            Response::error($msg, $http, $code);
            return;
        }

        if (str_starts_with($code, 'OPENCORPORATES_HTTP_')) {
            Response::error('Erreur OpenCorporates.', 502, $code);
            return;
        }

        error_log('[NEXUS OC] ' . $code);
        Response::error('Échec registre entreprises.', 502, 'OPENCORPORATES_FAILED');
    }
}
