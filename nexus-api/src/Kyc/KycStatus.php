<?php

declare(strict_types=1);

namespace Nexus\Kyc;

/**
 * KycStatus — statut de vérification d'identité, INDÉPENDANT du provider (§19, §26).
 *
 * Le Core et le Policy Engine ne raisonnent QUE sur cet enum. Les statuts
 * propriétaires (Sumsub : init/pending/queued/completed + GREEN/RED) sont
 * traduits par l'adaptateur : le Core ne connaît jamais leur vocabulaire.
 */
enum KycStatus: string
{
    /** Aucune démarche engagée. */
    case NOT_STARTED = 'not_started';

    /** Applicant créé, documents non encore soumis/complets. */
    case IN_PROGRESS = 'in_progress';

    /** Dossier soumis, en cours d'examen chez le provider. */
    case PENDING = 'pending';

    /** Vérifié — le provider a rendu un avis favorable. */
    case VERIFIED = 'verified';

    /** Refus temporaire : l'utilisateur peut resoumettre des documents. */
    case RESUBMISSION_REQUESTED = 'resubmission_requested';

    /** Refus définitif. */
    case REJECTED = 'rejected';

    /** Vérification suspendue (revue manuelle, service externe…). */
    case ON_HOLD = 'on_hold';

    /**
     * L'identité est-elle formellement établie ?
     *
     * SEUL VERIFIED autorise les opérations réglementées. Aucun autre statut
     * ne doit être interprété comme un succès (§37 : ne jamais simuler un
     * KYC vérifié).
     */
    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }

    /** Une action de l'utilisateur est-elle attendue ? */
    public function requiresUserAction(): bool
    {
        return in_array($this, [
            self::NOT_STARTED,
            self::IN_PROGRESS,
            self::RESUBMISSION_REQUESTED,
        ], true);
    }

    /** Le dossier est-il définitivement clos en refus ? */
    public function isFinalRejection(): bool
    {
        return $this === self::REJECTED;
    }

    /** Action attendue, exposable à l'API (§32). */
    public function requiredAction(): string
    {
        return match ($this) {
            self::NOT_STARTED            => 'start_verification',
            self::IN_PROGRESS            => 'complete_submission',
            self::RESUBMISSION_REQUESTED => 'resubmit_documents',
            self::PENDING, self::ON_HOLD => 'wait_for_review',
            self::VERIFIED               => 'none',
            self::REJECTED               => 'contact_support',
        };
    }
}
