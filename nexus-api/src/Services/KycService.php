<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Kyc\KycProvider;
use Nexus\Kyc\KycStatus;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\KycWebhookEvent;
use PDO;

/**
 * KycService — orchestration KYC/KYB côté Nexus (§21, §22, §24, §26).
 *
 *   Sumsub VÉRIFIE.  Nexus ORCHESTRE.  Le Policy Engine DÉCIDE.
 *
 * Ce service ne contient aucune règle réglementaire (elles appartiennent au
 * Policy Engine) et aucune logique propre à Sumsub (elle appartient à
 * l'adaptateur). Il fait le lien entre les deux et garantit l'idempotence.
 */
final class KycService
{
    private function __construct()
    {
    }

    /**
     * Démarre (ou reprend) une vérification et renvoie un token de session.
     *
     * @return array{applicant_id: string, token: string, expires_in: int}
     */
    public static function startVerification(
        PDO $pdo,
        KycProvider $provider,
        int $userId,
        KycSubjectType $type,
        array $profile = []
    ): array {
        $existing = self::findVerification($pdo, $provider, $userId, $type);

        $applicantId = $existing['applicant_id'] ?? null;
        if (!is_string($applicantId) || $applicantId === '') {
            $applicantId = $provider->createApplicant((string) $userId, $type, $profile);
            self::persistVerification($pdo, $provider, $userId, $type, $applicantId, KycStatus::IN_PROGRESS);
        }

        $session = $provider->createVerificationSession((string) $userId, $type);

        return [
            'applicant_id' => $applicantId,
            'token'        => $session['token'],
            'expires_in'   => $session['expires_in'],
        ];
    }

    /**
     * Traite un webhook DÉJÀ vérifié cryptographiquement.
     *
     * IDEMPOTENCE (§24) : la clé (provider, environment, event_id) est insérée
     * en base sous contrainte UNIQUE. Si l'insertion échoue, l'événement a déjà
     * été traité : on sort sans ré-appliquer le changement d'état.
     *
     * @return array{processed: bool, duplicate: bool, status: string}
     */
    public static function handleVerifiedWebhook(PDO $pdo, KycWebhookEvent $event): array
    {
        // 1) Réservation atomique de l'événement (garde-fou au niveau base).
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO kyc_webhook_events
                (provider, environment, event_id, applicant_id, status)
             VALUES (:p, :e, :eid, :aid, :st)'
        );
        $stmt->execute([
            'p'   => $event->provider,
            'e'   => $event->environment,
            'eid' => $event->eventId,
            'aid' => $event->applicantId,
            'st'  => $event->status->value,
        ]);

        if ($stmt->rowCount() === 0) {
            // Déjà traité : rejeu détecté, aucun effet de bord.
            return ['processed' => false, 'duplicate' => true, 'status' => $event->status->value];
        }

        // 2) Application du nouvel état au dossier correspondant.
        $updated = $pdo->prepare(
            'UPDATE kyc_verifications
                SET status = :st, reason = :reason, reviewed_at = :rev
              WHERE provider = :p AND environment = :e AND applicant_id = :aid'
        );
        $updated->execute([
            'st'     => $event->status->value,
            'reason' => $event->reason,
            'rev'    => $event->occurredAt !== null ? date('Y-m-d H:i:s', strtotime($event->occurredAt) ?: time()) : null,
            'p'      => $event->provider,
            'e'      => $event->environment,
            'aid'    => $event->applicantId,
        ]);

        // 3) Projection sur users.kyc_level — UNIQUEMENT si réellement vérifié.
        //    Aucun autre statut ne doit élever le niveau KYC (§37).
        if ($event->status->isVerified()) {
            self::promoteUserKycLevel($pdo, $event);
        } elseif ($event->status->isFinalRejection()) {
            self::demoteUserKycLevel($pdo, $event);
        }

        return ['processed' => true, 'duplicate' => false, 'status' => $event->status->value];
    }

    /** Statut courant, exposable à l'API (§32) — sans donnée sensible. */
    public static function statusFor(PDO $pdo, KycProvider $provider, int $userId, KycSubjectType $type): array
    {
        $row = self::findVerification($pdo, $provider, $userId, $type);

        $status = $row === null
            ? KycStatus::NOT_STARTED
            : (KycStatus::tryFrom((string) $row['status']) ?? KycStatus::NOT_STARTED);

        return [
            'status'            => $status->value,
            'required_action'   => $status->requiredAction(),
            'verification_type' => $type->value,
            'provider'          => $provider->slug(),
            'environment'       => $provider->environment(),
            'updated_at'        => $row['updated_at'] ?? null,
            'reason'            => $row['reason'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findVerification(PDO $pdo, KycProvider $provider, int $userId, KycSubjectType $type): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM kyc_verifications
              WHERE user_id = :uid AND provider = :p AND environment = :e AND subject_type = :t
              LIMIT 1'
        );
        $stmt->execute([
            'uid' => $userId,
            'p'   => $provider->slug(),
            'e'   => $provider->environment(),
            't'   => $type->value,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private static function persistVerification(
        PDO $pdo,
        KycProvider $provider,
        int $userId,
        KycSubjectType $type,
        string $applicantId,
        KycStatus $status
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO kyc_verifications
                (user_id, provider, environment, subject_type, applicant_id, status)
             VALUES (:uid, :p, :e, :t, :aid, :st)
             ON DUPLICATE KEY UPDATE
                applicant_id = VALUES(applicant_id),
                status       = VALUES(status)'
        );
        $stmt->execute([
            'uid' => $userId,
            'p'   => $provider->slug(),
            'e'   => $provider->environment(),
            't'   => $type->value,
            'aid' => $applicantId,
            'st'  => $status->value,
        ]);
    }

    /**
     * Élève le niveau KYC de l'utilisateur après vérification confirmée.
     *
     * Le niveau reste une PROJECTION : la source de vérité est
     * `kyc_verifications.status`, alimentée par un webhook signé.
     */
    private static function promoteUserKycLevel(PDO $pdo, KycWebhookEvent $event): void
    {
        $pdo->prepare(
            'UPDATE users u
                JOIN kyc_verifications k ON k.user_id = u.id
                SET u.kyc_level = :lvl, u.kyc_verified_at = NOW()
              WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
        )->execute([
            'lvl' => 'standard',
            'p'   => $event->provider,
            'e'   => $event->environment,
            'aid' => $event->applicantId,
        ]);
    }

    private static function demoteUserKycLevel(PDO $pdo, KycWebhookEvent $event): void
    {
        $pdo->prepare(
            'UPDATE users u
                JOIN kyc_verifications k ON k.user_id = u.id
                SET u.kyc_level = :lvl, u.kyc_verified_at = NULL
              WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
        )->execute([
            'lvl' => 'none',
            'p'   => $event->provider,
            'e'   => $event->environment,
            'aid' => $event->applicantId,
        ]);
    }
}
