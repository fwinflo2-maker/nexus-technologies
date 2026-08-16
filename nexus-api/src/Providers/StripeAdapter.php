<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * StripeAdapter — adaptateur Stripe (exemple concret).
 *
 * Ne contient AUCUNE clé : les credentials sont résolus depuis l'environnement
 * (PROVIDER_STRIPE_*). Les opérations de paiement réelles seront implémentées
 * ici, derrière l'interface commune, sans jamais toucher au Core.
 */
final class StripeAdapter extends AbstractProviderAdapter
{
    public function __construct()
    {
        parent::__construct('stripe');
    }

    protected function declaredMethods(): array
    {
        return ['card', 'bank'];
    }
}
