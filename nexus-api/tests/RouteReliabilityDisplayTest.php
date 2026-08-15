<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\ProviderReliability;
use Nexus\Services\RoutingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le CLIENT voit de la fiabilité (boucle 13).
 *
 * `ProviderReliabilityTest` vérifie la mesure ; ici on vérifie sa
 * PRÉSENTATION, car c'est là que le mensonge était visible : l'API renvoyait
 * `reliability: "Élevée"`, `reliabilityNum: 0.97` et le badge
 * « 🛡️ PLUS FIABLE » pour des providers jamais observés.
 *
 * Deux pièges spécifiques sont figés ici :
 *
 *  1. `(float) null === 0.0`. Sans garde, un provider non mesuré héritait du
 *     label « Modérée » en orange — une note inventée pour une absence de
 *     donnée.
 *  2. Le classement. Une fiabilité inconnue ne doit ni avantager ni pénaliser :
 *     départager des routes sur un score absent revient à inventer un ordre.
 */
final class RouteReliabilityDisplayTest extends TestCase
{
    /**
     * Construit une quote minimale telle que la produit QuoteEngine.
     *
     * @return array<string, mixed>
     */
    private function quote(
        string $slug,
        ?float $reliability,
        string $status,
        float $received = 65000.0,
        float $fees = 2.9,
        int $delay = 180
    ): array {
        return [
            'provider_slug'      => $slug,
            'provider_name'      => ucfirst($slug),
            'received'           => $received,
            'received_currency'  => 'XAF',
            'fees'               => $fees,
            'fee_currency'       => 'EUR',
            'delay_min'          => 60,
            'delay_max'          => 300,
            'delay_avg'          => $delay,
            'reliability'        => $reliability,
            'reliability_status' => $status,
            'reliability_obs'    => $reliability === null ? 0 : 40,
            'spread_pct'         => 0.93,
            'effective_rate'     => 650.0,
            'method_type'        => 'mobile_money',
        ];
    }

    /** @param list<array<string,mixed>> $quotes @return list<array<string,mixed>> */
    private function rank(array $quotes, string $objective = 'optimized'): array
    {
        return RoutingEngine::rank($quotes, $objective, 'NX-TEST-0001', 100.0, 'EUR', 'XAF', 'mobile_money');
    }

    public function test_une_fiabilite_non_mesuree_n_affiche_aucun_score(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderReliability::UNAVAILABLE),
        ]);

        $route = $routes[0];

        self::assertSame('Non mesurée', $route['reliability']);
        self::assertNull($route['reliabilityNum'], 'Aucun nombre ne doit être publié sans mesure.');
        self::assertFalse($route['reliabilityMeasured']);
        self::assertSame(ProviderReliability::UNAVAILABLE, $route['reliabilityStatus']);
    }

    /**
     * Le piège `(float) null` : sans garde, l'absence de mesure devenait 0.0,
     * donc le label « Modérée » — une note pour un provider jamais observé.
     */
    public function test_une_fiabilite_absente_n_est_pas_traduite_en_mauvaise_note(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderReliability::UNAVAILABLE),
        ]);

        self::assertNotSame('Modérée', $routes[0]['reliability']);
        self::assertNotSame(0.0, $routes[0]['reliabilityNum']);
    }

    public function test_une_fiabilite_mesuree_est_affichee_avec_son_score(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', 0.97, ProviderReliability::MEASURED),
        ]);

        self::assertSame('Élevée', $routes[0]['reliability']);
        self::assertSame(0.97, $routes[0]['reliabilityNum']);
        self::assertTrue($routes[0]['reliabilityMeasured']);
        self::assertSame(40, $routes[0]['reliabilityObs']);
    }

    /**
     * Le badge constaté en HTTP pendant l'audit : décerné à pawaPay alors
     * qu'aucune fiabilité n'était mesurée.
     */
    public function test_le_badge_PLUS_FIABLE_exige_deux_mesures_reelles(): void
    {
        $routes = $this->rank([
            // La 2e reçoit moins et est plus lente : elle ne peut pas gagner
            // un badge « MAX REÇU » ou « PLUS RAPIDE ».
            $this->quote('alpha', null, ProviderReliability::UNAVAILABLE, 66000.0, 2.0, 100),
            $this->quote('beta', null, ProviderReliability::UNAVAILABLE, 64000.0, 3.0, 400),
        ]);

        foreach ($routes as $route) {
            self::assertStringNotContainsString(
                'PLUS FIABLE',
                $route['badge'],
                'Aucun badge de fiabilité ne doit être décerné sans mesure.'
            );
        }
    }

    public function test_le_badge_PLUS_FIABLE_reste_possible_entre_deux_mesures(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', 0.80, ProviderReliability::MEASURED, 66000.0, 2.0, 100),
            $this->quote('beta', 0.99, ProviderReliability::MEASURED, 64000.0, 3.0, 400),
        ]);

        $badges = array_column($routes, 'badge');
        self::assertNotEmpty(
            array_filter($badges, static fn ($b) => str_contains((string) $b, 'PLUS FIABLE')),
            'Entre deux fiabilités mesurées, le comparatif garde tout son sens.'
        );
    }

    /**
     * Neutralité du classement : sans mesure, la composante fiabilité ne doit
     * favoriser personne. Ici seul le montant reçu diffère, donc la meilleure
     * route est celle qui reçoit le plus — et non un favori arbitraire.
     */
    public function test_sans_mesure_le_classement_ne_depend_que_des_criteres_reels(): void
    {
        $routes = $this->rank([
            $this->quote('petit', null, ProviderReliability::UNAVAILABLE, 60000.0),
            $this->quote('grand', null, ProviderReliability::UNAVAILABLE, 70000.0),
        ], 'max_received');

        self::assertSame('grand', $routes[0]['providerSlug']);
        self::assertTrue($routes[0]['recommended']);
    }

    /**
     * `most_reliable` accorde 55 % du poids à la fiabilité. Sans mesure, ce
     * poids doit rester neutre plutôt que de trancher au hasard.
     */
    public function test_objectif_most_reliable_sans_mesure_reste_neutre(): void
    {
        $routes = $this->rank([
            $this->quote('petit', null, ProviderReliability::UNAVAILABLE, 60000.0),
            $this->quote('grand', null, ProviderReliability::UNAVAILABLE, 70000.0),
        ], 'most_reliable');

        // Aucune fiabilité n'étant connue, les autres critères décident.
        self::assertSame('grand', $routes[0]['providerSlug']);

        foreach ($routes as $route) {
            self::assertFalse(
                $route['reliabilityMeasured'],
                'Aucune route ne doit prétendre à une fiabilité mesurée.'
            );
        }
    }

    /**
     * Le badge d'OBJECTIF est une seconde source de « PLUS FIABLE », distincte
     * du badge contextuel — et elle n'était pas couverte par les tests
     * unitaires initiaux : c'est en rejouant la requête HTTP réelle que le
     * badge est réapparu, alors que le score affichait déjà « Non mesurée ».
     *
     * Demander `most_reliable` ne prouve rien : sans mesure, le badge tombe
     * sur le libellé neutre.
     */
    public function test_objectif_most_reliable_sans_mesure_ne_decerne_pas_le_badge(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderReliability::UNAVAILABLE, 66000.0),
            $this->quote('beta', null, ProviderReliability::UNAVAILABLE, 64000.0),
        ], 'most_reliable');

        foreach ($routes as $route) {
            self::assertStringNotContainsString(
                'PLUS FIABLE',
                $route['badge'],
                'Le badge d\'objectif ne doit pas affirmer une fiabilité jamais mesurée.'
            );
        }
    }

    /**
     * Dès qu'une fiabilité est réellement mesurée, l'objectif retrouve son
     * badge : la correction ne supprime pas la fonctionnalité, elle exige
     * qu'elle repose sur un fait.
     */
    public function test_objectif_most_reliable_avec_mesure_decerne_le_badge(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', 0.98, ProviderReliability::MEASURED, 66000.0),
            $this->quote('beta', 0.70, ProviderReliability::MEASURED, 64000.0),
        ], 'most_reliable');

        self::assertStringContainsString('PLUS FIABLE', (string) $routes[0]['badge']);
    }

    /**
     * Cas mixte : une route mesurée, une autre non. La mesurée expose son
     * score, l'autre reste explicitement inconnue.
     */
    public function test_mesure_et_absence_de_mesure_coexistent_sans_confusion(): void
    {
        $routes = $this->rank([
            $this->quote('mesure', 0.92, ProviderReliability::MEASURED),
            $this->quote('inconnu', null, ProviderReliability::INSUFFICIENT_DATA),
        ]);

        $bySlug = [];
        foreach ($routes as $route) {
            $bySlug[$route['providerSlug']] = $route;
        }

        self::assertTrue($bySlug['mesure']['reliabilityMeasured']);
        self::assertSame(0.92, $bySlug['mesure']['reliabilityNum']);

        self::assertFalse($bySlug['inconnu']['reliabilityMeasured']);
        self::assertNull($bySlug['inconnu']['reliabilityNum']);
        self::assertSame(
            ProviderReliability::INSUFFICIENT_DATA,
            $bySlug['inconnu']['reliabilityStatus']
        );
    }
}
