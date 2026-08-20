<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ConfigDrivenProviderAdapter — adaptateur générique piloté par le catalogue.
 *
 * Dès que des credentials sont saisies dans le SuperAdmin, testConnection()
 * délègue à ProviderAuthProbe (auth adaptée au provider). Les opérations
 * métier (payout, etc.) restent NOT_IMPLEMENTED jusqu'à un adaptateur dédié.
 */
final class ConfigDrivenProviderAdapter extends AbstractProviderAdapter
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        if (!ProviderAuthProbe::supports($this->slug)) {
            return [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Test d\'authentification non encore défini pour ce provider : '
                    . 'les credentials peuvent être stockées, mais aucune validation HTTP n\'est câblée.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        return ProviderAuthProbe::test($this->slug, $environment, $credentials);
    }
}
