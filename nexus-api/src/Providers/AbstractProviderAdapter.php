<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Services\ProviderCatalog;

/**
 * AbstractProviderAdapter — implémentation commune des adaptateurs.
 *
 * Fournit :
 *  - la validation de configuration (via ProviderConfig, sans jamais exposer
 *    la valeur des secrets) ;
 *  - le health check (statut + sonde de connectivité optionnelle) ;
 *  - la déclaration des capacités dérivée du catalogue ;
 *  - la vérification de signature de webhook ;
 *  - un comportement honnête (exception) pour les opérations non câblées.
 */
abstract class AbstractProviderAdapter implements ProviderAdapter
{
    protected string $slug;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function getCapabilities(): array
    {
        $provider = ProviderCatalog::get($this->slug);
        $status   = $this->validateConfiguration();

        return [
            'slug'              => $this->slug,
            'name'              => $provider['name'] ?? $this->slug,
            'category'          => $provider['category'] ?? 'unknown',
            'countries'         => $provider['countries'] ?? [],
            'supported_methods' => $this->declaredMethods(),
            'environments'      => ['sandbox', 'production'],
            'active_environment'=> ProviderConfig::activeEnvironment($this->slug),
            'status'            => $status['status']->value,
        ];
    }

    public function validateConfiguration(): array
    {
        return ProviderConfig::validate($this->slug, ProviderConfig::activeEnvironment($this->slug));
    }

    public function healthCheck(): array
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $config      = $this->validateConfiguration();
        $status      = $config['status'];

        // Configuration invalide / désactivée / credentials manquants :
        // la santé est indissociable de la configuration.
        if ($status !== ProviderStatus::CONFIGURED) {
            return [
                'slug'         => $this->slug,
                'environment'  => $environment,
                'status'       => $status->value,
                'healthy'      => false,
                'latency_ms'   => null,
                'message'      => $config['reason'],
            ];
        }

        // Sans sonde de connectivité demandée, on reste honnête :
        // « configured » ≠ « healthy » tant que l'API n'a pas été joignable.
        if (!ProviderConfig::connectivityCheckEnabled()) {
            return [
                'slug'         => $this->slug,
                'environment'  => $environment,
                'status'       => ProviderStatus::CONFIGURED->value,
                'healthy'      => null,
                'latency_ms'   => null,
                'message'      => 'Configuré — connectivité non encore testée (PROVIDERS_CONNECTIVITY_CHECK).',
            ];
        }

        return $this->probeConnectivity($environment);
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $secret      = ProviderConfig::credential($this->slug, 'WEBHOOK_SECRET', $environment);
        if ($secret === null) {
            return false;
        }
        return WebhookVerifier::verify($payload, $signature, $secret);
    }

    // ── Opérations métier : non implémentées à ce stade ────────────────────

    public function getQuote(array $intent): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'getQuote');
    }

    public function createPayment(array $params): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'createPayment');
    }

    public function getPaymentStatus(string $paymentId): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'getPaymentStatus');
    }

    public function cancelPayment(string $paymentId): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'cancelPayment');
    }

    public function getBalance(): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'getBalance');
    }

    // ── Internes ───────────────────────────────────────────────────────────

    /**
     * Méthodes de réception supportées (déduites de la catégorie).
     * Les adaptateurs concrets peuvent surcharger.
     *
     * @return list<string>
     */
    protected function declaredMethods(): array
    {
        $provider = ProviderCatalog::get($this->slug);
        $category = $provider['category'] ?? '';
        return match ($category) {
            'mobile_money', 'payout_network' => ['mobile_money', 'cash_pickup'],
            'banking', 'fx'                  => ['bank'],
            'crypto', 'onramp'               => ['crypto'],
            default                          => [],
        };
    }

    /** Sonde TCP de la base URL (sans jamais envoyer de credentials). */
    private function probeConnectivity(string $environment): array
    {
        $url   = ProviderConfig::baseUrl($this->slug, $environment);
        $parts = parse_url($url);
        $host  = $parts['host'] ?? null;
        // Respecte un port explicite ; sinon déduit du schéma (443 https / 80 http).
        $port  = isset($parts['port'])
            ? (int) $parts['port']
            : (($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        $start = microtime(true);
        $ok    = false;
        if ($host !== null) {
            $errno  = 0;
            $errstr = '';
            $fp     = @fsockopen($host, $port, $errno, $errstr, 5.0);
            if ($fp !== false) {
                $ok = true;
                fclose($fp);
            }
        }
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($ok) {
            return [
                'slug'        => $this->slug,
                'environment' => $environment,
                'status'      => ProviderStatus::HEALTHY->value,
                'healthy'     => true,
                'latency_ms'  => $latencyMs,
                'message'     => null,
            ];
        }

        return [
            'slug'        => $this->slug,
            'environment' => $environment,
            'status'      => ProviderStatus::UNAVAILABLE->value,
            'healthy'     => false,
            'latency_ms'  => $latencyMs,
            'message'     => 'Connexion impossible à la base URL.',
        ];
    }
}
