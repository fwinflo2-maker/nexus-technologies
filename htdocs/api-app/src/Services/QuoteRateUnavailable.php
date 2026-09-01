<?php

declare(strict_types=1);

namespace Nexus\Services;

use RuntimeException;

/**
 * Aucun taux de change n'est disponible pour la paire demandée.
 *
 * Levée par le Quote Engine quand `QuotePricing` ne parvient pas à résoudre
 * le taux. Une quote sans taux réel n'est pas une quote dégradée : c'est une
 * promesse financière sans fondement. Mieux vaut refuser de coter que
 * d'annoncer un montant reçu qui ne pourra pas être honoré (§12, §13).
 *
 * Pendant applicatif de `ProviderOperationNotImplemented` (providers) et de
 * l'état `UNAVAILABLE` de `SanctionsScreening` : même doctrine, même refus de
 * combler une absence de donnée par une valeur plausible.
 */
final class QuoteRateUnavailable extends RuntimeException
{
    /** Code métier renvoyé au client HTTP. */
    public const ERROR_CODE = 'FX_RATE_UNAVAILABLE';

    public function __construct(
        public readonly string $sourceCurrency,
        public readonly string $destCurrency,
        string $reason
    ) {
        parent::__construct($reason);
    }
}
