<?php

declare(strict_types=1);

namespace Nexus\Providers;

use RuntimeException;

/**
 * Levée lorsqu'une opération provider n'est pas encore implémentée.
 *
 * Honnêteté produit : tant que les transactions réelles chez les providers
 * ne sont pas câblées, les adaptateurs lèvent cette exception au lieu de
 * simuler un succès.
 */
final class ProviderOperationNotImplemented extends RuntimeException
{
    public function __construct(string $slug, string $operation)
    {
        parent::__construct(sprintf(
            'Opération « %s » non implémentée pour le provider « %s » (intégration réelle à venir).',
            $operation,
            $slug
        ));
    }
}
