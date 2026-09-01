<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use PDO;
use Throwable;

/**
 * ProviderLatency — délai d'exécution d'un provider, mesuré ou déclaré absent.
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ────────────────────────────
 * Le délai annoncé au client venait de `CapabilityEngine::CATEGORY_DELAYS`,
 * une constante de six entrées commentée « simulation démo » :
 *
 *     'mobile_money' => [60, 300],   // 1-5 min
 *     'banking'      => [180, 600],  // 3-10 min
 *
 * Elle était indexée par CATÉGORIE, pas par provider : les trois providers
 * Mobile Money d'un corridor affichaient donc rigoureusement le même
 * « ~3 min ». Ce n'était pas une estimation grossière, c'était une valeur
 * sans rapport avec le provider concerné.
 *
 * Vérifié en HTTP pendant l'audit : après 20 exécutions réelles à 600 s pour
 * pawaPay et 20 à 30 s pour MTN MoMo — un écart mesuré de 20× — l'API
 * annonçait « ~3 min » pour les deux. Sur une base ne contenant AUCUNE
 * transaction, elle annonçait déjà « ~3 min » et décernait le badge
 * « ⚡ PLUS RAPIDE ». Un délai insensible aux temps réellement observés est un
 * faux affichage mesuré (§12).
 *
 * L'enjeu n'est pas cosmétique : `speed_inv` pèse 50 % du score pour
 * l'objectif `fastest` et 10 à 15 % pour les autres. Le client qui demande la
 * route la plus rapide était arbitré par une constante.
 *
 * LES TROIS ÉTATS
 * ───────────────
 * Même doctrine que `ProviderReliability` et `SanctionsScreening` : l'absence
 * de mesure est un état visible, jamais une valeur plausible.
 *
 *   MEASURED           assez d'exécutions chronométrées → délai calculé.
 *   INSUFFICIENT_DATA  des exécutions, mais trop peu pour conclure.
 *   UNAVAILABLE        aucune exécution chronométrée.
 *
 * `INSUFFICIENT_DATA` et `UNAVAILABLE` rendent `seconds => null`. L'appelant
 * ne peut pas publier un délai non mesuré par inadvertance.
 *
 * POURQUOI LA MÉDIANE, ET NON LA MOYENNE
 * ──────────────────────────────────────
 * Un unique transfert bloqué 40 minutes suffirait à faire passer un provider
 * habituellement instantané pour lent : la moyenne suit les valeurs extrêmes.
 * La médiane décrit le délai que l'utilisateur rencontre réellement le plus
 * souvent, ce qui est précisément ce qu'un ETA doit annoncer.
 *
 * POURQUOI UN SEUIL MINIMAL
 * ─────────────────────────
 * Un provider dont on n'a chronométré qu'un transfert afficherait ce chiffre
 * comme un ETA. Une observation n'est pas une statistique : en dessous de
 * MIN_OBSERVATIONS, on refuse de conclure.
 *
 * ISOLATION PAR ENVIRONNEMENT
 * ───────────────────────────
 * Un temps mesuré en sandbox ne dit rien de la production : les rails de test
 * des providers ne sont pas leurs rails réels. Les mesures sont scopées.
 *
 * SOURCE
 * ──────
 * Aucune table nouvelle. `transactions.execution_time_seconds` est déjà
 * renseigné par `ExecutionEngine` (chronométrage réel via `microtime`) et
 * déjà agrégé par `DashboardController` et `BusinessService`. La donnée
 * existait ; seul le routing l'ignorait.
 *
 * `payments` n'est pas utilisé : la table ne porte aucune durée d'exécution
 * (seulement `executed_at`, sans instant de départ fiable). Mesurer une durée
 * à partir de `created_at` mêlerait le temps d'approbation humaine au temps
 * d'exécution du provider — ce serait mesurer autre chose.
 */
final class ProviderLatency
{
    /** Assez d'exécutions chronométrées : le délai est calculé. */
    public const MEASURED = 'MEASURED';

    /** Des exécutions existent, mais en nombre insuffisant pour conclure. */
    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    /** Aucune exécution chronométrée pour ce provider. */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Nombre minimal d'exécutions chronométrées avant de publier un délai.
     *
     * Aligné sur ProviderReliability : même exigence de preuve pour les deux
     * grandeurs affichées au client.
     */
    public const MIN_OBSERVATIONS = 20;

    /** Seules les exécutions menées à terme ont une durée significative. */
    private const COUNTED_STATUSES = ['completed'];

    /**
     * Cache par requête : le Capability Engine interroge chaque provider
     * éligible d'un même corridor. Une seule lecture suffit.
     *
     * @var array<string, array<string, list<int>>>
     */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * Mesure le délai d'exécution d'un provider dans un environnement donné.
     *
     * @return array{status: string, seconds: int|null, measured: bool,
     *               observations: int, p90_seconds: int|null}
     *         `seconds` (médiane) vaut null dès que `measured` est faux.
     *         `p90_seconds` donne la borne haute observée : utile pour
     *         annoncer une fourchette honnête plutôt qu'un chiffre unique.
     */
    public static function forProvider(string $slug, ExecutionEnvironment $environment): array
    {
        $durations = self::durationsFor($slug, $environment);
        $count     = count($durations);

        if ($count === 0) {
            return self::unknown(self::UNAVAILABLE, 0);
        }

        if ($count < self::MIN_OBSERVATIONS) {
            return self::unknown(self::INSUFFICIENT_DATA, $count);
        }

        return [
            'status'       => self::MEASURED,
            'seconds'      => self::percentile($durations, 0.5),
            'measured'     => true,
            'observations' => $count,
            'p90_seconds'  => self::percentile($durations, 0.9),
        ];
    }

    /** Réinitialise le cache par requête (tests, ou traitement long). */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Durées chronométrées d'un provider, triées croissant.
     *
     * @return list<int>
     */
    private static function durationsFor(string $slug, ExecutionEnvironment $environment): array
    {
        $env = $environment->value;

        if (isset(self::$cache[$env][$slug])) {
            return self::$cache[$env][$slug];
        }

        $durations = [];

        // Une base indisponible rend « inconnu », jamais une erreur : un
        // calcul de route ne doit pas échouer sur une statistique d'affichage.
        try {
            $pdo = Database::getConnection();

            $statuses = self::placeholders(self::COUNTED_STATUSES, 's');
            // Même problème d'identité que pour la fiabilité : l'ExecutionEngine
            // enregistre le NOM d'affichage de la route (« pawaPay »), le Core
            // raisonne en slug (« pawapay »). Ignorer l'une des formes
            // amputerait la mesure d'exécutions réelles.
            $identities = self::placeholders(self::identitiesOf($slug), 'p');

            $sql = "SELECT execution_time_seconds
                    FROM transactions
                    WHERE provider IN ({$identities['sql']})
                      AND environment = :env
                      AND status IN ({$statuses['sql']})
                      AND execution_time_seconds IS NOT NULL
                      AND execution_time_seconds > 0
                    ORDER BY execution_time_seconds ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(
                ['env' => $env]
                + $identities['params']
                + $statuses['params']
            );

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $durations[] = (int) $value;
            }
        } catch (Throwable) {
            $durations = [];
        }

        self::$cache[$env][$slug] = $durations;

        return $durations;
    }

    /**
     * Percentile d'une série TRIÉE croissant.
     *
     * L'index est arrondi vers le HAUT (`ceil`), et non vers le bas. Avec
     * `floor`, une série de 20 valeurs dont 18 à 60 s et deux lentes (300 s,
     * 600 s) donnait un p90 à 60 s : la traîne lente disparaissait, et le p90
     * — dont l'objet est précisément de l'exposer — devenait indiscernable de
     * la médiane. Arrondir vers le haut est le choix prudent pour une borne
     * haute annoncée à un client.
     *
     * @param list<int> $sorted
     */
    private static function percentile(array $sorted, float $ratio): int
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0;
        }

        $index = (int) ceil($ratio * ($count - 1));

        return $sorted[max(0, min($count - 1, $index))];
    }

    /**
     * Formes sous lesquelles un provider a pu être enregistré.
     *
     * @return list<string>
     */
    private static function identitiesOf(string $slug): array
    {
        $identities = [$slug];

        $catalog = ProviderCatalog::all();
        $name    = $catalog[$slug]['name'] ?? null;

        if (is_string($name) && $name !== '' && strcasecmp($name, $slug) !== 0) {
            $identities[] = $name;
        }

        return $identities;
    }

    /**
     * Construit une liste de placeholders nommés pour un IN (...).
     *
     * @param list<string> $values
     * @return array{sql: string, params: array<string, string>}
     */
    private static function placeholders(array $values, string $prefix): array
    {
        $names  = [];
        $params = [];

        foreach (array_values($values) as $i => $value) {
            $name          = ':' . $prefix . $i;
            $names[]       = $name;
            $params[$name] = $value;
        }

        return ['sql' => implode(', ', $names), 'params' => $params];
    }

    /**
     * Résultat sans délai : l'état dit pourquoi, `seconds` reste null.
     *
     * @return array{status: string, seconds: int|null, measured: bool,
     *               observations: int, p90_seconds: int|null}
     */
    private static function unknown(string $status, int $observations): array
    {
        return [
            'status'       => $status,
            'seconds'      => null,
            'measured'     => false,
            'observations' => $observations,
            'p90_seconds'  => null,
        ];
    }
}
