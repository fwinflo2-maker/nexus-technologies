<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use PDO;
use Throwable;

/**
 * ProviderReliability — fiabilité d'un provider, mesurée ou déclarée absente.
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ────────────────────────────
 * La fiabilité vivait dans `CapabilityEngine::PERFORMANCE_SCORES`, une
 * constante PHP de 20 valeurs écrites à la main :
 *
 *     'pawapay' => 0.97, 'stripe' => 0.99, 'noah' => 0.85, …
 *
 * Ces nombres ne mesuraient rien. Aucune table `providers` ni service de
 * métriques n'existe dans le schéma : il n'y avait aucune source d'où ils
 * auraient pu provenir. Et pourtant ils étaient présentés au client comme une
 * mesure (`reliability: "Élevée"`, `reliabilityNum: 0.97`, badge
 * « 🛡️ PLUS FIABLE ») et pesaient jusqu'à 55 % du classement des routes pour
 * l'objectif `most_reliable`.
 *
 * Vérifié en HTTP pendant l'audit : après 10 paiements pawaPay `failed` sur 10
 * (taux de succès réel 0 %), l'API annonçait toujours « Élevée / 0.97 / PLUS
 * FIABLE ». Un nombre insensible à la réalité qu'il prétend décrire n'est pas
 * une mesure imprécise : c'est un faux succès (§12), et il contredisait
 * frontalement la promesse affichée par le Control Center — « aucun score de
 * fiabilité fabriqué ».
 *
 * LES TROIS ÉTATS
 * ───────────────
 * Même doctrine que `SanctionsScreening` : l'absence de mesure est un état
 * visible, jamais un score plausible.
 *
 *   MEASURED           assez d'exécutions réelles observées → score calculé.
 *   INSUFFICIENT_DATA  des exécutions existent, mais trop peu pour conclure.
 *   UNAVAILABLE        aucune exécution observée pour ce provider.
 *
 * `INSUFFICIENT_DATA` et `UNAVAILABLE` ne sont JAMAIS convertis en score.
 * L'appelant reçoit `score => null` et doit le traiter comme « inconnu ».
 *
 * POURQUOI UN SEUIL MINIMAL
 * ─────────────────────────
 * Un provider ayant réussi son unique transfert afficherait 100 % de succès et
 * dominerait le classement. Une observation n'est pas une statistique : en
 * dessous de MIN_OBSERVATIONS, on refuse de conclure plutôt que de publier un
 * chiffre que le premier échec démentira.
 *
 * ISOLATION PAR ENVIRONNEMENT
 * ───────────────────────────
 * Les mesures sont scopées par environnement. Des succès en sandbox ne
 * disent rien de la production : mélanger les deux ferait passer un provider
 * jamais utilisé en argent réel pour éprouvé.
 *
 * SOURCES
 * ───────
 * Aucune table nouvelle : `transactions` et `payments` portent déjà
 * `provider`, `status` et `environment`. Les deux sont agrégées, car un
 * transfert Personal et un paiement Business sont deux exécutions réelles du
 * même provider.
 *
 * IDENTIFICATION DU PROVIDER
 * ──────────────────────────
 * La colonne `provider` n'est pas normalisée : l'ExecutionEngine y écrit le
 * NOM d'affichage issu de la route (`pawaPay`), tandis que le reste du Core
 * raisonne en slug (`pawapay`). Vérifié en base : les deux formes coexistent.
 *
 * Ne compter que le slug ferait silencieusement disparaître des exécutions
 * réelles — et une mesure amputée est une mesure fausse. On interroge donc le
 * slug ET le nom catalogue correspondant. La comparaison reste insensible à
 * la casse (collation `utf8mb4_unicode_ci`).
 */
final class ProviderReliability
{
    /** Assez d'observations réelles : le score est calculé. */
    public const MEASURED = 'MEASURED';

    /** Des observations existent, mais en nombre insuffisant pour conclure. */
    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    /** Aucune exécution observée pour ce provider. */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Nombre minimal d'exécutions terminées avant de publier un score.
     *
     * En dessous, le taux observé est trop volatil : 1 succès isolé donnerait
     * 100 %, un échec isolé 0 %.
     */
    public const MIN_OBSERVATIONS = 20;

    /** Statuts considérés comme une exécution terminée avec succès. */
    private const SUCCESS_STATUSES = ['completed'];

    /** Statuts considérés comme un échec d'exécution. */
    private const FAILURE_STATUSES = ['failed'];

    /**
     * Cache par requête : le Capability Engine interroge la fiabilité pour
     * chaque provider éligible d'un même corridor. Une seule agrégation suffit.
     *
     * @var array<string, array<string, array{successes: int, failures: int}>>
     */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * Mesure la fiabilité d'un provider dans un environnement donné.
     *
     * @return array{status: string, score: float|null, measured: bool,
     *               observations: int, successes: int, failures: int}
     *         `score` vaut null dès que `measured` est faux : aucun appelant
     *         ne doit pouvoir afficher un nombre non mesuré par inadvertance.
     */
    public static function forProvider(string $slug, ExecutionEnvironment $environment): array
    {
        $counts = self::countsFor($slug, $environment);

        $successes    = $counts['successes'];
        $failures     = $counts['failures'];
        $observations = $successes + $failures;

        if ($observations === 0) {
            return self::unknown(self::UNAVAILABLE, 0, 0, 0);
        }

        if ($observations < self::MIN_OBSERVATIONS) {
            return self::unknown(self::INSUFFICIENT_DATA, $observations, $successes, $failures);
        }

        return [
            'status'       => self::MEASURED,
            'score'        => round($successes / $observations, 4),
            'measured'     => true,
            'observations' => $observations,
            'successes'    => $successes,
            'failures'     => $failures,
        ];
    }

    /** Réinitialise le cache par requête (tests, ou traitement long). */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Agrège les exécutions terminées d'un provider, tous canaux confondus.
     *
     * @return array{successes: int, failures: int}
     */
    private static function countsFor(string $slug, ExecutionEnvironment $environment): array
    {
        $env = $environment->value;

        if (isset(self::$cache[$env][$slug])) {
            return self::$cache[$env][$slug];
        }

        $totals = ['successes' => 0, 'failures' => 0];

        // La mesure ne doit jamais casser le devis : une base indisponible
        // rend « inconnu », pas une erreur 500 sur un simple calcul de route.
        try {
            $pdo = Database::getConnection();

            foreach (['transactions', 'payments'] as $table) {
                $row = self::aggregate($pdo, $table, $slug, $env);
                $totals['successes'] += $row['successes'];
                $totals['failures']  += $row['failures'];
            }
        } catch (Throwable) {
            $totals = ['successes' => 0, 'failures' => 0];
        }

        self::$cache[$env][$slug] = $totals;

        return $totals;
    }

    /**
     * Compte succès et échecs d'un provider dans une table donnée.
     *
     * @return array{successes: int, failures: int}
     */
    private static function aggregate(PDO $pdo, string $table, string $slug, string $env): array
    {
        // $table n'est jamais une entrée utilisateur : la liste est fixée par
        // l'appelant ci-dessus. Les valeurs, elles, sont toujours liées.
        $successList = self::placeholders(self::SUCCESS_STATUSES, 's');
        $failureList = self::placeholders(self::FAILURE_STATUSES, 'f');

        $identities = self::placeholders(self::identitiesOf($slug), 'p');

        $sql = "SELECT
                    SUM(CASE WHEN status IN ({$successList['sql']}) THEN 1 ELSE 0 END) AS successes,
                    SUM(CASE WHEN status IN ({$failureList['sql']}) THEN 1 ELSE 0 END) AS failures
                FROM {$table}
                WHERE provider IN ({$identities['sql']})
                  AND environment = :env";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(
            ['env' => $env]
            + $identities['params']
            + $successList['params']
            + $failureList['params']
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'successes' => (int) ($row['successes'] ?? 0),
            'failures'  => (int) ($row['failures'] ?? 0),
        ];
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
     * Résultat sans score : l'état dit pourquoi, `score` reste null.
     *
     * @return array{status: string, score: float|null, measured: bool,
     *               observations: int, successes: int, failures: int}
     */
    private static function unknown(string $status, int $observations, int $successes, int $failures): array
    {
        return [
            'status'       => $status,
            'score'        => null,
            'measured'     => false,
            'observations' => $observations,
            'successes'    => $successes,
            'failures'     => $failures,
        ];
    }
}
