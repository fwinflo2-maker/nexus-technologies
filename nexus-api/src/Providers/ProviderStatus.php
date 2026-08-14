<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * Statut d'un provider (configuration + santé).
 *
 * Deux axes, volontairement distincts (§10 du prompt) :
 *  - CONFIGURÉ (configuré ≠ sain) ;
 *  - statuts de santé (HEALTHY / DEGRADED / UNAVAILABLE) réservés au health check.
 *
 * « Configured » ne signifie jamais « Healthy » : la connectivité réelle
 * n'est vérifiée que par le health check (et non par la simple présence
 * de credentials).
 */
enum ProviderStatus: string
{
    case CONFIGURED           = 'configured';
    case MISSING_CREDENTIALS  = 'missing_credentials';
    case INVALID_CONFIGURATION = 'invalid_configuration';
    case DISABLED             = 'disabled';
    case HEALTHY              = 'healthy';
    case DEGRADED             = 'degraded';
    case UNAVAILABLE          = 'unavailable';

    /** Statuts qui autorisent un provider à participer au routing. */
    public function routable(): bool
    {
        return in_array($this, [self::CONFIGURED, self::HEALTHY, self::DEGRADED], true);
    }
}
