<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\DemoMode;
use PHPUnit\Framework\TestCase;

/**
 * Verrou sur le garde-fou du mode démonstration (boucle 12).
 *
 * LE BUG QUE CES TESTS FIGENT
 * ───────────────────────────
 * `seedingAllowed()` lisait le drapeau ainsi :
 *
 *     $flag = strtolower(trim((string) (getenv('NEXUS_DEMO_SEED') ?: '')));
 *     if (in_array($flag, ['0', 'false', 'no', 'off'], true)) { … }
 *
 * `getenv()` rend la CHAÎNE "0" — qui est falsy en PHP. L'opérateur `?:` la
 * remplaçait donc par '', et '' n'appartient pas à la liste d'arrêt : le test
 * ne se déclenchait jamais. Autrement dit `NEXUS_DEMO_SEED=0`, la valeur
 * d'extinction documentée dans .env.example, n'éteignait rien.
 *
 * Conséquence observée sur un serveur configuré avec `NEXUS_DEMO_SEED=0` :
 * une inscription créait quand même six wallets de bienvenue et créditait
 * 2500 EUR de bonus fictif, via de véritables écritures au ledger.
 *
 * Aucun test ne couvrait DemoMode : c'est précisément pour cela que le
 * garde-fou a pu rester inopérant.
 */
final class DemoModeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['NEXUS_DEMO_SEED', 'APP_ENV'] as $key) {
            $this->saved[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    /**
     * LE test de non-régression : "0" doit éteindre le seeding.
     */
    public function test_la_valeur_zero_desactive_reellement_le_seeding(): void
    {
        putenv('NEXUS_DEMO_SEED=0');

        self::assertFalse(
            DemoMode::seedingAllowed(),
            'NEXUS_DEMO_SEED=0 doit désactiver le seeding. La chaîne "0" est '
            . 'falsy en PHP : un `?:` la transforme en chaîne vide et le '
            . 'garde-fou devient inopérant.'
        );
    }

    /**
     * @dataProvider valeursDArret
     */
    public function test_toutes_les_valeurs_d_arret_documentees_fonctionnent(string $value): void
    {
        putenv('NEXUS_DEMO_SEED=' . $value);

        self::assertFalse(DemoMode::seedingAllowed(), "La valeur « {$value} » doit désactiver le seeding.");
    }

    /** @return list<array{string}> */
    public static function valeursDArret(): array
    {
        return [['0'], ['false'], ['no'], ['off'], ['FALSE'], ['Off'], [' 0 ']];
    }

    public function test_le_seeding_reste_actif_par_defaut_hors_production(): void
    {
        putenv('NEXUS_DEMO_SEED');
        putenv('APP_ENV=development');

        self::assertTrue(DemoMode::seedingAllowed());
    }

    public function test_une_valeur_d_activation_explicite_autorise_le_seeding(): void
    {
        putenv('APP_ENV=development');
        putenv('NEXUS_DEMO_SEED=1');

        self::assertTrue(DemoMode::seedingAllowed());
    }

    /**
     * La constante APP_ENV prime sur la variable d'environnement.
     *
     * `tests/bootstrap.php` fait `define('APP_ENV', 'development')`, à l'image
     * du front controller. Cette précédence est VOULUE : la constante reflète
     * la configuration réellement chargée par le déploiement, et une variable
     * d'environnement tardive ne doit pas pouvoir la contredire — sans quoi
     * un processus pourrait se redéclarer « development » en production.
     *
     * Le test le fige donc explicitement, au lieu de laisser croire que
     * `putenv('APP_ENV=production')` suffirait à basculer l'application.
     */
    public function test_la_constante_APP_ENV_prime_sur_la_variable_d_environnement(): void
    {
        self::assertTrue(defined('APP_ENV'), 'Le bootstrap de tests doit définir APP_ENV.');

        putenv('APP_ENV=production');

        self::assertSame(
            APP_ENV === 'production',
            DemoMode::isProduction(),
            'isProduction() doit suivre la constante APP_ENV, pas la variable d\'environnement.'
        );
    }
}
