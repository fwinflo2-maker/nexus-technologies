<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;

/**
 * Funding Source Engine — calcule les origines autorisées pour un utilisateur.
 *
 * Séparation stricte des concepts :
 *   1. Pays de résidence     → donnée KYC (users.country_of_residence)
 *   2. Origine des fonds     → dérivée des sources de financement vérifiées
 *   3. Pays de destination   → couverture providers (IntentEngine, inchangé)
 *
 * Formule de calcul des origines autorisées :
 *
 *   authorized_origins =
 *       verified_user_funding_sources
 *       ∩ provider_supported_countries
 *       ∩ compliance_allowed_countries
 *       ∩ user_allowed_countries
 *
 * Le frontend ne décide JAMAIS des origines disponibles.
 * Tout value envoyée par le client est re-validée côté serveur.
 */
final class FundingSourceEngine
{
    /**
     * Pays autorisés par les règles de conformité NEXUS (MVP).
     *
     * Cette liste représente les pays dans lesquels NEXUS est autorisé
     * à opérer conformément aux réglementations en vigueur.
     * Un pays peut être supporté par un provider mais ne pas être dans
     * cette liste → il ne sera PAS proposé comme origine.
     */
    private const COMPLIANCE_ALLOWED_COUNTRIES = [
        // Europe
        'FR', 'DE', 'ES', 'IT', 'PT', 'BE', 'NL', 'LU', 'IE', 'AT',
        'FI', 'GR', 'CH', 'GB',
        // Afrique (principaux corridors)
        'CG', 'CD', 'CM', 'GA', 'GQ', 'CF', 'TD',
        'SN', 'CI', 'ML', 'BF', 'NE', 'TG', 'BJ',
        'NG', 'GH', 'KE', 'TZ', 'UG', 'RW', 'ZM', 'ZW', 'ZA',
        'MA', 'TN',
        // Amériques
        'US', 'CA', 'BR', 'MX',
        // Moyen-Orient
        'AE', 'SA', 'QA', 'JO',
        // Asie-Pacifique
        'IN', 'JP', 'SG', 'AU', 'NZ',
    ];

    private function __construct() {}

    /**
     * Calcule les origines autorisées pour un utilisateur donné.
     *
     * Retourne un tableau structuré :
     * [
     *   {
     *     country: 'CG',
     *     countryName: 'Congo',
     *     flag: '🇨🇬',
     *     currency: 'XAF',
     *     sources: [
     *       { id: 1, kind: 'mobile_money', label: '...', ... },
     *     ]
     *   },
     *   ...
     * ]
     *
     * @param int   $userId Identifiant de l'utilisateur authentifié
     * @param array $user   Données utilisateur (inclut kyc_level, country_of_residence, etc.)
     * @return list<array{country: string, countryName: string, flag: string, currency: string, sources: list<array>}>
     */
    public static function getAuthorizedOrigins(int $userId, array $user): array
    {
        // ── 1. Sources de financement vérifiées de l'utilisateur ────────
        $verifiedSources = self::getVerifiedFundingSources($userId);

        if (empty($verifiedSources)) {
            return [];
        }

        // ── 2. Pays supportés par les providers (catalogue NEXUS) ───────
        $providerCountries = self::getProviderSupportedSourceCountries();

        // ── 3. Intersection : sources vérifiées ∩ providers ─────────────
        $candidateOrigins = [];
        foreach ($verifiedSources as $source) {
            $country = strtoupper($source['country'] ?? '');
            if ($country === '' || strlen($country) !== 2) {
                continue;
            }
            if (!isset($providerCountries[$country])) {
                continue;
            }
            if (!isset($candidateOrigins[$country])) {
                $candidateOrigins[$country] = [
                    'country'     => $country,
                    'sources'     => [],
                ];
            }
            $candidateOrigins[$country]['sources'][] = $source;
        }

        // ── 4. Filtre conformité ────────────────────────────────────────
        $complianceAllowed = array_flip(self::COMPLIANCE_ALLOWED_COUNTRIES);
        foreach ($candidateOrigins as $country => $_) {
            if (!isset($complianceAllowed[$country])) {
                unset($candidateOrigins[$country]);
            }
        }

        // ── 5. Enrichissement avec données de référence ─────────────────
        $origins = [];
        foreach ($candidateOrigins as $country => $data) {
            $ref = IntentEngine::COUNTRY_DATA[$country] ?? null;
            $origins[] = [
                'country'     => $country,
                'countryName' => $ref['name'] ?? $country,
                'flag'        => self::flagEmoji($country),
                'currency'    => $ref['currency'] ?? $data['sources'][0]['currency'] ?? 'USD',
                'sources'     => array_map([self::class, 'formatSource'], $data['sources']),
            ];
        }

        // Tri : pays de résidence KYC en premier, puis alphabétique
        $residence = strtoupper($user['country_of_residence'] ?? '');
        usort($origins, static function (array $a, array $b) use ($residence): int {
            if ($a['country'] === $residence && $b['country'] !== $residence) return -1;
            if ($b['country'] === $residence && $a['country'] !== $residence) return 1;
            return mb_strtolower($a['countryName']) <=> mb_strtolower($b['countryName']);
        });

        return $origins;
    }

    /**
     * Valide qu'un pays d'origine spécifique est autorisé pour l'utilisateur.
     *
     * Retourne :
     *   ['authorized' => true,  'sources' => [...]]
     *   ['authorized' => false, 'reason'  => '...']
     *
     * @param int    $userId        Identifiant utilisateur
     * @param array  $user          Données utilisateur
     * @param string $originCountry Code ISO-2 du pays d'origine revendiqué
     * @return array{authorized: bool, reason?: string, sources?: list<array>}
     */
    public static function validateOrigin(int $userId, array $user, string $originCountry): array
    {
        $originCountry = strtoupper(trim($originCountry));

        // Validation format
        if (strlen($originCountry) !== 2) {
            return [
                'authorized' => false,
                'reason'     => 'Code pays d\'origine invalide.',
            ];
        }

        // Calcul des origines autorisées
        $authorized = self::getAuthorizedOrigins($userId, $user);

        // Recherche de l'origine demandée
        foreach ($authorized as $origin) {
            if ($origin['country'] === $originCountry) {
                return [
                    'authorized' => true,
                    'sources'    => $origin['sources'],
                ];
            }
        }

        // Origine non trouvée → message explicite
        return [
            'authorized' => false,
            'reason'     => 'Cette origine de transfert n\'est pas disponible pour votre compte. '
                          . 'Ajoutez et vérifiez un moyen de paiement pris en charge dans ce pays '
                          . 'pour pouvoir envoyer des fonds depuis cette origine.',
        ];
    }

    /**
     * Récupère les sources de financement vérifiées et actives de l'utilisateur.
     *
     * Critères :
     *   - role = 'source'
     *   - verification_status = 'verified'
     *   - status = 'active'
     *   - supported_for_transfer = 1
     *
     * @return list<array>
     */
    private static function getVerifiedFundingSources(int $userId): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT id, kind, label, holder_name, country, currency,
                    operator, network, is_default, provider_slug,
                    verification_status, supported_for_transfer, status,
                    created_at, updated_at
             FROM payment_accounts
             WHERE user_id = :uid
               AND role = \'source\'
               AND verification_status = \'verified\'
               AND status = \'active\'
               AND supported_for_transfer = 1
             ORDER BY is_default DESC, created_at DESC'
        );
        $stmt->execute(['uid' => $userId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retourne la liste des pays supportés comme source par les providers.
     *
     * Extrait du ProviderCatalog les pays dans lesquels les providers
     * peuvent opérer en tant que source de financement.
     *
     * @return array<string, true>  Pays en clé (ISO-2)
     */
    private static function getProviderSupportedSourceCountries(): array
    {
        $countries = [];

        foreach (ProviderCatalog::all() as $provider) {
            $expanded = self::expandCountries($provider['countries'] ?? []);
            foreach ($expanded as $cc) {
                $countries[$cc] = true;
            }
        }

        return $countries;
    }

    /**
     * Déplie les codes régionaux (ex: 'EU') en pays individuels.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private static function expandCountries(array $codes): array
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
            'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];

        $expanded = [];
        foreach ($codes as $code) {
            if ($code === 'EU') {
                foreach ($euCountries as $eu) {
                    $expanded[$eu] = true;
                }
            } else {
                $expanded[$code] = true;
            }
        }
        return array_keys($expanded);
    }

    /**
     * Formate une source de financement pour l'API publique.
     */
    private static function formatSource(array $row): array
    {
        $kindLabels = [
            'bank_iban'     => 'Compte bancaire',
            'mobile_money'  => 'Mobile Money',
            'crypto_wallet' => 'Wallet Crypto',
            'card'          => 'Carte bancaire',
            'virtual_iban'  => 'IBAN virtuel',
            'cash_pickup'   => 'Cash Pickup',
        ];

        return [
            'id'       => (int) $row['id'],
            'kind'     => $row['kind'],
            'kindLabel' => $kindLabels[$row['kind']] ?? $row['kind'],
            'label'    => $row['label'],
            'country'  => $row['country'],
            'currency' => $row['currency'],
            'operator' => $row['operator'],
            'isDefault' => (int) ($row['is_default'] ?? 0) === 1,
        ];
    }

    /**
     * Génère l'emoji drapeau à partir d'un code ISO-3166-1 alpha-2.
     */
    private static function flagEmoji(string $code): string
    {
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '🌍';
        }
        $first  = mb_chr(ord($code[0]) - ord('A') + 0x1F1E6);
        $second = mb_chr(ord($code[1]) - ord('A') + 0x1F1E6);
        return ($first !== false && $second !== false) ? $first . $second : '🌍';
    }
}
