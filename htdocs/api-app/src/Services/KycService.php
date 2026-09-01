<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Kyc\KycProvider;
use Nexus\Kyc\KycRiskScorer;
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

        // 3) Projection sur users — UNIQUEMENT si réellement vérifié.
        //    KYB (subject_type=company) → users.kyb_status ; KYC (individu) →
        //    users.kyc_level. Aucun autre statut ne doit élever ces niveaux (§37).
        if ($event->status->isVerified()) {
            self::promoteUserKycLevel($pdo, $event);
        } elseif ($event->status->isFinalRejection()) {
            self::demoteUserKycLevel($pdo, $event);
        }

        return ['processed' => true, 'duplicate' => false, 'status' => $event->status->value];
    }

    /**
     * Évalue puis persiste le niveau de risque KYB d'un compte Business.
     *
     * Le niveau est une PROJECTION déterministe (KycRiskScorer) des attributs
     * déjà collectés (pays, secteur) : il sert au Policy Engine et à la
     * priorisation des revues, sans remplacer l'évaluation de Sumsub.
     *
     * @param array<string,mixed> $user Ligne `users`.
     */
    public static function persistRiskLevel(PDO $pdo, int $userId, array $user): void
    {
        $risk = KycRiskScorer::assess($user);
        $pdo->prepare('UPDATE users SET risk_level = :r WHERE id = :id')
            ->execute(['r' => $risk, 'id' => $userId]);
    }

    /**
     * Profil de vérification lu en base — source de vérité pour Sumsub.
     *
     * Comme pour le dashboard (reload user après sync), on ne s'appuie pas
     * sur la projection JWT de AuthMiddleware : company_name, SIREN, genre…
     * doivent être présents au premier POST /kyc/session.
     *
     * @return array<string,mixed>
     */
    public static function verificationProfile(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, full_name, email, phone, account_type, birth_date, gender,
                    country_of_residence, company_name, company_registration_number,
                    industry, legal_form
               FROM users
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
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

    /**
     * Relit le statut chez le provider (Sumsub) et projette sur Nexus.
     *
     * Utile quand le webhook n'est pas (encore) configuré ou a échoué :
     * la lecture serveur fait autorité au même titre qu'un webhook signé.
     *
     * @return array<string,mixed>
     */
    public static function syncFromProvider(
        PDO $pdo,
        KycProvider $provider,
        int $userId,
        KycSubjectType $type
    ): array {
        $row = self::findVerification($pdo, $provider, $userId, $type);
        if ($row === null) {
            return self::statusFor($pdo, $provider, $userId, $type) + self::accountProjection($pdo, $userId);
        }

        $local = KycStatus::tryFrom((string) $row['status']) ?? KycStatus::NOT_STARTED;
        $applicantId = (string) ($row['applicant_id'] ?? '');
        if ($applicantId === '' || $local->isVerified()) {
            return self::statusFor($pdo, $provider, $userId, $type) + self::accountProjection($pdo, $userId);
        }

        try {
            $remote = $provider->getVerificationStatus($applicantId);
        } catch (\Throwable) {
            // Provider down / timeout : on renvoie l'état local sans bloquer l'UI.
            return self::statusFor($pdo, $provider, $userId, $type) + self::accountProjection($pdo, $userId);
        }

        /** @var KycStatus $status */
        $status = $remote['status'];
        $reason = $remote['reason'] ?? null;
        $reviewedAt = $remote['reviewed_at'] ?? null;

        if ($status !== $local) {
            $pdo->prepare(
                'UPDATE kyc_verifications
                    SET status = :st, reason = :reason, reviewed_at = :rev
                  WHERE id = :id'
            )->execute([
                'st'     => $status->value,
                'reason' => is_string($reason) ? $reason : null,
                'rev'    => is_string($reviewedAt) && $reviewedAt !== ''
                    ? date('Y-m-d H:i:s', strtotime($reviewedAt) ?: time())
                    : ($status->isVerified() || $status->isFinalRejection() ? date('Y-m-d H:i:s') : null),
                'id'     => (int) $row['id'],
            ]);

            if ($status->isVerified()) {
                self::promoteUserById($pdo, $userId, $type);
            } elseif ($status->isFinalRejection()) {
                self::demoteUserById($pdo, $userId, $type);
            }
        }

        return self::statusFor($pdo, $provider, $userId, $type) + self::accountProjection($pdo, $userId);
    }

    /** Projection compte pour que le frontend rafraîchisse badges / bandeau. */
    private static function accountProjection(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT status, kyc_level, kyb_status, kyc_verified_at, kyb_verified_at
               FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $u = $stmt->fetch();
        if ($u === false) {
            return ['account' => null];
        }

        return [
            'account' => [
                'status'           => (string) $u['status'],
                'kyc_level'        => (string) ($u['kyc_level'] ?? 'none'),
                'kyb_status'       => (string) ($u['kyb_status'] ?? 'none'),
                'kyc_verified_at'  => $u['kyc_verified_at'] ?? null,
                'kyb_verified_at'  => $u['kyb_verified_at'] ?? null,
            ],
        ];
    }

    private static function promoteUserById(PDO $pdo, int $userId, KycSubjectType $type): void
    {
        if ($type === KycSubjectType::COMPANY) {
            $pdo->prepare(
                'UPDATE users
                    SET kyb_status = :st,
                        kyb_verified_at = NOW(),
                        status = IF(status = \'PENDING\', \'ACTIVE\', status)
                  WHERE id = :id'
            )->execute(['st' => KycStatus::VERIFIED->value, 'id' => $userId]);
            return;
        }

        $pdo->prepare(
            'UPDATE users
                SET kyc_level = :lvl,
                    kyc_verified_at = NOW(),
                    status = IF(status = \'PENDING\', \'ACTIVE\', status)
              WHERE id = :id'
        )->execute(['lvl' => 'standard', 'id' => $userId]);
    }

    private static function demoteUserById(PDO $pdo, int $userId, KycSubjectType $type): void
    {
        if ($type === KycSubjectType::COMPANY) {
            $pdo->prepare(
                'UPDATE users SET kyb_status = :st, kyb_verified_at = NULL WHERE id = :id'
            )->execute(['st' => 'none', 'id' => $userId]);
            return;
        }

        $pdo->prepare(
            'UPDATE users SET kyc_level = :lvl, kyc_verified_at = NULL WHERE id = :id'
        )->execute(['lvl' => 'none', 'id' => $userId]);
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
     * Élève le niveau de l'utilisateur après vérification confirmée.
     *
     * La projection dépend de la nature du sujet :
     *   - company → `users.kyb_status` (KYB Business, indépendant du KYC) ;
     *   - individual → `users.kyc_level` (KYC personne physique).
     *
     * Le niveau reste une PROJECTION : la source de vérité est
     * `kyc_verifications.status`, alimentée par un webhook signé.
     */
    private static function promoteUserKycLevel(PDO $pdo, KycWebhookEvent $event): void
    {
        $subjectType = self::subjectTypeOf($pdo, $event);
        $params = [
            'p'   => $event->provider,
            'e'   => $event->environment,
            'aid' => $event->applicantId,
        ];

        if ($subjectType === KycSubjectType::COMPANY->value) {
            // KYB — la vérification d'entreprise ne touche PAS au KYC individuel.
            // Un compte PENDING passe ACTIVE dès que le KYB est validé (sinon le
            // bandeau « vérification requise » reste affiché indéfiniment).
            $pdo->prepare(
                'UPDATE users u
                    JOIN kyc_verifications k ON k.user_id = u.id
                    SET u.kyb_status = :st,
                        u.kyb_verified_at = NOW(),
                        u.status = IF(u.status = \'PENDING\', \'ACTIVE\', u.status)
                  WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
            )->execute(['st' => KycStatus::VERIFIED->value] + $params);
            return;
        }

        $pdo->prepare(
            'UPDATE users u
                JOIN kyc_verifications k ON k.user_id = u.id
                SET u.kyc_level = :lvl,
                    u.kyc_verified_at = NOW(),
                    u.status = IF(u.status = \'PENDING\', \'ACTIVE\', u.status)
              WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
        )->execute(['lvl' => 'standard'] + $params);
    }

    private static function demoteUserKycLevel(PDO $pdo, KycWebhookEvent $event): void
    {
        $subjectType = self::subjectTypeOf($pdo, $event);
        $params = [
            'p'   => $event->provider,
            'e'   => $event->environment,
            'aid' => $event->applicantId,
        ];

        if ($subjectType === KycSubjectType::COMPANY->value) {
            // KYB — révoque l'état d'entreprise vérifiée.
            $pdo->prepare(
                'UPDATE users u
                    JOIN kyc_verifications k ON k.user_id = u.id
                    SET u.kyb_status = :st, u.kyb_verified_at = NULL
                  WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
            )->execute(['st' => 'none'] + $params);
            return;
        }

        $pdo->prepare(
            'UPDATE users u
                JOIN kyc_verifications k ON k.user_id = u.id
                SET u.kyc_level = :lvl, u.kyc_verified_at = NULL
              WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
        )->execute(['lvl' => 'none'] + $params);
    }

    /** Nature du sujet porté par l'applicant (lecture projetée, non sensible). */
    private static function subjectTypeOf(PDO $pdo, KycWebhookEvent $event): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT subject_type FROM kyc_verifications
              WHERE provider = :p AND environment = :e AND applicant_id = :aid
              LIMIT 1'
        );
        $stmt->execute([
            'p'   => $event->provider,
            'e'   => $event->environment,
            'aid' => $event->applicantId,
        ]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
