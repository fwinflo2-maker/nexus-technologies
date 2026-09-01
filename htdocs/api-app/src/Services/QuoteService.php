<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;

/**
 * QuoteService — exécute le pipeline Capability → Policy → Quote → Routing.
 *
 * Factorisation partagée entre le Send Personal (QuoteController) et les
 * paiements Business (BusinessService), afin de garantir UN SEUL comportement
 * de pricing/routing sur toute la plateforme.
 */
final class QuoteService
{
    /** Préfixe des identifiants de quote (cohérent avec QuoteController). */
    private const ID_PREFIX = 'NX';

    /**
     * Calcule et classe les routes pour une intention normalisée.
     *
     * @param array<string,mixed> $user   Utilisateur authentifié (PolicyEngine).
     * @param array<string,mixed> $intent Intention normalisée par IntentParser.
     * @param ExecutionEnvironment|null $environment Environnement d'exécution,
     *        transmis au PolicyEngine : un filtrage de sanctions indisponible
     *        bloque en production et ne fait que signaler en sandbox.
     * @param ExecutionContext|null $context Contexte complet pour la résolution
     *        multi-provider (credentials scopées).
     *
     * @return list<array<string,mixed>> Routes classées (A, B, C…).
     */
    public static function computeRoutes(
        array $user,
        array $intent,
        ?ExecutionEnvironment $environment = null,
        ?ExecutionContext $context = null,
    ): array {
        if ($context === null && $environment !== null) {
            $context = ExecutionContext::explicit(
                actorUserId: (int) ($user['id'] ?? 0),
                environment: $environment,
            );
        }

        // Capability Engine : providers éligibles pour ce corridor.
        $providers = CapabilityEngine::findEligible($intent, $environment, false, $context);

        // Policy Engine : conformité avant tout calcul de prix.
        $sourceToEur = self::rateToEur((string) $intent['sourceCurrency'], $environment);
        $amountRef   = $sourceToEur > 0.0 ? ((float) $intent['amount'] / $sourceToEur) : 0.0;
        PolicyEngine::evaluate($user, $intent, $amountRef, $environment);

        // Quote Engine : une quote par provider éligible.
        $quoteId = self::generateQuoteId();
        $quotes  = [];
        foreach ($providers as $provider) {
            // QuoteRateUnavailable remonte volontairement : un paiement
            // Business ne doit pas davantage être coté sans taux réel.
            $quotes[] = QuoteEngine::quote($intent, $provider, $quoteId, $environment);
        }

        // Routing Engine : scoring + classement.
        return RoutingEngine::rank(
            $quotes,
            (string) $intent['objective'],
            $quoteId,
            (float) $intent['amount'],
            (string) $intent['sourceCurrency'],
            (string) $intent['destCurrency'],
            (string) $intent['receivingMethod'],
        );
    }

    /** Génère un identifiant de quote court (NX + timestamp + aléa). */
    private static function generateQuoteId(): string
    {
        return self::ID_PREFIX . '-' . strtoupper(substr(md5(uniqid((string) random_int(0, PHP_INT_MAX), true)), 0, 8)) . '-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 4);
    }

    /**
     * Taux EUR vers une devise (1 EUR = X), RÉEL.
     *
     * Pour le Policy Engine (plafonds en EUR), le montant source doit être
     * converti en EUR avec un taux de la source FX réelle. Sans taux, le
     * plafond ne peut pas être évalué : refus explicite (§7) — aucun tableau
     * de taux statique ne subsiste.
     */
    private static function rateToEur(string $currency, ?ExecutionEnvironment $environment = null): float
    {
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        $pricing = QuotePricing::resolveRate('EUR', strtoupper($currency), $environment);
        if ($pricing['status'] === QuotePricing::RESOLVED && $pricing['rate'] !== null) {
            return (float) $pricing['rate'];
        }

        throw new QuoteRateUnavailable(
            'EUR',
            $currency,
            sprintf('Aucun taux de change disponible pour EUR → %s : plafond non évaluable.', $currency)
        );
    }
}
