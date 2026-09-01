<?php

declare(strict_types=1);

namespace Nexus\Services;

use DateTimeImmutable;
use Nexus\Models\FXRate;

/**
 * FXProviderInterface — contrat vendor-indépendant d'une source de taux (Cycle 5).
 *
 * Toute source FX (parité officielle fixe, banque centrale, vendor de marché)
 * doit s'y conformer AVANT d'être branchée. Le pipeline reste :
 *
 *   FXService → [provider autoritaire si la paire est couverte]
 *             → FXRateCache (scopé environnement, horodaté, expirant)
 *             → sinon REFUS (`FX_RATE_UNAVAILABLE`)
 *
 * Règles non négociables :
 *   - un provider ne renvoie JAMAIS de valeur inventée : paire non couverte
 *     ou source indisponible → null (le fail-closed reste en aval) ;
 *   - chaque taux porte sa provenance (`source`), son horodatage
 *     (`fetched_at`) et son expiration (`expires_at`) — un taux sans
 *     provenance vérifiable n'existe pas ;
 *   - le cache est la trace auditable : chaque écriture est attribuée.
 */
interface FXProviderInterface
{
    /**
     * Identifiant stable de la source (colonne `fx_rates_cache.source`,
     * varchar(50)). Ex. : 'official_peg_bdf_cfa'.
     */
    public function getSource(): string;

    /**
     * Métadonnées d'une paire si la source la couvre, sinon null.
     *
     * @return array{base:string, quote:string, kind:string, ttl_seconds:int}|null
     *         kind : 'fixed_peg' (parité officielle) | 'market' (vendor).
     */
    public function getPair(string $baseCurrency, string $quoteCurrency): ?array;

    /**
     * Taux frais pour une paire couverte, ou null (paire inconnue ou source
     * indisponible — jamais d'invention).
     */
    public function getRate(string $baseCurrency, string $quoteCurrency): ?FXRate;

    /**
     * Horodatage de la dernière publication de la source (pour une parité
     * fixe : l'instant de dérivation ; pour un vendor : la publication réelle).
     */
    public function getTimestamp(): ?DateTimeImmutable;

    /**
     * État de santé honnête de la source.
     *
     * @return array{
     *   source:string,
     *   configured:bool,
     *   kind:string,
     *   reachable:bool,
     *   pairs:list<string>,
     *   provenance:string,
     *   ladder:string
     * }
     */
    public function health(): array;
}
