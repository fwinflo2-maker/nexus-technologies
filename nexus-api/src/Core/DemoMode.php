<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * DemoMode — garde-fou unique du mode démonstration (§13, §29, §37).
 *
 * RÈGLE ABSOLUE : aucune donnée de démonstration (wallets de bienvenue,
 * transactions fictives, comptes ou notifications de démo) ne doit être
 * injectée automatiquement en production.
 *
 * Le contrôle est centralisé ICI plutôt que dupliqué sur chaque site
 * d'appel : un garde-fou éparpillé finit toujours par être oublié à un
 * endroit, et c'est exactement ce genre d'oubli qui fait fuiter des données
 * fictives en production.
 *
 * Le seeding est autorisé UNIQUEMENT si :
 *   - APP_ENV n'est pas « production » ; ET
 *   - NEXUS_DEMO_SEED n'est pas explicitement désactivé.
 *
 * En production, aucune variable d'environnement ne peut le réactiver :
 * la production est un refus inconditionnel.
 */
final class DemoMode
{
    private function __construct()
    {
    }

    /** L'environnement applicatif est-il la production ? */
    public static function isProduction(): bool
    {
        if (defined('APP_ENV')) {
            return APP_ENV === 'production';
        }
        return strtolower(trim((string) (getenv('APP_ENV') ?: ''))) === 'production';
    }

    /**
     * Le seeding de données de démonstration est-il autorisé ?
     *
     * Production → toujours false, sans exception possible.
     */
    public static function seedingAllowed(): bool
    {
        if (self::isProduction()) {
            return false;
        }

        // ATTENTION au `?:` ici : `getenv()` rend la CHAÎNE "0", qui est
        // falsy en PHP. `getenv('NEXUS_DEMO_SEED') ?: ''` transformait donc
        // "0" en chaîne vide, laquelle n'appartient pas à la liste ci-dessous
        // — et `NEXUS_DEMO_SEED=0`, la valeur d'arrêt documentée dans
        // .env.example, n'éteignait rien. Le seeding continuait alors que
        // l'exploitant l'avait explicitement désactivé.
        // Seul `false` (variable absente) doit être traité comme « non
        // renseigné » : d'où la comparaison stricte.
        $raw  = getenv('NEXUS_DEMO_SEED');
        $flag = $raw === false ? '' : strtolower(trim($raw));

        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }
}
