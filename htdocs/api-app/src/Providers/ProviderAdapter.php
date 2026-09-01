<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ProviderAdapter — contrat commun de tous les adaptateurs providers.
 *
 * Le Nexus Core (Capability / Quote / Routing / Execution) ne parle JAMAIS
 * à un provider directement : il passe par cette interface, ce qui garantit
 * que le Core reste provider-agnostic (§1, §9).
 *
 * Toutes les méthodes ne sont pas obligatoires pour tous les providers :
 * un adaptateur « déclare ses capacités » via getCapabilities(), et les
 * opérations qu'il ne supporte pas lèvent ProviderOperationNotImplemented.
 *
 * Aucun secret n'est exposé par cette interface : les credentials restent
 * internes à l'adaptateur (résolus depuis l'environnement).
 */
interface ProviderAdapter
{
    /** Identifiant unique du provider (slug du catalogue). */
    public function slug(): string;

    /** Déclare les capacités réelles (méthodes, pays, devises, statut). */
    public function getCapabilities(): array;

    /** Valide la configuration (présence/format des credentials). */
    public function validateConfiguration(): array;

    /** Health check : configuration + connectivité (si activée). */
    public function healthCheck(): array;

    /**
     * Test de connexion RÉEL : appelle l'API du provider avec les
     * credentials (fournies par le dashboard, sinon l'environnement).
     *
     * Ne retourne JAMAIS un succès sans vérification réelle (§5).
     * Statuts : CONNECTION_SUCCESS | INVALID_CREDENTIALS | UNAUTHORIZED |
     *           PROVIDER_UNAVAILABLE | TIMEOUT | CONFIGURATION_ERROR |
     *           PROVIDER_NOT_CONFIGURED.
     *
     * @param array<string,string>|null $credentials Credentials déchiffrées
     *        (dashboard SuperAdmin) ; null/absent → repli sur l'environnement.
     */
    public function testConnection(string $environment, ?array $credentials = null): array;

    // ── Opérations métier (réservées — non implémentées à ce stade) ──

    public function getQuote(array $intent): array;

    public function createPayment(array $params): array;

    public function getPaymentStatus(string $paymentId): array;

    public function cancelPayment(string $paymentId): array;

    /** Vérifie la signature d'un webhook entrant. */
    public function verifyWebhook(string $payload, string $signature): bool;

    public function getBalance(): array;
}
