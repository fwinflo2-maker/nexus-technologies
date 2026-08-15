<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Routing Engine — classe les routes et construit les 3 meilleures.
 *
 * Étape 4 du pipeline. Reçoit les quotes brutes du Quote Engine,
 * les classe selon l'objectif de l'utilisateur et retourne les 3
 * meilleures options (A, B, C) avec leur badge.
 *
 * Modes de routing :
 *   - ⭐ optimized    : meilleur équilibre frais/réception/fiabilité
 *   - ⚡ fastest      : délai le plus court
 *   - 💰 max_received : montant reçu maximum
 *   - 💸 cheapest     : frais les plus bas
 *   - 🛡️ most_reliable : fiabilité la plus élevée
 *
 * La route recommandée (★) est celle qui gagne le classement
 * multi-critères du mode « optimized ».
 *
 * Les 3 routes retournées sont toujours cohérentes entre elles :
 * mêmes frais de base, taux fixe, spreads variés.
 */
final class RoutingEngine
{
    /** Badge par objectif. */
    private const OBJECTIVE_BADGES = [
        'optimized'     => ['badge' => '⭐ RECOMMANDÉE',   'cls' => 'p-gr'],
        'fastest'       => ['badge' => '⚡ PLUS RAPIDE',    'cls' => 'p-c'],
        'max_received'  => ['badge' => '💰 MAX REÇU',      'cls' => 'p-g'],
        'cheapest'      => ['badge' => '💸 MOINS CHER',    'cls' => 'p-g'],
        'most_reliable' => ['badge' => '🛡️ PLUS FIABLE',   'cls' => 'p-v'],
    ];

    /**
     * Mapping objective → indicateur de classement (pour le tri secondaire).
     * Plus la valeur est élevée, meilleure est la route pour cet objectif.
     */
    private const SCORING_WEIGHTS = [
        'optimized'     => ['received' => 0.35, 'reliability' => 0.25, 'fees_inv' => 0.25, 'speed_inv' => 0.15],
        'fastest'       => ['speed_inv' => 0.50, 'reliability' => 0.25, 'received' => 0.15, 'fees_inv' => 0.10],
        'max_received'  => ['received' => 0.60, 'reliability' => 0.15, 'fees_inv' => 0.15, 'speed_inv' => 0.10],
        'cheapest'      => ['fees_inv' => 0.50, 'reliability' => 0.25, 'received' => 0.15, 'speed_inv' => 0.10],
        'most_reliable' => ['reliability' => 0.55, 'received' => 0.20, 'fees_inv' => 0.15, 'speed_inv' => 0.10],
    ];

    /** Nombre de routes maximales retournées. */
    private const MAX_ROUTES = 3;

    private function __construct() {}

    /**
     * Classe les quotes et retourne les 3 meilleures routes.
     *
     * @param list<array{provider_slug: string, provider_name: string, received: float,
     *                    fees: float, delay_min: int, delay_max: int, delay_avg: int,
     *                    reliability: float, ...}> $quotes
     * @param string $objective
     * @param string $quoteId ID de la quote (pour les IDs A/B/C).
     * @param float  $amountSent Montant envoyé (pour l'affichage).
     * @param string $sourceCurrency Devise source.
     * @param string $destCurrency Devise destination.
     * @param string $methodType Mode de réception (mobile_money, bank, etc.).
     *
     * @return list<array{
     *     id: string,
     *     badge: string,
     *     badgeCls: string,
     *     provider: string,
     *     providerSlug: string,
     *     method: string,
     *     methodIcon: string,
     *     received: string,
     *     receivedNum: float,
     *     fees: string,
     *     feesNum: float,
     *     delay: string,
     *     delayMinutes: int,
     *     reliability: string,
     *     reliabilityNum: float,
     *     reliabilityColor: string,
     *     recommended: bool,
     *     spread: string,
     *     rate: float,
     * }>
     */
    public static function rank(
        array $quotes,
        string $objective,
        string $quoteId,
        float $amountSent,
        string $sourceCurrency,
        string $destCurrency,
        string $methodType,
    ): array {
        $weights = self::SCORING_WEIGHTS[$objective] ?? self::SCORING_WEIGHTS['optimized'];

        // ── Normalisation des valeurs pour le scoring ───────────
        $maxReceived = max(array_map(fn ($q) => (float) $q['received'], $quotes));
        $minReceived = min(array_map(fn ($q) => (float) $q['received'], $quotes));
        $maxFees     = max(array_map(fn ($q) => (float) $q['fees'], $quotes));
        $minFees     = min(array_map(fn ($q) => (float) $q['fees'], $quotes));
        $maxDelay    = max(array_map(fn ($q) => (int) $q['delay_avg'], $quotes));
        $minDelay    = min(array_map(fn ($q) => (int) $q['delay_avg'], $quotes));
        // Fiabilité : seules les valeurs MESURÉES entrent dans la
        // normalisation. `(float) null` vaudrait 0.0 et ferait passer un
        // provider « non mesuré » pour le pire de tous (§17).
        $measuredRel = array_values(array_filter(
            array_map(static fn ($q) => $q['reliability'], $quotes),
            static fn ($r) => $r !== null
        ));
        $maxRel = $measuredRel === [] ? 0.0 : max($measuredRel);
        $minRel = $measuredRel === [] ? 0.0 : min($measuredRel);

        // Éviter division par zéro
        $norm = static fn (float $val, float $min, float $max): float =>
            $max > $min ? ($val - $min) / ($max - $min) : 0.5;

        // ── Scoring multi-critères ──────────────────────────────
        $scored = [];
        foreach ($quotes as $q) {
            $receivedScore = $norm((float) $q['received'], $minReceived, $maxReceived);
            $feesScore     = 1.0 - $norm((float) $q['fees'], $minFees, $maxFees); // inversion : moins cher = mieux
            $speedScore    = 1.0 - $norm((float) $q['delay_avg'], $minDelay, $maxDelay); // inversion : plus rapide = mieux
            // Non mesuré → score NEUTRE, ni bonus ni malus. Départager des
            // routes sur une fiabilité inconnue reviendrait à inventer un
            // classement ; à défaut de mesure, la composante n'avantage
            // personne.
            $reliabilityScore = $q['reliability'] === null
                ? 0.5
                : $norm((float) $q['reliability'], $minRel, $maxRel);

            $score = $receivedScore * $weights['received']
                + $reliabilityScore * $weights['reliability']
                + $feesScore * $weights['fees_inv']
                + $speedScore * $weights['speed_inv'];

            $q['_score'] = $score;
            $scored[] = $q;
        }

        // Tri par score décroissant
        usort($scored, static fn (array $a, array $b): int =>
            $b['_score'] <=> $a['_score']
        );

        // ── Sélection des top N ─────────────────────────────────
        $best = array_slice($scored, 0, self::MAX_ROUTES);

        // ── Badge de l'objectif principal ───────────────────────
        $objBadge = self::OBJECTIVE_BADGES[$objective] ?? self::OBJECTIVE_BADGES['optimized'];

        // « 🛡️ PLUS FIABLE » affirme un fait mesuré. Or ce badge était décerné
        // sur le seul objectif demandé : sur un corridor sans historique,
        // `most_reliable` le collait à la première route alors qu'AUCUNE
        // fiabilité n'était connue — constaté en HTTP après la correction du
        // score lui-même. À défaut de mesure, on retombe sur le badge neutre
        // « RECOMMANDÉE », qui décrit un classement et non une performance
        // observée.
        if ($objective === 'most_reliable') {
            $anyMeasured = false;
            foreach ($best as $candidate) {
                if (($candidate['reliability'] ?? null) !== null) {
                    $anyMeasured = true;
                    break;
                }
            }
            if (!$anyMeasured) {
                $objBadge = self::OBJECTIVE_BADGES['optimized'];
            }
        }

        // ── Icônes de méthode ──────────────────────────────────
        $methodIcons = [
            'mobile_money' => '📱 Mobile Money',
            'bank'         => '🏦 Virement',
            'crypto'       => '₿ Crypto',
            'cash_pickup'  => '💵 Cash Pickup',
        ];

        // ── Construction des routes finales ─────────────────────
        $routes = [];
        $routeLabels = ['A', 'B', 'C'];

        foreach ($best as $index => $q) {
            $label = $routeLabels[$index] ?? 'X';
            $isFirst = $index === 0;

            // Badge : la première route prend le badge de l'objectif
            // Les suivantes prennent des badges contextuels
            if ($isFirst) {
                $badge    = $objBadge['badge'];
                $badgeCls = $objBadge['cls'];
            } else {
                $contextBadge = self::contextBadge($q, $best[0]);
                $badge    = $contextBadge['badge'];
                $badgeCls = $contextBadge['cls'];
            }

            $received = (float) $q['received'];
            $fees     = (float) $q['fees'];

            // Fiabilité → label texte + couleur, UNIQUEMENT si mesurée.
            // Auparavant `(float) null` produisait 0.0, donc « Modérée » en
            // orange : une note inventée pour un provider jamais observé.
            $reliabilityRaw    = $q['reliability'] ?? null;
            $reliabilityStatus = (string) ($q['reliability_status'] ?? ProviderReliability::UNAVAILABLE);
            $reliabilityNum    = $reliabilityRaw === null ? null : (float) $reliabilityRaw;
            $reliabilityText   = $reliabilityNum === null
                ? 'Non mesurée'
                : self::reliabilityLabel($reliabilityNum);
            $reliabilityColor  = $reliabilityNum === null
                ? 'var(--text-mid)'
                : self::reliabilityColor($reliabilityNum);

            $delayAvg = (int) $q['delay_avg'];
            $delayText = $delayAvg < 1 ? '~1 min' : "~{$delayAvg} min";

            $methodTypeKey = $q['method_type'] ?? $methodType;
            $methodIcon = $methodIcons[$methodTypeKey] ?? "🌐 {$methodTypeKey}";

            $routes[] = [
                'id'                => $label,
                'badge'             => $badge,
                'badgeCls'          => $badgeCls,
                'provider'          => $q['provider_name'],
                'providerSlug'      => $q['provider_slug'],
                'method'            => $methodIcon,
                'methodIcon'        => $methodIcon,
                'received'          => number_format($received, 0, ',', ' ') . ' ' . $destCurrency,
                'receivedNum'       => $received,
                'fees'              => number_format($fees, 2, ',', ' ') . ' EUR',
                'feesNum'           => $fees,
                'delay'             => $delayText,
                'delayMinutes'      => $delayAvg,
                'reliability'         => $reliabilityText,
                'reliabilityNum'      => $reliabilityNum,
                'reliabilityColor'    => $reliabilityColor,
                // L'interface doit pouvoir distinguer « fiabilité mesurée »
                // de « pas encore d'historique » sans le deviner.
                'reliabilityStatus'   => $reliabilityStatus,
                'reliabilityMeasured' => $reliabilityNum !== null,
                'reliabilityObs'      => (int) ($q['reliability_obs'] ?? 0),
                'recommended'       => $isFirst,
                'spread'            => number_format((float) ($q['spread_pct'] ?? 0), 2) . '%',
                'rate'              => (float) ($q['effective_rate'] ?? 0),
            ];
        }

        return $routes;
    }

    /**
     * Génère un badge contextuel pour les routes non-recommandées
     * en se basant sur leur point fort relatif.
     */
    private static function contextBadge(array $q, array $best): array
    {
        $receivedDiff = (float) $q['received'] - (float) $best['received'];

        if ($receivedDiff > 1000) {
            return ['badge' => '💰 MAX REÇU', 'cls' => 'p-g'];
        }
        // Badge « PLUS FIABLE » : uniquement si les DEUX fiabilités sont
        // réellement mesurées. Le comparatif s'appuyait sur `(float) null`,
        // ce qui décernait le badge à partir de scores inventés — c'est
        // précisément ce que l'audit a constaté en HTTP.
        if ($q['reliability'] !== null
            && $best['reliability'] !== null
            && (float) $q['reliability'] > (float) $best['reliability']
        ) {
            return ['badge' => '🛡️ PLUS FIABLE', 'cls' => 'p-v'];
        }
        if ((int) $q['delay_avg'] < (int) $best['delay_avg']) {
            return ['badge' => '⚡ PLUS RAPIDE', 'cls' => 'p-c'];
        }
        if ((float) $q['fees'] < (float) $best['fees']) {
            return ['badge' => '💸 MOINS CHER', 'cls' => 'p-g'];
        }
        return ['badge' => 'ALTERNATIVE', 'cls' => 'p-c'];
    }

    /**
     * Convertit un score de fiabilité (0-1) en label texte.
     */
    private static function reliabilityLabel(float $score): string
    {
        if ($score >= 0.96) return 'Élevée';
        if ($score >= 0.90) return 'Bonne';
        if ($score >= 0.80) return 'Moyenne';
        return 'Modérée';
    }

    /**
     * Convertit un score de fiabilité (0-1) en couleur CSS.
     */
    private static function reliabilityColor(float $score): string
    {
        if ($score >= 0.96) return 'var(--green)';
        if ($score >= 0.90) return 'var(--cyan)';
        if ($score >= 0.80) return 'var(--gold)';
        return 'var(--orange)';
    }
}
