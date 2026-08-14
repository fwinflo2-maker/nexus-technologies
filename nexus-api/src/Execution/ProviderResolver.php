<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Providers\ProviderAdapter;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;

/**
 * ProviderResolver — obtention d'un provider utilisable DANS un contexte donné.
 *
 * INVERSION DE DÉPENDANCE FONDAMENTALE
 * ────────────────────────────────────
 * Le sens de la résolution est imposé :
 *
 *     contexte → environnement → credential          (CORRECT)
 *     credential disponible → environnement          (INTERDIT)
 *
 * La seconde forme est celle qui produit les accidents : la simple présence
 * d'une clé de production suffirait à faire exécuter en production une
 * opération demandée en sandbox.
 *
 * Ici, l'environnement est arrêté AVANT toute consultation de credential. Si
 * la credential de cet environnement précis est absente, l'opération échoue
 * proprement — jamais de repli vers l'autre environnement.
 */
final class ProviderResolver
{
    private function __construct()
    {
    }

    /**
     * Résout un adapter utilisable pour ce provider dans ce contexte.
     *
     * @throws HttpException 404 provider inconnu
     *                       409 provider non configuré POUR CET ENVIRONNEMENT
     */
    public static function resolve(string $slug, ExecutionContext $context): ProviderAdapter
    {
        if (!ProviderCatalog::exists($slug)) {
            throw new HttpException(404, 'Provider inconnu : ' . $slug . '.', 'PROVIDER_UNKNOWN');
        }

        $environment = $context->environmentValue();

        // L'environnement est déjà décidé : on EXIGE la credential
        // correspondante. On ne cherche pas « ce qui est disponible ».
        if (!self::hasCredentialFor($slug, $context)) {
            throw new HttpException(
                409,
                sprintf(
                    'Provider « %s » non configuré pour l\'environnement « %s ». '
                    . 'Aucun repli vers l\'autre environnement n\'est effectué.',
                    $slug,
                    $environment
                ),
                'PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT'
            );
        }

        return ProviderRegistry::adapter($slug);
    }

    /**
     * Une credential existe-t-elle pour CE provider dans CET environnement ?
     *
     * Les deux sources possibles sont consultées, chacune strictement scopée :
     *   - variables d'environnement (PROVIDER_{SLUG}_{ENV}_{FIELD}) ;
     *   - credentials chiffrées en base, pour le sujet du contexte.
     *
     * Aucune valeur n'est retournée ni journalisée : uniquement l'existence.
     */
    public static function hasCredentialFor(string $slug, ExecutionContext $context): bool
    {
        $provider    = ProviderCatalog::get($slug);
        $environment = $context->environmentValue();

        if ($provider === null) {
            return false;
        }

        // 1) Variables d'environnement, scopées.
        //    Tous les champs REQUIS doivent être présents dans cet
        //    environnement. Un provider sans champ requis déclaré ne peut pas
        //    être considéré comme configuré « par défaut » : il faut au moins
        //    un champ effectivement renseigné.
        $required  = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            if (($field['required'] ?? false) === true) {
                $required[] = (string) $field['key'];
            }
        }

        if ($required !== []) {
            $allPresent = true;
            foreach ($required as $key) {
                if (ProviderConfig::credential($slug, $key, $environment) === null) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                return true;
            }
        }

        // 2) Credentials chiffrées en base, pour le sujet et cet environnement.
        //    findRow() n'effectue AUCUN déchiffrement : tester la présence ne
        //    justifie pas de manipuler des secrets en clair.
        $row = ProviderCredentialService::findRow(
            Database::getConnection(),
            $context->subjectUserId,
            $slug,
            $environment
        );

        return $row !== null && ($row['credentials_enc'] ?? null) !== null;
    }

    /**
     * Providers réellement utilisables dans ce contexte.
     *
     * Sert au routing : ne jamais proposer une route dont le provider n'est
     * pas configuré POUR l'environnement d'exécution retenu.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function usableSlugs(array $slugs, ExecutionContext $context): array
    {
        $usable = [];
        foreach ($slugs as $slug) {
            if (self::hasCredentialFor($slug, $context)) {
                $usable[] = $slug;
            }
        }

        return $usable;
    }
}
