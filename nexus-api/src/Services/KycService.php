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
            $pdo->prepare(
                'UPDATE users u
                    JOIN kyc_verifications k ON k.user_id = u.id
                    SET u.kyb_status = :st, u.kyb_verified_at = NOW()
                  WHERE k.provider = :p AND k.environment = :e AND k.applicant_id = :aid'
            )->execute(['st' => KycStatus::VERIFIED->value] + $params);
            return;
        }

        $pdo->prepare(
            'UPDATE users u
                JOIN kyc_verifications k ON k.user_id = u.id
                SET u.kyc_level = :lvl, u.kyc_verified_at = NOW()
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

    /**
     * Décision manuelle exclusive Super Admin — secours quand Sumsub est HS.
     *
     * Ne remplace pas le flux provider : c'est un override audité, réservé au
     * Super Admin, pour débloquer un compte lorsque le provider KYC est
     * indisponible ou renvoie un état incohérent. Le motif est obligatoire.
     *
     * @return array{
     *   verification_id: int,
     *   user_id: int,
     *   status: string,
     *   subject_type: string,
     *   provider: string,
     *   created: bool
     * }
     */
    public static function applyManualOverride(
        PDO $pdo,
        int $actorId,
        string $decision,
        string $reason,
        ?int $verificationId = null,
        ?int $userId = null,
        ?string $subjectType = null,
        string $environment = 'sandbox'
    ): array {
        $decision = strtolower(trim($decision));
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Un motif est obligatoire pour un override KYC manuel.');
        }
        if (strlen($reason) > 500) {
            throw new \InvalidArgumentException('Motif trop long (500 caractères max).');
        }

        $status = match ($decision) {
            'approve' => KycStatus::VERIFIED,
            'reject' => KycStatus::REJECTED,
            'resubmission' => KycStatus::RESUBMISSION_REQUESTED,
            default => throw new \InvalidArgumentException('Décision KYC invalide.'),
        };

        $env = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';
        $created = false;

        if ($verificationId !== null && $verificationId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM kyc_verifications WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $verificationId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new \RuntimeException('Vérification introuvable.');
            }
        } else {
            if ($userId === null || $userId <= 0) {
                throw new \InvalidArgumentException('user_id ou verification_id requis.');
            }
            $userStmt = $pdo->prepare('SELECT id, account_type FROM users WHERE id = :id FOR UPDATE');
            $userStmt->execute(['id' => $userId]);
            $user = $userStmt->fetch();
            if ($user === false) {
                throw new \RuntimeException('Utilisateur introuvable.');
            }

            $type = $subjectType
                ?? (((string) ($user['account_type'] ?? 'personal') === 'business')
                    ? KycSubjectType::COMPANY->value
                    : KycSubjectType::INDIVIDUAL->value);
            if (!in_array($type, [KycSubjectType::INDIVIDUAL->value, KycSubjectType::COMPANY->value], true)) {
                throw new \InvalidArgumentException('subject_type invalide.');
            }

            // Priorité : dossier Sumsub existant pour ce sujet / env, sinon manuel.
            $find = $pdo->prepare(
                'SELECT * FROM kyc_verifications
                  WHERE user_id = :uid AND environment = :e AND subject_type = :t
                  ORDER BY CASE provider WHEN \'sumsub\' THEN 0 WHEN \'manual\' THEN 1 ELSE 2 END, id DESC
                  LIMIT 1
                  FOR UPDATE'
            );
            $find->execute(['uid' => $userId, 'e' => $env, 't' => $type]);
            $row = $find->fetch();

            if ($row === false) {
                $applicantId = 'manual-' . $userId . '-' . bin2hex(random_bytes(6));
                $ins = $pdo->prepare(
                    'INSERT INTO kyc_verifications
                        (user_id, provider, environment, subject_type, applicant_id, level_name, status, reason, reviewed_at)
                     VALUES (:uid, \'manual\', :e, :t, :aid, \'superadmin_manual\', :st, :reason, NOW())'
                );
                $ins->execute([
                    'uid'    => $userId,
                    'e'      => $env,
                    't'      => $type,
                    'aid'    => $applicantId,
                    'st'     => $status->value,
                    'reason' => $reason,
                ]);
                $verificationId = (int) $pdo->lastInsertId();
                $created = true;
                $row = [
                    'id'           => $verificationId,
                    'user_id'      => $userId,
                    'provider'     => 'manual',
                    'environment'  => $env,
                    'subject_type' => $type,
                    'applicant_id' => $applicantId,
                ];
            }
        }

        $vid = (int) $row['id'];
        $uid = (int) $row['user_id'];
        $subject = (string) $row['subject_type'];

        if (!$created) {
            $pdo->prepare(
                'UPDATE kyc_verifications
                    SET status = :st, reason = :reason, reviewed_at = NOW(),
                        level_name = COALESCE(level_name, \'superadmin_manual\')
                  WHERE id = :id'
            )->execute([
                'st'     => $status->value,
                'reason' => $reason,
                'id'     => $vid,
            ]);
        }

        self::projectManualOverrideOntoUser($pdo, $uid, $subject, $status);

        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata)
             VALUES (:user, :action, \'kyc_verifications\', :id, :metadata)'
        )->execute([
            'user'   => $actorId,
            'action' => match ($decision) {
                'approve' => 'kyc.approve',
                'reject' => 'kyc.reject',
                default => 'kyc.resubmission',
            },
            'id'     => $vid,
            'metadata' => json_encode([
                'source'         => 'superadmin_manual_override',
                'decision'       => $decision,
                'reason'         => $reason,
                'user_id'        => $uid,
                'subject_type'   => $subject,
                'provider'       => (string) $row['provider'],
                'environment'    => (string) $row['environment'],
                'created_dossier'=> $created,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'verification_id' => $vid,
            'user_id'         => $uid,
            'status'          => $status->value,
            'subject_type'    => $subject,
            'provider'        => (string) $row['provider'],
            'created'         => $created,
        ];
    }

    private static function projectManualOverrideOntoUser(
        PDO $pdo,
        int $userId,
        string $subjectType,
        KycStatus $status
    ): void {
        if ($subjectType === KycSubjectType::COMPANY->value) {
            if ($status->isVerified()) {
                $pdo->prepare(
                    'UPDATE users SET kyb_status = :st, kyb_verified_at = NOW() WHERE id = :id'
                )->execute(['st' => KycStatus::VERIFIED->value, 'id' => $userId]);
            } elseif ($status->isFinalRejection() || $status === KycStatus::RESUBMISSION_REQUESTED) {
                $pdo->prepare(
                    'UPDATE users SET kyb_status = :st, kyb_verified_at = NULL WHERE id = :id'
                )->execute([
                    'st' => $status->isFinalRejection() ? 'rejected' : 'pending',
                    'id' => $userId,
                ]);
            }
            return;
        }

        if ($status->isVerified()) {
            $pdo->prepare(
                "UPDATE users SET kyc_level = 'standard', kyc_verified_at = NOW() WHERE id = :id"
            )->execute(['id' => $userId]);
        } elseif ($status->isFinalRejection() || $status === KycStatus::RESUBMISSION_REQUESTED) {
            $pdo->prepare(
                "UPDATE users SET kyc_level = 'none', kyc_verified_at = NULL WHERE id = :id"
            )->execute(['id' => $userId]);
        }
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
