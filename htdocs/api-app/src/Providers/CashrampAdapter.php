<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * CashrampAdapter — stub Milestone 2.
 *
 * Cashramp est le provider cible pour comptes/wallets fiat et crypto, mais
 * l'intégration financière complète appartient au Milestone 3. Cet adaptateur
 * existe pour éviter ConfigDrivenProviderAdapter qui simulerait une intégration
 * fonctionnelle via une simple sonde HTTP.
 */
final class CashrampAdapter extends AbstractProviderAdapter
{
    public function __construct()
    {
        parent::__construct('cashramp');
    }
}
