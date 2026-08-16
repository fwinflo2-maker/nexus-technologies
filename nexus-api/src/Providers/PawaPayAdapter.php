<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * PawaPayAdapter — adaptateur pawaPay (exemple concret).
 *
 * Aucune clé en dur : les credentials viennent de l'environnement
 * (PROVIDER_PAWAPAY_*). Les opérations Mobile Money réelles seront
 * implémentées ici, derrière l'interface commune.
 */
final class PawaPayAdapter extends AbstractProviderAdapter
{
    public function __construct()
    {
        parent::__construct('pawapay');
    }

    protected function declaredMethods(): array
    {
        return ['mobile_money', 'cash_pickup'];
    }
}
