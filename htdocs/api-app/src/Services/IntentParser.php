<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;

/**
 * Intent Parser — valide et normalise l'intention utilisateur.
 *
 * Étape 1 du pipeline Quote → Routing. Reçoit le payload brut du
 * formulaire /send et retourne un tableau normalisé prêt pour le
 * Capability Engine.
 *
 * Champs requis :
 *   - amount         : montant numérique > 0
 *   - sourceCurrency : devise source (EUR, USD, GBP, etc.)
 *   - destCountry    : code ISO-2 du pays de destination
 *   - destCurrency   : devise de réception
 *   - receivingMethod: mobile_money | bank | crypto | cash_pickup
 *
 * Champs optionnels :
 *   - objective      : optimized | fastest | max_received | cheapest
 *   - action         : send | recharge | receive
 */
final class IntentParser
{
    /** Devises source autorisées (mêmes que Currency::WALLET_CURRENCIES). */
    private const VALID_SOURCE_CURRENCIES = [
        'EUR', 'USD', 'GBP', 'XAF', 'XOF', 'USDT', 'USDC', 'ETH', 'BTC',
    ];

    /** Modes de réception autorisés. */
    private const VALID_METHODS = [
        'mobile_money', 'bank', 'crypto', 'cash_pickup',
    ];

    /** Objectifs de routing. */
    private const VALID_OBJECTIVES = [
        'optimized', 'fastest', 'max_received', 'cheapest', 'most_reliable',
    ];

    /** Plafond minimum (ne peut pas envoyer 0). */
    private const MIN_AMOUNT = 0.01;

    /** Plafond maximum (devise de démo). */
    private const MAX_AMOUNT = 50000;

    /**
     * Valide et normalise l'intention brute.
     *
     * @param array{amount?: mixed, sourceCurrency?: mixed, destCountry?: mixed,
     *              destCurrency?: mixed, receivingMethod?: mixed, objective?: mixed} $raw
     *
     * @return array{amount: float, sourceCurrency: string, destCountry: string,
     *               destCurrency: string, receivingMethod: string, objective: string}
     *
     * @throws HttpException 400 si les champs requis sont manquants ou invalides.
     */
    public static function parse(array $raw): array
    {
        // ── Montant ──────────────────────────────────────────────
        $amount = (float) ($raw['amount'] ?? 0);
        if ($amount <= self::MIN_AMOUNT) {
            throw new HttpException(400, 'Le montant doit être supérieur à 0.', 'VALIDATION_ERROR');
        }
        if ($amount > self::MAX_AMOUNT) {
            throw new HttpException(400, 'Le montant dépasse le plafond autorisé (' . self::MAX_AMOUNT . ' EUR).', 'VALIDATION_ERROR');
        }
        $amount = round($amount, 2);

        // ── Devise source ────────────────────────────────────────
        $sourceCurrency = strtoupper(trim((string) ($raw['sourceCurrency'] ?? '')));
        if ($sourceCurrency === '' || !in_array($sourceCurrency, self::VALID_SOURCE_CURRENCIES, true)) {
            throw new HttpException(400, 'Devise source invalide ou non supportée.', 'VALIDATION_ERROR');
        }

        // ── Pays de destination ──────────────────────────────────
        $destCountry = strtoupper(trim((string) ($raw['destCountry'] ?? '')));
        if (strlen($destCountry) !== 2) {
            throw new HttpException(400, 'Le pays de destination doit être un code ISO-2 (2 caractères).', 'VALIDATION_ERROR');
        }

        // ── Devise de réception ──────────────────────────────────
        $destCurrency = strtoupper(trim((string) ($raw['destCurrency'] ?? '')));
        if ($destCurrency === '' || strlen($destCurrency) > 5) {
            throw new HttpException(400, 'Devise de réception invalide.', 'VALIDATION_ERROR');
        }

        // ── Mode de réception ────────────────────────────────────
        $receivingMethod = strtolower(trim((string) ($raw['receivingMethod'] ?? '')));
        if ($receivingMethod === '' || !in_array($receivingMethod, self::VALID_METHODS, true)) {
            throw new HttpException(400, 'Mode de réception invalide (attendu : ' . implode(', ', self::VALID_METHODS) . ').', 'VALIDATION_ERROR');
        }

        // ── Objectif (optionnel, défaut = optimized) ─────────────
        $objective = strtolower(trim((string) ($raw['objective'] ?? 'optimized')));
        if (!in_array($objective, self::VALID_OBJECTIVES, true)) {
            $objective = 'optimized';
        }

        return [
            'amount'          => $amount,
            'sourceCurrency'  => $sourceCurrency,
            'destCountry'     => $destCountry,
            'destCurrency'    => $destCurrency,
            'receivingMethod' => $receivingMethod,
            'objective'       => $objective,
        ];
    }
}
