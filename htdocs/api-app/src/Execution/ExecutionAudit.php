<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\Database;
use Nexus\Providers\SecretRedactor;
use Throwable;

/**
 * ExecutionAudit — journalisation des décisions d'environnement.
 *
 * TABLE RÉUTILISÉE
 * ────────────────
 * Écrit dans `audit_logs` (§19). Aucune seconde table d'audit n'est créée :
 * deux journaux concurrents finiraient par se contredire, et la question
 * « où est la vérité ? » n'aurait plus de réponse.
 *
 * CE QUI EST RECONSTRUCTIBLE
 * ──────────────────────────
 *     qui                → user_id (acteur)
 *     quel compte        → metadata.account_id
 *     quel provider      → metadata.provider
 *     quelle opération   → metadata.operation
 *     quel environnement → colonne environment (+ metadata)
 *     source de décision → metadata.environment_source
 *     request_id         → metadata.request_id
 *     quelle décision    → action (granted / denied) + metadata.error_code
 *
 * DEUX PUBLICS, DEUX NIVEAUX DE DÉTAIL
 * ────────────────────────────────────
 * §18 — le journal interne conserve le motif technique exact ; la réponse
 * HTTP reste générique. Un utilisateur non autorisé ne doit pas pouvoir
 * déduire du message d'erreur qu'une credential de production EXISTE : ce
 * serait lui apprendre ce qu'il n'a pas le droit de savoir.
 *
 * JAMAIS DE SECRET
 * ────────────────
 * La charge utile passe par `SecretRedactor`. Aucune valeur de
 * `secret_key`, `api_token`, `webhook_secret` ou `private_key` ne peut
 * atteindre la table.
 *
 * NON BLOQUANT
 * ────────────
 * Une panne du journal ne doit pas transformer un refus de sécurité en
 * erreur 500 — ni, pire, empêcher le refus d'être prononcé. L'écriture est
 * donc best-effort : la décision prime toujours sur sa trace.
 */
final class ExecutionAudit
{
    public const ACTION_GRANTED = 'execution.environment.granted';
    public const ACTION_DENIED  = 'execution.environment.denied';

    private function __construct()
    {
    }

    /**
     * Journalise une décision d'environnement acceptée.
     */
    public static function recordGranted(ExecutionContext $context): void
    {
        self::write(
            action:      self::ACTION_GRANTED,
            userId:      $context->actorUserId,
            environment: $context->environmentValue(),
            metadata:    $context->toArray(),
        );
    }

    /**
     * Journalise un refus de sécurité (§18).
     *
     * @param string      $errorCode   ENVIRONMENT_INVALID, ENVIRONMENT_NOT_ALLOWED,
     *                                 ENVIRONMENT_MISMATCH,
     *                                 PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT…
     * @param string|null $environment Environnement refusé, `null` si la
     *                                 demande n'était pas une valeur valide.
     * @param array<string,mixed> $details Contexte technique interne.
     */
    public static function recordDenied(
        string $errorCode,
        ?int $userId,
        ?string $environment = null,
        array $details = []
    ): void {
        self::write(
            action:      self::ACTION_DENIED,
            userId:      $userId,
            environment: self::normalizeEnvironment($environment),
            metadata:    ['error_code' => $errorCode] + $details,
        );
    }

    /**
     * Seules les valeurs valides de l'ENUM peuvent être stockées.
     *
     * Un refus `ENVIRONMENT_INVALID` porte, par définition, une demande qui
     * n'en est pas une : la colonne reste NULL et la valeur brute rejetée est
     * conservée dans `metadata`. Inventer une valeur ici reviendrait à
     * fabriquer l'information que le refus constate absente.
     */
    private static function normalizeEnvironment(?string $environment): ?string
    {
        $env = strtolower(trim((string) $environment));

        return $env === 'sandbox' || $env === 'production' ? $env : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function write(
        string $action,
        ?int $userId,
        ?string $environment,
        array $metadata
    ): void {
        try {
            $metadata['ip_address'] = null;
            unset($metadata['ip_address']);

            $safe = SecretRedactor::redactArray($metadata);

            $stmt = Database::getConnection()->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, metadata, ip_address, environment)
                 VALUES
                    (:uid, :action, :etype, NULL, :meta, :ip, :env)'
            );
            $stmt->execute([
                'uid'    => $userId !== null && $userId > 0 ? $userId : null,
                'action' => $action,
                'etype'  => 'execution_context',
                'meta'   => json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip'     => self::clientIp(),
                'env'    => $environment,
            ]);
        } catch (Throwable) {
            // Best effort : la décision de sécurité prime sur sa trace.
            // Ne jamais laisser une panne de journal masquer un refus.
        }
    }

    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
