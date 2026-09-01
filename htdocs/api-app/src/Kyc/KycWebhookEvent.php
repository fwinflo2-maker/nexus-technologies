<?php

declare(strict_types=1);

namespace Nexus\Kyc;

/**
 * KycWebhookEvent — événement de vérification normalisé (§24).
 *
 * Objet immuable, indépendant du provider. Ne contient AUCUNE donnée
 * sensible : ni document, ni selfie, ni donnée biométrique, ni secret (§23).
 *
 * La clé d'idempotence est le triplet (provider, environment, event_id) :
 * aucun événement ne doit être traité deux fois.
 */
final class KycWebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $environment,
        public readonly string $eventId,
        public readonly string $applicantId,
        public readonly string $externalUserId,
        public readonly KycSubjectType $subjectType,
        public readonly KycStatus $status,
        public readonly ?string $reason = null,
        public readonly ?string $occurredAt = null,
    ) {
    }

    /** Clé d'idempotence : provider + environment + event_id (§24). */
    public function idempotencyKey(): string
    {
        return $this->provider . ':' . $this->environment . ':' . $this->eventId;
    }

    /** Représentation non sensible, propre à l'audit et aux logs (§31). */
    public function toAuditArray(): array
    {
        return [
            'provider'     => $this->provider,
            'environment'  => $this->environment,
            'event_id'     => $this->eventId,
            'applicant_id' => $this->applicantId,
            'subject_type' => $this->subjectType->value,
            'status'       => $this->status->value,
            'occurred_at'  => $this->occurredAt,
        ];
    }
}
