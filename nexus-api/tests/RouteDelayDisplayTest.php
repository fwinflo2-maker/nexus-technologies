<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\ProviderLatency;
use Nexus\Services\ProviderReliability;
use Nexus\Services\RoutingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le CLIENT voit du délai (boucle 14).
 *
 * `ProviderLatencyTest` vérifie la mesure ; ici on vérifie sa PRÉSENTATION,
 * car c'est là que le faux affichage était visible : l'API renvoyait
 * « ~3 min » et le badge « ⚡ PLUS RAPIDE » sur une base ne contenant aucune
 * transaction.
 *
 * Deux pièges spécifiques sont figés :
 *
 *  1. `(int) null === 0`. Sans garde, un provider jamais chronométré valait
 *     zéro minute — soit le plus rapide de tous. Une absence de mesure
 *     devenait un avantage au classement.
 *  2. Le badge d'objectif. `fastest` décernait « ⚡ PLUS RAPIDE » sur le seul
 *     objectif demandé, sans qu'aucun délai ne soit mesuré.
 */
final class RouteDelayDisplayTest extends TestCase
{
    /**
     * Quote minimale telle que la produit QuoteEngine.
     *
     * @return array<string, mixed>
     */
    private function quote(
        string $slug,
        ?int $delayMinutes,
        string $delayStatus,
        float $received = 65000.0,
        float $fees = 2.9
    ): array {
        return [
            'provider_slug'      => $slug,
            'provider_name'      => ucfirst($slug),
            'received'           => $received,
            'received_currency'  => 'XAF',
            'fees'               => $fees,
            'fee_currency'       => 'EUR',
            'delay_seconds'      => $delayMinutes === null ? null : $delayMinutes * 60,
            'delay_avg'          => $delayMinutes,
            'delay_status'       => $delayStatus,
            'delay_obs'          => $delayMinutes === null ? 0 : 40,
            'delay_p90_seconds'  => $delayMinutes === null ? null : $delayMinutes * 90,
            // La fiabilité n'est pas le sujet ici : on la neutralise.
            'reliability'        => null,
            'reliability_status' => ProviderReliability::UNAVAILABLE,
            'reliability_obs'    => 0,
            'spread_pct'         => 0.93,
            'effective_rate'     => 650.0,
            'method_type'        => 'mobile_money',
        ];
    }

    /** @param list<array<string,mixed>> $quotes @return list<array<string,mixed>> */
    private function rank(array $quotes, string $objective = 'optimized'): array
    {
        return RoutingEngine::rank($quotes, $objective, 'NX-TEST-0002', 100.0, 'EUR', 'XAF', 'mobile_money');
    }

    public function test_un_delai_non_mesure_n_affiche_aucune_duree(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderLatency::UNAVAILABLE),
        ]);

        $route = $routes[0];

        self::assertSame('Non mesuré', $route['delay']);
        self::assertNull($route['delayMinutes'], 'Aucune durée ne doit être publiée sans mesure.');
        self::assertFalse($route['delayMeasured']);
        self::assertSame(ProviderLatency::UNAVAILABLE, $route['delayStatus']);
    }

    /**
     * Le « ~3 min » exact constaté en HTTP sur une base vide : il provenait de
     * la constante de catégorie, pas d'une mesure.
     */
    public function test_sans_mesure_l_API_n_annonce_plus_la_valeur_de_la_constante(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderLatency::UNAVAILABLE),
        ]);

        self::assertNotSame('~3 min', $routes[0]['delay']);
        self::assertNotSame(3, $routes[0]['delayMinutes']);
        self::assertNotSame(0, $routes[0]['delayMinutes'], '`(int) null` ferait passer l\'inconnu pour instantané.');
    }

    public function test_un_delai_mesure_est_affiche_avec_sa_duree(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', 10, ProviderLatency::MEASURED),
        ]);

        self::assertSame('~10 min', $routes[0]['delay']);
        self::assertSame(10, $routes[0]['delayMinutes']);
        self::assertTrue($routes[0]['delayMeasured']);
        self::assertSame(40, $routes[0]['delayObs']);
    }

    /**
     * Le scénario de l'audit : deux providers aux temps réels opposés doivent
     * afficher deux délais différents — la constante les rendait identiques.
     */
    public function test_deux_providers_aux_temps_opposes_affichent_des_delais_differents(): void
    {
        $routes = $this->rank([
            $this->quote('lent', 10, ProviderLatency::MEASURED),
            $this->quote('rapide', 1, ProviderLatency::MEASURED),
        ]);

        $bySlug = [];
        foreach ($routes as $route) {
            $bySlug[$route['providerSlug']] = $route;
        }

        self::assertSame('~10 min', $bySlug['lent']['delay']);
        self::assertSame('~1 min', $bySlug['rapide']['delay']);
        self::assertNotSame($bySlug['lent']['delay'], $bySlug['rapide']['delay']);
    }

    /**
     * Badge contextuel : constaté en HTTP sur une base vide de transactions.
     */
    public function test_le_badge_PLUS_RAPIDE_exige_deux_mesures_reelles(): void
    {
        $routes = $this->rank([
            // La 2e reçoit moins et coûte plus : elle ne peut pas gagner un
            // badge « MAX REÇU » ou « MOINS CHER ».
            $this->quote('alpha', null, ProviderLatency::UNAVAILABLE, 66000.0, 2.0),
            $this->quote('beta', null, ProviderLatency::UNAVAILABLE, 64000.0, 3.0),
        ]);

        foreach ($routes as $route) {
            self::assertStringNotContainsString(
                'PLUS RAPIDE',
                (string) $route['badge'],
                'Aucun badge de vitesse ne doit être décerné sans mesure.'
            );
        }
    }

    public function test_le_badge_PLUS_RAPIDE_reste_possible_entre_deux_mesures(): void
    {
        $routes = $this->rank([
            $this->quote('lent', 12, ProviderLatency::MEASURED, 66000.0, 2.0),
            $this->quote('rapide', 1, ProviderLatency::MEASURED, 64000.0, 3.0),
        ]);

        $badges = array_map(static fn ($r) => (string) $r['badge'], $routes);
        self::assertNotEmpty(
            array_filter($badges, static fn ($b) => str_contains($b, 'PLUS RAPIDE')),
            'Entre deux délais mesurés, le comparatif garde tout son sens.'
        );
    }

    /**
     * Badge d'OBJECTIF : seconde source, distincte du badge contextuel.
     * C'est le piège qui avait déjà été manqué pour `most_reliable`.
     */
    public function test_objectif_fastest_sans_mesure_ne_decerne_pas_le_badge(): void
    {
        $routes = $this->rank([
            $this->quote('alpha', null, ProviderLatency::UNAVAILABLE, 66000.0),
            $this->quote('beta', null, ProviderLatency::UNAVAILABLE, 64000.0),
        ], 'fastest');

        foreach ($routes as $route) {
            self::assertStringNotContainsString(
                'PLUS RAPIDE',
                (string) $route['badge'],
                'Le badge d\'objectif ne doit pas affirmer une vitesse jamais mesurée.'
            );
        }
    }

    public function test_objectif_fastest_avec_mesure_decerne_le_badge(): void
    {
        $routes = $this->rank([
            $this->quote('rapide', 1, ProviderLatency::MEASURED, 64000.0),
            $this->quote('lent', 15, ProviderLatency::MEASURED, 66000.0),
        ], 'fastest');

        self::assertStringContainsString('PLUS RAPIDE', (string) $routes[0]['badge']);
        self::assertSame('rapide', $routes[0]['providerSlug']);
    }

    /**
     * Neutralité : sans délai mesuré, la composante vitesse (50 % du poids
     * pour `fastest`) ne doit favoriser personne. Seul le montant reçu diffère
     * ici, donc c'est lui qui doit décider.
     */
    public function test_sans_mesure_le_classement_ne_depend_que_des_criteres_reels(): void
    {
        $routes = $this->rank([
            $this->quote('petit', null, ProviderLatency::UNAVAILABLE, 60000.0),
            $this->quote('grand', null, ProviderLatency::UNAVAILABLE, 70000.0),
        ], 'fastest');

        self::assertSame('grand', $routes[0]['providerSlug']);

        foreach ($routes as $route) {
            self::assertFalse(
                $route['delayMeasured'],
                'Aucune route ne doit prétendre à un délai mesuré.'
            );
        }
    }

    /**
     * Cas mixte : un délai mesuré RAPIDE doit devancer un délai inconnu.
     *
     * Subtilité de la normalisation : avec une SEULE valeur mesurée,
     * `min == max` et `norm()` retourne 0.5 — le provider mesuré se retrouve
     * à égalité avec le neutre, faute de point de comparaison. Ce n'est pas
     * un défaut : avec une seule mesure, rien ne permet d'affirmer qu'elle
     * est « rapide » dans l'absolu. Le test fournit donc deux délais mesurés
     * encadrants, situation dans laquelle « rapide » a un sens.
     */
    public function test_un_delai_inconnu_ne_bat_pas_un_delai_mesure_rapide(): void
    {
        $routes = $this->rank([
            $this->quote('inconnu', null, ProviderLatency::UNAVAILABLE),
            $this->quote('mesure_rapide', 1, ProviderLatency::MEASURED),
            $this->quote('mesure_lente', 20, ProviderLatency::MEASURED),
        ], 'fastest');

        self::assertSame(
            'mesure_rapide',
            $routes[0]['providerSlug'],
            'Un provider mesuré rapide doit devancer un provider au délai inconnu.'
        );

        // Et le provider lent, lui, doit passer DERRIÈRE l'inconnu : une
        // lenteur mesurée est une information, l'inconnu reste neutre.
        $order = array_map(static fn ($r) => $r['providerSlug'], $routes);
        self::assertLessThan(
            array_search('mesure_lente', $order, true),
            array_search('inconnu', $order, true),
            'Une lenteur mesurée doit pénaliser davantage qu\'un délai inconnu.'
        );
    }

    public function test_mesure_et_absence_de_mesure_coexistent_sans_confusion(): void
    {
        $routes = $this->rank([
            $this->quote('mesure', 5, ProviderLatency::MEASURED),
            $this->quote('inconnu', null, ProviderLatency::INSUFFICIENT_DATA),
        ]);

        $bySlug = [];
        foreach ($routes as $route) {
            $bySlug[$route['providerSlug']] = $route;
        }

        self::assertTrue($bySlug['mesure']['delayMeasured']);
        self::assertSame(5, $bySlug['mesure']['delayMinutes']);

        self::assertFalse($bySlug['inconnu']['delayMeasured']);
        self::assertNull($bySlug['inconnu']['delayMinutes']);
        self::assertSame(ProviderLatency::INSUFFICIENT_DATA, $bySlug['inconnu']['delayStatus']);
    }
}
