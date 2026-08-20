<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Execution\ExecutionContext;
use Nexus\Core\Response;
use Nexus\Services\FXService;
use Nexus\Services\WalletService;

/**
 * Dashboard : agrégats et vue d'ensemble branchés sur MySQL.
 *
 *  - GET /api/dashboard/summary  → soldes, KPIs, transactions récentes, bannière.
 *  - GET /api/dashboard/activity → série temporelle (7j / 30j / 12 mois).
 *
 * Toutes les valeurs sont calculées côté serveur depuis les tables
 * `wallets` et `transactions` — aucune valeur n'est codée en dur.
 */
final class DashboardController
{
    /** Nombre de transactions récentes renvoyées par le résumé. */
    private const RECENT_LIMIT = 6;

    /** Périodes acceptées par l'endpoint d'activité. */
    private const PERIODS = ['7d', '30d', '12m'];

    /**
     * GET /api/dashboard/summary
     *
     * Retourne l'ensemble des données de la vue d'ensemble :
     *  - user (statut, niveau KYC, type de compte) ;
     *  - wallets (6 devises, soldes par état, équivalent ref) ;
     *  - totaux (ref_currency) ;
     *  - KPIs agrégés depuis `transactions` ;
     *  - dernières transactions ;
     *  - bannière intelligente (kyc / limites / corridor).
     */
    public static function summary(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        // §21 — un total financier ne doit jamais additionner de l'argent
        // réel et des montants de test. Le périmètre suit le contexte.
        $context = ExecutionContext::fromRequest($request, $user);

        $pdo = Database::getConnection();
        $ref = Currency::REF;

        // --- Wallets (6 devises, zéro rempli si absente) ----------------------
        // Utilisation de WalletService au lieu de requête SQL directe
        $balances = WalletService::getAllBalances($userId);
        $byCurrency = [];
        foreach ($balances as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        $wallets            = [];
        $totalRef           = 0.0;
        $totalAvailableRef  = 0.0;
        $currenciesWithFunds = 0;

        foreach (Currency::WALLET_CURRENCIES as $currency) {
            $wallet = $byCurrency[$currency] ?? null;

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
                $totalRef          += $balance / $rateRef;
                $totalAvailableRef += $available / $rateRef;
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

        // --- KPIs agrégés depuis `transactions` -------------------------------
        $kpis = self::kpis($pdo, $userId, $context->environmentValue());

        // --- Dernières transactions -------------------------------------------
        $stmt = $pdo->prepare(
            'SELECT id, type, direction, label, description, amount, currency,
                    amount_ref, ref_currency, amount_xaf, status, provider, destination,
                    execution_time_seconds, created_at
             FROM transactions
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::RECENT_LIMIT
        );
        $stmt->execute(['uid' => $userId]);
        $recent = array_map(
            static fn (array $tx): array => [
                'id'          => (int) $tx['id'],
                'type'        => $tx['type'],
                'direction'   => $tx['direction'],
                'label'       => $tx['label'],
                'description' => $tx['description'],
                'amount'      => round((float) $tx['amount'], 2),
                'currency'    => $tx['currency'],
                'amount_ref'  => round((float) $tx['amount_ref'], 2),
                'amount_xaf'  => round((float) $tx['amount_xaf'], 2),
                'status'      => $tx['status'],
                'provider'    => $tx['provider'],
                'destination' => $tx['destination'],
                'created_at'  => self::toIso8601($tx['created_at']),
            ],
            $stmt->fetchAll()
        );

        Response::success([
            'ref_currency'      => $ref,
            'user'              => [
                'id'           => (int) $user['id'],
                'full_name'    => $user['full_name'],
                'account_type' => $user['account_type'],
                'status'       => $user['status'],
                'kyc_level'    => $user['kyc_level'],
            ],
            'wallets'           => $wallets,
            'totals'            => [
                'ref_currency'    => $ref,
                'total_ref'       => round($totalRef, 2),
                'available_ref'   => round($totalAvailableRef, 2),
                'currencies'      => count(Currency::WALLET_CURRENCIES),
                'with_funds'      => $currenciesWithFunds,
            ],
            'kpis'              => $kpis,
            'recent'            => $recent,
            'banner'            => self::banner($user, $totalAvailableRef),
        ]);
    }

    /**
     * GET /api/dashboard/activity?period=30d
     *
     * Série temporelle volume (EUR, devise de référence) + nombre de
     * transactions, pour les périodes 7j, 30j ou 12 mois.
     */
    public static function activity(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $period = strtolower((string) $request->query('period', '30d'));
        if (!in_array($period, self::PERIODS, true)) {
            $period = '30d';
        }

        $context = ExecutionContext::fromRequest($request, $user);
        $pdo     = Database::getConnection();
        $series  = [];
        $default = static function (string $label): array {
            return ['label' => $label, 'volume' => 0.0, 'count' => 0];
        };

        if ($period === '12m') {
            // --- 12 derniers mois (inclus le mois courant) --------------------
            $since = date('Y-m-01 00:00:00', strtotime('-11 months'));

            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period_key,
                        COALESCE(SUM(amount_ref), 0) AS volume,
                        COUNT(*) AS cnt
                 FROM transactions
                 WHERE user_id = :uid AND environment = :env
                   AND status <> 'cancelled' AND created_at >= :since
                 GROUP BY period_key
                 ORDER BY period_key ASC"
            );
            $stmt->execute(['uid' => $userId, 'env' => $context->environmentValue(), 'since' => $since]);
            $grouped = [];
            foreach ($stmt->fetchAll() as $row) {
                $grouped[$row['period_key']] = $row;
            }

            for ($i = 11; $i >= 0; $i--) {
                $key   = date('Y-m', strtotime("-{$i} months"));
                $label = self::frenchMonth((int) date('n', strtotime($key . '-01'))) . ' ' . date('Y', strtotime($key . '-01'));
                $point = $default($label);
                if (isset($grouped[$key])) {
                    $point['volume'] = round((float) $grouped[$key]['volume'], 2);
                    $point['count']  = (int) $grouped[$key]['cnt'];
                }
                $series[] = $point;
            }
        } else {
            // --- 7 ou 30 derniers jours ---------------------------------------
            $days  = $period === '7d' ? 7 : 30;
            $since = date('Y-m-d 00:00:00', strtotime("-{$days} days +1 day"));

            $stmt = $pdo->prepare(
                'SELECT DATE(created_at) AS period_key,
                        COALESCE(SUM(amount_ref), 0) AS volume,
                        COUNT(*) AS cnt
                 FROM transactions
                 WHERE user_id = :uid AND environment = :env
                   AND status <> \'cancelled\' AND created_at >= :since
                 GROUP BY period_key
                 ORDER BY period_key ASC'
            );
            $stmt->execute(['uid' => $userId, 'env' => $context->environmentValue(), 'since' => $since]);
            $grouped = [];
            foreach ($stmt->fetchAll() as $row) {
                $grouped[$row['period_key']] = $row;
            }

            for ($i = $days - 1; $i >= 0; $i--) {
                $key   = date('Y-m-d', strtotime("-{$i} days"));
                $label = self::frenchDay($key);
                $point = $default($label);
                if (isset($grouped[$key])) {
                    $point['volume'] = round((float) $grouped[$key]['volume'], 2);
                    $point['count']  = (int) $grouped[$key]['cnt'];
                }
                $series[] = $point;
            }
        }

        Response::success([
            'period'       => $period,
            'ref_currency' => Currency::REF,
            'series'       => $series,
        ]);
    }

    // --- Helpers privés ---------------------------------------------------------

    /**
     * Calcule les KPIs du dashboard depuis la table `transactions`.
     *
     * @return array{
     *     transactions_month: int,
     *     volume_xaf: float,
     *     success_rate: float,
     *     avg_exec_time_sec: ?int,
     *     fees_total_ref: float
     * }
     */
    private static function kpis(\PDO $pdo, int $userId, string $environment): array
    {
        $since30d = date('Y-m-d 00:00:00', strtotime('-29 days'));

        // Transactions du mois civil courant.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM transactions
             WHERE user_id = :uid AND environment = :env
               AND created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')'
        );
        $stmt->execute(['uid' => $userId, 'env' => $environment]);
        $transactionsMonth = (int) $stmt->fetchColumn();

        // Volume total (équivalent XAF), 30 derniers jours.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_xaf), 0) FROM transactions
             WHERE user_id = :uid AND environment = :env
               AND status <> \'cancelled\' AND created_at >= :since'
        );
        $stmt->execute(['uid' => $userId, 'env' => $environment, 'since' => $since30d]);
        $volumeXaf = round((float) $stmt->fetchColumn(), 2);

        // Taux de réussite (complétées / exécutées), 30 derniers jours.
        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(status = \'completed\'), 0)  AS completed,
                COALESCE(SUM(status = \'failed\'), 0)     AS failed,
                COALESCE(SUM(status = \'cancelled\'), 0)  AS cancelled
             FROM transactions
             WHERE user_id = :uid AND environment = :env AND created_at >= :since'
        );
        $stmt->execute(['uid' => $userId, 'env' => $environment, 'since' => $since30d]);
        $counts      = $stmt->fetch();
        $executed    = (int) $counts['completed'] + (int) $counts['failed'] + (int) $counts['cancelled'];
        $successRate = $executed > 0
            ? round(((int) $counts['completed'] / $executed) * 100, 1)
            : 0.0;

        // Temps moyen d'exécution (secondes), transactions complétées, 30 jours.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(AVG(execution_time_seconds), 0) FROM transactions
             WHERE user_id = :uid AND status = \'completed\'
               AND execution_time_seconds IS NOT NULL AND created_at >= :since'
        );
        $stmt->execute(['uid' => $userId, 'since' => $since30d]);
        $avgExecSeconds = (float) $stmt->fetchColumn();

        // Frais totaux (équivalent ref EUR), 30 derniers jours.
        // Taux RÉEL par devise ; sans taux, le frais ne contribue pas au
        // total (jamais de conversion inventée, §7).
        $execEnv = \Nexus\Execution\ExecutionEnvironment::fromString($environment);
        $stmt = $pdo->prepare(
            'SELECT fee, fee_currency FROM transactions
             WHERE user_id = :uid AND status <> \'cancelled\' AND created_at >= :since'
        );
        $stmt->execute(['uid' => $userId, 'since' => $since30d]);
        $feesRef = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $rate = FXService::rateToRef((string) $row['fee_currency'], $execEnv);
            if ($rate !== null && $rate > 0.0) {
                $feesRef += (float) $row['fee'] / $rate;
            }
        }

        return [
            'transactions_month' => $transactionsMonth,
            'volume_xaf'         => $volumeXaf,
            'success_rate'       => $successRate,
            'avg_exec_time_sec'  => $avgExecSeconds > 0 ? (int) round($avgExecSeconds) : null,
            'fees_total_ref'     => round($feesRef, 2),
        ];
    }

    /**
     * Détermine la bannière intelligente du dashboard.
     *
     * Priorité :
     *  1. Compte PENDING          → inviter à la vérification d'identité (KYC/KYB) ;
     *  2. Compte ACTIVE mais KYC faible ou compte restreint → expliquer les limites ;
     *  3. Portefeuille vide       → suggérer le corridor EUR → XAF (MVP).
     *
     * @return array{type: ?string, title: string, message: string, action: ?string, reason?: ?string, href?: ?string}
     */
    private static function banner(array $user, float $totalAvailableRef): array
    {
        $status   = (string) $user['status'];
        $kycLevel = (string) $user['kyc_level'];
        $isBiz    = ($user['account_type'] ?? '') === 'business';

        // 1. Vérification d'identité requise.
        if ($status === 'PENDING') {
            return [
                'type'    => 'kyc',
                'href'    => '/kyc',
                'title'   => $isBiz ? 'Vérification d\'entreprise requise' : 'Vérification d\'identité requise',
                'message' => $isBiz
                    ? 'Complétez la procédure KYB pour activer les corridors, relever vos plafonds et débloquer les paiements fournisseurs.'
                    : 'Complétez votre KYC pour débloquer tous les corridors (SEPA, Mobile Money, FX) et relever vos plafonds de transaction.',
                'action'  => $isBiz ? 'Vérifier mon entreprise' : 'Vérifier mon identité',
            ];
        }

        // 2. Compte restreint (suspendu / fermé) vs plafonds KYC bas.
        $restricted = in_array($status, ['SUSPENDED', 'CLOSED'], true);
        $lowKyc     = in_array($kycLevel, ['none', 'basic'], true);
        if ($restricted) {
            return [
                'type'    => 'limits',
                'reason'  => 'restricted',
                'href'    => '/support',
                'title'   => 'Compte restreint',
                'message' => 'Votre compte est actuellement restreint. Contactez le support NEXUS pour clarifier votre situation avant tout nouvel envoi.',
                'action'  => 'Contacter le support',
            ];
        }
        if ($lowKyc) {
            return [
                'type'    => 'limits',
                'reason'  => 'low_kyc',
                'href'    => '/kyc',
                'title'   => 'Limites de compte actives',
                'message' => 'Votre compte dispose de plafonds limités (montants et volume mensuels) tant que votre vérification n\'est pas finalisée. Relevez vos limites dès maintenant.',
                'action'  => 'Relever mes limites',
            ];
        }

        // 3. Portefeuille vide → corridor recommandé.
        if ($totalAvailableRef <= 0.0) {
            return [
                'type'    => 'corridor',
                'href'    => '/wallet?fund=1',
                'title'   => 'Votre portefeuille est vide',
                'message' => 'Rechargez votre wallet EUR pour démarrer : le corridor EUR → XAF vers Mobile Money est le chemin recommandé pour votre premier envoi (MVP).',
                'action'  => 'Recharger mon wallet',
            ];
        }

        return [
            'type'    => null,
            'title'   => '',
            'message' => '',
            'action'  => null,
        ];
    }

    /** Convertit une date MySQL en ISO 8601 avec fuseau (base en UTC). */
    private static function toIso8601(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }

    /** Libellé français court d'un jour (ex. "5 août"). */
    private static function frenchDay(string $mysqlDate): string
    {
        $day   = (int) date('j', strtotime($mysqlDate));
        $month = self::frenchMonth((int) date('n', strtotime($mysqlDate)));
        return $day . ' ' . $month;
    }

    /** Nom de mois français abrégé (1 → "janv." ... 12 → "déc."). */
    private static function frenchMonth(int $month): string
    {
        return [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
            7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ][$month] ?? (string) $month;
    }
}
