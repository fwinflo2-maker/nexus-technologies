<?php

declare(strict_types=1);

namespace Nexus\Kyc;

/**
 * KycRiskScorer — approche basée sur le risque pour le KYB.
 *
 * SOURCE : recommandations FATF (Rec. 10 & 22) et guides KYB 2026 des grandes
 * fintechs (Sumsub, Wise, Stripe) :
 *
 *   - la due diligence doit être proportionnée au risque (low / medium / high) ;
 *   - les juridictions à haut risque (liste grise/noire FATF) et les secteurs
 *     sensibles déclenchent des contrôles renforcés (Enhanced Due Diligence) ;
 *   - le niveau de risque est DÉTERMINISTE et AUDITABLE : mêmes entrées →
 *     même sortie, sans aléa ni boîte noire.
 *
 * Ce scorer est volontairement simple et transparent : c'est une première
 * couche d'évaluation, pas un modèle de fraude. Les signaux complémentaires
 * (structure de propriété complexe, PEP, adverse media) sont la responsabilité
 * de Sumsub et du Policy Engine.
 */
final class KycRiskScorer
{
    /** Niveaux de risque autorisés (miroir de l'enum SQL users.risk_level). */
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /**
     * Juridictions à haut risque — liste grise/noire FATF + pays sous
     * sanctions globales. Liste indicative, à revoir selon la politique AML
     * interne (elle n'est pas exhaustive et doit être maintenue).
     */
    private const HIGH_RISK_JURISDICTIONS = [
        'KP', // Corée du Nord — liste noire FATF
        'IR', // Iran — liste noire FATF
        'MM', // Myanmar — liste noire FATF
        'SY', // Syrie — sanctions
        'CU', // Cuba — sanctions
        'RU', // Russie — sanctions
        'BY', // Biélorussie — sanctions
        'VE', // Venezuela — sanctions
        'AF', // Afghanistan — sanctions
        'SS', // Soudan du Sud — sanctions
        'YE', // Yémen — sanctions
        'SO', // Somalie — sanctions
        'LY', // Libye — sanctions
        'IQ', // Irak — sanctions
    ];

    /** Juridictions sous surveillance renforcée (liste grise FATF — extrait). */
    private const MEDIUM_RISK_JURISDICTIONS = [
        'CD', 'HT', 'LB', 'MG', 'MZ', 'NI', 'NG', 'PH', 'SN', 'TZ', 'VN', 'ZM', 'ZW',
    ];

    /**
     * Secteurs d'activité sensibles : par nature plus exposés au blanchiment.
     * La correspondance est faite sur des mots-clés du champ users.industry.
     */
    private const HIGH_RISK_INDUSTRY_KEYWORDS = [
        'crypto', 'cryptomonnaie', 'monnaie', 'money service', 'msb', 'transfert',
        'remittance', 'gambling', 'jeu', 'casino', 'pari', 'forex', 'trading',
        'precious metal', 'métaux précieux', 'or', 'diamant', 'armes', 'weapon',
        'défense', 'charité', 'ong', 'association', 'non-profit', 'immobilier',
        'real estate', 'société écran', 'shell', 'fiducie', 'trust',
    ];

    private function __construct()
    {
    }

    /**
     * Évalue le niveau de risque KYB d'un compte Business.
     *
     * @param array<string,mixed> $user Ligne `users` (account_type, country_of_residence, industry…).
     * @return string low|medium|high
     */
    public static function assess(array $user): string
    {
        $country  = strtoupper(trim((string) ($user['country_of_residence'] ?? '')));
        $industry = mb_strtolower(trim((string) ($user['industry'] ?? '')), 'UTF-8');

        // 1) Juridiction à haut risque → high, sans appel.
        if (in_array($country, self::HIGH_RISK_JURISDICTIONS, true)) {
            return self::HIGH;
        }

        // 2) Secteur sensible → high.
        foreach (self::HIGH_RISK_INDUSTRY_KEYWORDS as $keyword) {
            if ($industry !== '' && str_contains($industry, $keyword)) {
                return self::HIGH;
            }
        }

        // 3) Juridiction sous surveillance renforcée → medium.
        if (in_array($country, self::MEDIUM_RISK_JURISDICTIONS, true)) {
            return self::MEDIUM;
        }

        // 4) Par défaut, absence de signal → low.
        return self::LOW;
    }
}
