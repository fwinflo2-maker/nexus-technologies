<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Execution\ExecutionEnvironment;

/**
 * SanctionsScreening — filtrage des sanctions, avec état explicite (§37).
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ────────────────────────────
 * Le contrôle des sanctions vivait dans `PolicyEngine` sous la forme d'une
 * constante VIDE parcourue par une boucle :
 *
 *     private const SANCTION_LIST = [];        // « simulation démo »
 *     foreach (self::SANCTION_LIST as $entry) { … }
 *
 * Zéro itération, zéro contrôle — puis le moteur retournait
 * « Tous les contrôles de conformité sont passés ». C'est le motif interdit
 * par la règle d'honnêteté : déclarer un succès pour une opération qui n'a
 * jamais eu lieu. Sur un chemin qui déplace de l'argent réel (Send Personal
 * et paiements Business passent tous deux par `PolicyEngine::evaluate`), un
 * contrôle réglementaire fictif est un risque de conformité, pas une dette.
 *
 * LES TROIS ÉTATS POSSIBLES
 * ─────────────────────────
 * Le filtrage ne peut pas mentir : il rend toujours l'un de ces trois états,
 * et l'appelant est obligé de les distinguer.
 *
 *   CLEARED      la cible a été comparée à une liste réellement chargée,
 *                aucune correspondance.
 *   HIT          correspondance : la transaction doit être refusée.
 *   UNAVAILABLE  aucune source de données n'est configurée. Le contrôle
 *                n'a PAS eu lieu, et le système le dit.
 *
 * `UNAVAILABLE` n'est jamais traduit en `CLEARED`. C'est tout l'objet de ce
 * composant : l'absence de liste devient un état visible, pas un succès
 * silencieux.
 *
 * FAIL-CLOSED EN PRODUCTION
 * ─────────────────────────
 * L'arbitrage de `UNAVAILABLE` dépend de l'argent en jeu, et suit la doctrine
 * déjà appliquée par `ProductionAuthorizationPolicy` (deny by default) :
 *
 *   production → REFUS. Déplacer de l'argent réel sans avoir filtré les
 *                sanctions n'est pas acceptable ; dans le doute, on refuse.
 *   sandbox    → REVIEW_REQUIRED. La sandbox ne déplace pas d'argent réel :
 *                la bloquer rendrait tout développement impossible. Mais le
 *                verdict porte la mention explicite que le filtrage n'a pas
 *                eu lieu — jamais « contrôles passés ».
 *
 * SOURCE DE DONNÉES
 * ─────────────────
 * La liste est fournie par l'environnement, jamais codée en dur ni versionnée
 * (une liste de sanctions périmée dans Git est pire qu'aucune liste) :
 *
 *   NEXUS_SANCTIONS_COUNTRIES  codes ISO-2 séparés par des virgules
 *   NEXUS_SANCTIONS_LIST_FILE  fichier, une entrée par ligne, `#` = commentaire
 *
 * Le format « codes pays » est délibérément minimal et ne prétend pas
 * remplacer un screening nominatif OFAC/UE/ONU. Brancher un vrai provider
 * (Dow Jones, ComplyAdvantage…) consiste à remplacer `loadCountryList()` :
 * les trois états et le comportement fail-closed restent valides.
 */
final class SanctionsScreening
{
    /** La cible a été comparée à une liste réelle : aucune correspondance. */
    public const CLEARED = 'CLEARED';

    /** Correspondance trouvée : la transaction doit être refusée. */
    public const HIT = 'HIT';

    /** Aucune source configurée : le contrôle n'a PAS eu lieu. */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /** Liste de pays sanctionnés, en clair dans l'environnement. */
    public const ENV_COUNTRIES = 'NEXUS_SANCTIONS_COUNTRIES';

    /** Chemin d'un fichier de liste (une entrée par ligne). */
    public const ENV_LIST_FILE = 'NEXUS_SANCTIONS_LIST_FILE';

    private function __construct()
    {
    }

    /**
     * Filtre une destination contre la liste de sanctions configurée.
     *
     * @param string $destCountry Code ISO-2 du pays de destination.
     *
     * @return array{status: string, screened: bool, source: string,
     *               entries: int, matched: ?string}
     *         `screened` dit si un contrôle a réellement eu lieu. Il est faux
     *         quand `status` vaut UNAVAILABLE, et l'appelant ne doit alors
     *         présenter aucun contrôle comme effectué.
     */
    public static function screenCountry(string $destCountry): array
    {
        $list = self::loadCountryList();

        // Aucune source configurée : on ne peut rien affirmer.
        if ($list === null) {
            return [
                'status'   => self::UNAVAILABLE,
                'screened' => false,
                'source'   => 'none',
                'entries'  => 0,
                'matched'  => null,
            ];
        }

        $target = strtoupper(trim($destCountry));

        // Une liste configurée mais vide reste une liste : le contrôle a bien
        // eu lieu (l'exploitant a explicitement déclaré « aucun pays »).
        foreach ($list['entries'] as $entry) {
            if ($entry !== '' && $entry === $target) {
                return [
                    'status'   => self::HIT,
                    'screened' => true,
                    'source'   => $list['source'],
                    'entries'  => count($list['entries']),
                    'matched'  => $entry,
                ];
            }
        }

        return [
            'status'   => self::CLEARED,
            'screened' => true,
            'source'   => $list['source'],
            'entries'  => count($list['entries']),
            'matched'  => null,
        ];
    }

    /**
     * Un filtrage indisponible doit-il bloquer, dans cet environnement ?
     *
     * Production : oui, toujours. Sandbox : non, mais le verdict le signale.
     */
    public static function unavailableBlocks(ExecutionEnvironment $environment): bool
    {
        return $environment === ExecutionEnvironment::PRODUCTION;
    }

    /** Le filtrage est-il configuré (au moins une source lisible) ? */
    public static function isConfigured(): bool
    {
        return self::loadCountryList() !== null;
    }

    /**
     * Charge la liste depuis l'environnement.
     *
     * @return array{entries: list<string>, source: string}|null
     *         `null` si aucune source n'est configurée — jamais un tableau
     *         vide, qui serait indiscernable d'une liste réellement vide.
     */
    private static function loadCountryList(): ?array
    {
        $inline = trim((string) (getenv(self::ENV_COUNTRIES) ?: ''));
        if ($inline !== '') {
            return [
                'entries' => self::normalize(explode(',', $inline)),
                'source'  => self::ENV_COUNTRIES,
            ];
        }

        $file = trim((string) (getenv(self::ENV_LIST_FILE) ?: ''));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $entries = [];
                foreach ($lines as $line) {
                    // `#` introduit un commentaire (même convention que le
                    // manifeste de migrations).
                    $line = trim((string) preg_replace('/#.*$/', '', $line));
                    if ($line !== '') {
                        $entries[] = $line;
                    }
                }
                return [
                    'entries' => self::normalize($entries),
                    'source'  => self::ENV_LIST_FILE,
                ];
            }
        }

        // Un fichier déclaré mais illisible ne doit surtout pas être traité
        // comme « pas de sanctions » : on retombe sur UNAVAILABLE.
        return null;
    }

    /**
     * Normalise des entrées de liste (majuscules, sans doublon ni vide).
     *
     * @param list<string> $raw
     * @return list<string>
     */
    private static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $entry = strtoupper(trim($entry));
            if ($entry !== '' && !in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
