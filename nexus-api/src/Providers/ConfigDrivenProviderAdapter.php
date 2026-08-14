<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ConfigDrivenProviderAdapter — adaptateur générique piloté par le catalogue.
 *
 * Pour tout provider sans adaptateur dédié, la configuration et les capacités
 * sont dérivées du ProviderCatalog (schéma de credentials, catégorie, pays).
 * Ajouter un provider = l'ajouter au catalogue ; aucun code Core à modifier.
 */
final class ConfigDrivenProviderAdapter extends AbstractProviderAdapter
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
