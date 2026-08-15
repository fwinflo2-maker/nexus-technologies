<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;
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
     *
     * @return list<array<string,mixed>> Routes classées (A, B, C…).
     */
    public static function computeRoutes(
        array $user,
        array $intent,
        ?ExecutionEnvironment $environment = null
    ): array {
        // Capability Engine : providers éligibles pour ce corridor.
        $providers = CapabilityEngine::findEligible($intent, $environment);

        // Policy Engine : conformité avant tout calcul de prix.
        //
        // Le montant de référence est celui comparé aux PLAFONDS KYC : il doit
        // provenir du même référentiel FX que le pricing. Il était calculé sur
        // une table de taux écrite en dur — vérifié en HTTP, faire passer le
        // taux réel de 1,10 à 5,00 (×4,5) laissait le verdict inchangé.
        $amountRef = self::amountInReference(
            (float) $intent['amount'],
            (string) $intent['sourceCurrency'],
            $environment
        );

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

    /** Taux EUR vers une devise (cohérent avec QuoteEngine). */
    /**
     * Montant exprimé dans la devise de référence (EUR), via le FX réel.
     *
     * En production, un taux indisponible fait échouer la cotation : comparer
     * un plafond réglementaire à un montant estimé sur une constante de
     * démonstration reviendrait à ne pas le contrôler du tout.
     */
    private static function amountInReference(
        float $amount,
        string $currency,
        ?ExecutionEnvironment $environment
    ): float {
        $env = $environment
            ?? ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        $converted = ReferenceConverter::amountToEur($amount, $currency, $env);

        if ($converted === null) {
            throw new QuoteRateUnavailable(
                $currency,
                Currency::REF,
                sprintf(
                    'Aucun taux %s → %s disponible en %s : le plafond réglementaire '
                    . 'ne peut pas être vérifié.',
                    strtoupper($currency),
                    Currency::REF,
                    $env->value
                )
            );
        }

        return $converted;
    }
}
