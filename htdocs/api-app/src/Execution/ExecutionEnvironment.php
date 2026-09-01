<?php

declare(strict_types=1);

namespace Nexus\Execution;

use InvalidArgumentException;

/**
 * Environnement d'exécution d'une opération Nexus.
 *
 * Il n'existe que DEUX environnements. Il n'y a ni « staging », ni « test »,
 * ni « dev » : tout alias créerait un troisième environnement fantôme dont les
 * credentials seraient systématiquement absentes — un échec silencieux.
 *
 * Ce type existe pour rendre l'environnement IMPOSSIBLE à confondre avec une
 * chaîne quelconque : une fonction qui accepte `ExecutionEnvironment` ne peut
 * pas recevoir « prod » par erreur.
 */
enum ExecutionEnvironment: string
{
    case SANDBOX    = 'sandbox';
    case PRODUCTION = 'production';

    /**
     * Convertit une chaîne en environnement.
     *
     * Strict par construction : seules les deux valeurs canoniques sont
     * acceptées (casse et espaces tolérés, car ils ne changent pas le sens).
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'sandbox'    => self::SANDBOX,
            'production' => self::PRODUCTION,
            default      => throw new InvalidArgumentException(
                'Environnement d\'exécution invalide : « ' . $value . ' ». Attendu : sandbox ou production.'
            ),
        };
    }

    /** Cet environnement déplace-t-il de l'argent réel ? */
    public function isRealMoney(): bool
    {
        return $this === self::PRODUCTION;
    }
}
