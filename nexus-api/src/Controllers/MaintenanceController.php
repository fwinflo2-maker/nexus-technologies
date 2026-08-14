<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\PlatformRole;
use Nexus\Services\PaymentRecoveryService;
use PDO;

/**
 * MAINTENANCE D'EXPLOITATION — réparation d'états bloqués (§6).
 *
 * Routes :
 *   GET  /api/control/maintenance/stuck-payments  → diagnostic (lecture seule)
 *   POST /api/control/maintenance/recover-payments → reprise (écriture)
 *
 * ─── DEUX CAPACITÉS DISTINCTES, VOLONTAIREMENT ────────────────────────────
 * Le diagnostic est ouvert à la capacité `operations` : constater une anomalie
 * est le travail normal du support et de la supervision.
 *
 * La réparation exige la capacité `maintenance` (superadmin, SRE). Elle change
 * le statut de paiements réels : c'est une opération d'exploitation, pas une
 * consultation.
 *
 * Séparer les deux évite le glissement classique où « voir » finit par
 * emporter « agir ».
 *
 * ─── CONFIRMATION EXPLICITE ───────────────────────────────────────────────
 * La réparation exige `confirm: true` dans le corps de la requête. Cela rend
 * impossible une modification déclenchée par un simple appel accidentel, un
 * outil de test, ou une exploration d'API.
 *
 * ─── AUCUN POUVOIR SPÉCIAL ────────────────────────────────────────────────
 * Ces routes ne contournent rien : ni l'idempotence, ni le ledger, ni la
 * séparation sandbox/production. La reprise ne rejoue aucune opération
 * financière — elle réconcilie un statut avec un fait déjà écrit
 * (cf. PaymentRecoveryService).
 */
final class MaintenanceController
{
    /**
     * Plancher de sécurité sur le délai d'immobilité.
     *
     * Sans plancher, un opérateur pourrait passer `stale_seconds = 0` et
     * balayer des sagas démarrées la seconde précédente. Cinq minutes restent
     * très au-dessus d'une exécution normale.
     */
    private const MIN_STALE_SECONDS = 300;

    private static function authorize(Request $request, string $capability): array
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');

        PlatformRole::require($user, $capability);

        return $user;
    }

    /**
     * GET /api/control/maintenance/stuck-payments
     *
     * Diagnostic en lecture seule : aucun statut n'est modifié.
     */
    public static function stuckPayments(Request $request): void
    {
        self::authorize($request, 'operations');

        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT p.id, p.user_id, p.environment, p.source_currency, p.amount, p.updated_at,
                    COALESCE(k.status, 'never_started') AS saga_status,
                    TIMESTAMPDIFF(SECOND, p.updated_at, NOW()) AS stuck_seconds
               FROM payments p
               LEFT JOIN idempotency_keys k
                      ON k.idempotency_key = CONCAT('payment:', p.id, ':execute')
                     AND k.user_id = p.user_id
              WHERE p.status = 'executing'
              ORDER BY p.updated_at ASC
              LIMIT 200"
        );

        $rows  = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $items = [];

        foreach ($rows as $row) {
            $saga = (string) $row['saga_status'];

            // L'action recommandée est déduite du journal d'exécution, jamais
            // devinée. « processing » reste explicitement non tranchable.
            $recommendation = match ($saga) {
                'completed'  => 'recoverable_completed',
                'error'      => 'recoverable_failed',
                'processing' => 'requires_provider_reconciliation',
                default      => 'recoverable_reset',
            };

            $items[] = [
                'payment_id'     => (int) $row['id'],
                'environment'    => (string) $row['environment'],
                'currency'       => (string) $row['source_currency'],
                'amount'         => (string) $row['amount'],
                'stuck_seconds'  => (int) $row['stuck_seconds'],
                'saga_status'    => $saga,
                'recommendation' => $recommendation,
            ];
        }

        Response::success([
            'stuck_payments' => $items,
            'count'          => count($items),
            'note'           => 'Diagnostic en lecture seule. Aucun statut modifié.',
        ]);
    }

    /**
     * POST /api/control/maintenance/recover-payments
     *
     * Corps : { "confirm": true, "stale_seconds": 3600 }
     */
    public static function recoverPayments(Request $request): void
    {
        $user = self::authorize($request, 'maintenance');

        $body = $request->body();

        if (($body['confirm'] ?? false) !== true) {
            Response::error(
                'Cette opération modifie des paiements réels : renvoyez « confirm: true » pour la déclencher.',
                400,
                'CONFIRMATION_REQUIRED'
            );
        }

        $stale = isset($body['stale_seconds'])
            ? (int) $body['stale_seconds']
            : PaymentRecoveryService::DEFAULT_STALE_SECONDS;

        if ($stale < self::MIN_STALE_SECONDS) {
            Response::error(
                sprintf(
                    'Délai d\'immobilité trop court (minimum %d s) : une saga encore active pourrait être interrompue.',
                    self::MIN_STALE_SECONDS
                ),
                422,
                'STALE_WINDOW_TOO_SHORT'
            );
        }

        $pdo    = Database::getConnection();
        $report = PaymentRecoveryService::sweep($pdo, $stale);

        // Trace de l'ACTEUR. Le service audite chaque paiement repris ; cette
        // entrée-ci enregistre qui a déclenché le balayage, et avec quels
        // paramètres.
        $audit = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, environment, metadata)
             VALUES (:u, :a, :et, :ei, :env, :meta)'
        );
        $audit->execute([
            'u'    => (int) $user['id'],
            'a'    => 'maintenance.recover_payments',
            'et'   => 'maintenance',
            'ei'   => 0,
            'env'  => 'sandbox',
            'meta' => json_encode([
                'actor_id'      => (int) $user['id'],
                'platform_role' => PlatformRole::of($user),
                'stale_seconds' => $stale,
                'examined'      => $report['examined'],
                'completed'     => $report['completed'],
                'failed'        => $report['failed'],
                'reset'         => $report['reset'],
                'skipped'       => $report['skipped_in_progress'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        Response::success([
            'report' => $report,
            'note'   => $report['skipped_in_progress'] > 0
                ? 'Certains paiements ont une saga encore « en cours » : leur issue exige une réconciliation auprès du provider et n\'a pas été devinée.'
                : 'Tous les paiements immobiles ont été réconciliés avec le journal d\'exécution.',
        ]);
    }
}
