<?php

declare(strict_types=1);

namespace Nexus\Services;

use PDO;
use Throwable;

/**
 * REPRISE DES PAIEMENTS BLOQUÉS EN « executing ».
 *
 * ─── LE DÉFAUT CORRIGÉ ────────────────────────────────────────────────────
 * `PaymentController::execute` réserve le paiement (`status = 'executing'`)
 * hors transaction SQL, puis lance la saga. Son bloc `catch` rattrape les
 * exceptions — mais pas un arrêt brutal du processus (OOM, timeout, kill,
 * redéploiement). Le paiement restait alors « executing » DÉFINITIVEMENT :
 * inexécutable (`execute` exige `approved`), inannulable (`cancel` part de
 * `draft`), et compté à jamais dans les payables du tableau de bord.
 *
 * ─── CE QUE CE SERVICE NE FAIT PAS ────────────────────────────────────────
 * Il ne rejoue AUCUNE opération financière. Il ne devine RIEN. Il n'invente
 * aucune règle métier — notamment, il ne décide jamais qu'un paiement « a
 * probablement réussi ».
 *
 * ─── CE QU'IL FAIT ────────────────────────────────────────────────────────
 * Il lit un fait déjà écrit. La clé d'idempotence d'un paiement est
 * DÉTERMINISTE (`BusinessService::executePayment`) :
 *
 *     payment:{id}:execute
 *
 * La table `idempotency_keys` constitue donc le journal d'exécution de la
 * saga. Le balayage se contente de réconcilier le statut du paiement avec ce
 * journal :
 *
 *   clé « completed »  → la saga a abouti, l'argent est parti  → completed
 *   clé « error »      → la saga a échoué proprement           → failed
 *   clé « processing » → issue INCONNUE                        → on ne touche à rien
 *   aucune clé         → la saga n'a jamais démarré            → approved
 *
 * Le cas « processing » est le plus important : c'est précisément celui où un
 * balayage naïf ferait des dégâts, en tranchant le sort d'un transfert
 * peut-être encore vivant. Face à l'incertitude, ce service s'abstient — et
 * le paiement reste visible comme anomalie plutôt que d'être faussement
 * résolu.
 *
 * ─── DOUBLE PROTECTION CONTRE LA REPRISE PRÉMATURÉE ──────────────────────
 * 1. Ancienneté : seuls les paiements immobiles depuis plus de N secondes
 *    sont examinés.
 * 2. Journal : une saga « processing » est laissée intacte quel que soit son
 *    âge.
 *
 * ─── CE QUI RESTE OUVERT (non simulé) ─────────────────────────────────────
 * Un paiement « processing » depuis très longtemps signale une saga morte en
 * plein vol. Statuer sur son sort exige d'interroger le provider (l'argent
 * est-il parti ?). Les opérations métier des adapters n'étant pas
 * implémentées, cette réconciliation est BLOQUÉE sur dépendance externe :
 * elle est signalée (`skipped_in_progress`), jamais devinée.
 */
final class PaymentRecoveryService
{
    /**
     * Délai par défaut avant qu'un paiement « executing » soit considéré
     * comme immobile. Une heure : très au-delà de toute exécution normale,
     * afin de ne jamais concurrencer une saga vivante.
     */
    public const DEFAULT_STALE_SECONDS = 3600;

    /** Statut de départ : le seul état réellement bloquant. */
    private const STUCK_STATUS = 'executing';

    /**
     * Réconcilie les paiements immobiles avec le journal d'exécution.
     *
     * L'opération est idempotente : un second passage ne trouve plus rien à
     * reprendre, puisque chaque paiement traité quitte l'état « executing ».
     *
     * @param  int $staleSeconds Ancienneté minimale, en secondes.
     * @return array{completed:int,failed:int,reset:int,skipped_in_progress:int,examined:int,details:array<int,array<string,mixed>>}
     */
    public static function sweep(PDO $pdo, int $staleSeconds = self::DEFAULT_STALE_SECONDS): array
    {
        $staleSeconds = max(0, $staleSeconds);

        $report = [
            'completed'           => 0,
            'failed'              => 0,
            'reset'               => 0,
            'skipped_in_progress' => 0,
            'examined'            => 0,
            'details'             => [],
        ];

        // Le journal est joint au paiement : une seule lecture, pas de
        // fenêtre entre « je liste » et « je décide ».
        $stmt = $pdo->prepare(
            "SELECT p.id, p.user_id, p.environment, k.status AS idem_status, k.response_json
               FROM payments p
               LEFT JOIN idempotency_keys k
                      ON k.idempotency_key = CONCAT('payment:', p.id, ':execute')
                     AND k.user_id = p.user_id
              WHERE p.status = :stuck
                AND p.updated_at < DATE_SUB(NOW(), INTERVAL :secs SECOND)
              ORDER BY p.id"
        );
        $stmt->bindValue('stuck', self::STUCK_STATUS);
        $stmt->bindValue('secs', $staleSeconds, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $report['examined']++;

            $paymentId  = (int) $row['id'];
            $userId     = (int) $row['user_id'];
            $idemStatus = $row['idem_status'] !== null ? (string) $row['idem_status'] : null;

            // ── Saga toujours en cours : l'issue est inconnue. ────────────
            // Trancher ici reviendrait à inventer un fait financier.
            if ($idemStatus === 'processing') {
                $report['skipped_in_progress']++;
                $report['details'][] = [
                    'payment_id' => $paymentId,
                    'outcome'    => 'skipped_in_progress',
                    'reason'     => 'Saga en cours : issue inconnue, réconciliation provider requise.',
                ];
                continue;
            }

            [$newStatus, $outcome, $reason] = match ($idemStatus) {
                'completed' => ['completed', 'completed', 'Saga aboutie : le mouvement financier a été enregistré.'],
                'error'     => ['failed', 'failed', 'Saga en échec : aucun mouvement financier abouti.'],
                default     => ['approved', 'reset', 'Saga jamais démarrée : le paiement redevient exécutable.'],
            };

            // La transition est conditionnée sur l'état de départ : si un
            // autre processus a repris le paiement entre-temps, on n'écrase
            // pas sa décision.
            $upd = $pdo->prepare(
                "UPDATE payments SET status = :new WHERE id = :id AND status = :stuck"
            );
            $upd->execute(['new' => $newStatus, 'id' => $paymentId, 'stuck' => self::STUCK_STATUS]);

            if ($upd->rowCount() === 0) {
                continue; // Déjà repris par un concurrent : rien à signaler.
            }

            // Un paiement complété doit porter la transaction produite par la
            // saga, sans quoi la piste d'audit serait rompue.
            if ($newStatus === 'completed') {
                $txId = self::transactionIdFrom($row['response_json'] ?? null);
                if ($txId > 0) {
                    $link = $pdo->prepare(
                        'UPDATE payments SET transaction_id = :tx, executed_at = COALESCE(executed_at, NOW())
                          WHERE id = :id AND transaction_id IS NULL'
                    );
                    $link->execute(['tx' => $txId, 'id' => $paymentId]);
                }
            }

            $report[$outcome]++;
            $report['details'][] = [
                'payment_id' => $paymentId,
                'outcome'    => $outcome,
                'reason'     => $reason,
            ];

            self::audit($pdo, $userId, $paymentId, (string) $row['environment'], $outcome, $reason);
        }

        return $report;
    }

    /**
     * Extrait l'identifiant de transaction de la réponse mémorisée.
     *
     * Aucune supposition : si la réponse ne porte pas d'identifiant
     * exploitable, on ne fabrique pas de lien.
     */
    private static function transactionIdFrom(mixed $responseJson): int
    {
        if (!is_string($responseJson) || $responseJson === '') {
            return 0;
        }

        $decoded = json_decode($responseJson, true);
        if (!is_array($decoded)) {
            return 0;
        }

        foreach ([$decoded['id'] ?? null, $decoded['transaction']['id'] ?? null] as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    /**
     * Trace la reprise. Un changement de statut automatique sans trace serait
     * inexplicable a posteriori — donc inacceptable sur un système financier.
     *
     * L'audit ne doit jamais faire échouer la reprise elle-même : le statut
     * corrigé est le fait principal.
     */
    private static function audit(
        PDO $pdo,
        int $userId,
        int $paymentId,
        string $environment,
        string $outcome,
        string $reason
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, environment, metadata)
                 VALUES (:u, :a, :et, :ei, :env, :meta)'
            );
            $stmt->execute([
                'u'    => $userId,
                'a'    => 'payment.recovery.' . $outcome,
                'et'   => 'payment',
                'ei'   => $paymentId,
                'env'  => $environment !== '' ? $environment : 'sandbox',
                'meta' => json_encode([
                    'reason'    => $reason,
                    'recovered' => true,
                    'source'    => 'PaymentRecoveryService',
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            // Volontairement silencieux : voir le commentaire ci-dessus.
        }
    }
}
