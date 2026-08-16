<?php

declare(strict_types=1);

namespace Nexus\Kyc;

/**
 * KycProvider — contrat commun de tout fournisseur de vérification d'identité (§19).
 *
 *     Nexus → KycProvider (interface) → SumsubAdapter → Sumsub API
 *
 * Le Core ne dépend JAMAIS de Sumsub directement : changer de prestataire
 * doit se limiter à écrire un nouvel adaptateur.
 *
 * Aucune méthode ne renvoie de document brut, de selfie ni de donnée
 * biométrique : la source de vérité documentaire reste chez le provider (§23).
 */
interface KycProvider
{
    /** Identifiant du provider (ex. « sumsub »). */
    public function slug(): string;

    /** Environnement actif (sandbox|production). */
    public function environment(): string;

    /** La configuration est-elle complète et exploitable ? */
    public function isConfigured(): bool;

    /**
     * Crée un applicant chez le provider.
     *
     * @param string      $externalUserId Identifiant Nexus (jamais un secret).
     * @param KycSubjectType $type        Personne physique (KYC) ou entreprise (KYB).
     * @param array<string,mixed> $profile Données non sensibles de pré-remplissage.
     * @return string Identifiant de l'applicant chez le provider.
     */
    public function createApplicant(string $externalUserId, KycSubjectType $type, array $profile = []): string;

    /**
     * Crée une session de vérification (token court destiné au SDK client).
     *
     * Le token retourné est volontairement à durée de vie courte et limité à
     * un seul applicant : c'est la SEULE valeur transmissible au frontend.
     *
     * @return array{token: string, expires_in: int}
     */
    public function createVerificationSession(string $externalUserId, KycSubjectType $type): array;

    /** Statut courant de l'applicant, traduit en vocabulaire Nexus. */
    public function getApplicantStatus(string $applicantId): KycStatus;

    /**
     * Statut détaillé (statut + motif + horodatage), sans donnée sensible.
     *
     * @return array{status: KycStatus, reason: ?string, reviewed_at: ?string}
     */
    public function getVerificationStatus(string $applicantId): array;

    /**
     * Vérifie l'authenticité d'un webhook entrant.
     *
     * DOIT s'appuyer sur une comparaison à temps constant (§25).
     *
     * @param string $rawPayload Corps BRUT de la requête (non ré-encodé).
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * Traduit un webhook vérifié en événement Nexus normalisé.
     *
     * NE DOIT PAS être appelée avant verifyWebhookSignature().
     */
    public function parseWebhook(string $rawPayload): KycWebhookEvent;
}
