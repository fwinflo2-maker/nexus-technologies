<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\BusinessService;

/**
 * BusinessController — console financière Business :
 *   - GET /api/business/overview   (actifs, KPIs, cash flow, providers)
 *   - GET /api/business/treasury   (trésorerie : balances + exposure FX)
 *   - GET /api/business/analytics  (analytics : volume, coûts, providers)
 *
 * Tous les chiffres sont calculés depuis le backend (wallets + transactions).
 */
final class BusinessController
{
    public static function overview(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter la vue d\'ensemble');

        Response::success(BusinessService::overview($bid));
    }

    public static function treasury(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter la trésorerie');

        $data = BusinessService::overview($bid);
        Response::success([
            'totals'  => $data['totals'],
            'wallets' => $data['wallets'],
        ]);
    }

    public static function analytics(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter les analytics');

        $data = BusinessService::overview($bid);
        Response::success([
            'volume'    => $data['totals'],
            'cash_flow' => $data['cash_flow'],
            'providers' => $data['providers'],
        ]);
    }
}
