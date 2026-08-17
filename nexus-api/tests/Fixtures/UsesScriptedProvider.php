<?php

declare(strict_types=1);

namespace Nexus\Tests\Fixtures;

use Nexus\Providers\ProviderRegistry;

/**
 * Configure un provider SCRIPTÉ pour les tests du chemin nominal.
 *
 * La configuration passe par les VRAIES variables d'environnement
 * (`PROVIDER_STRIPE_*`) — le mécanisme réel d'un déploiement — et seul
 * l'appel réseau est remplacé par ScriptedProviderAdapter (fixture).
 *
 * À appeler dans setUp() / tearDown() des tests qui exécutent la saga
 * complète (ExecutionEngine) et attendent un succès provider. Les tests de
 * REFUS (provider non configuré, environnement incompatible) n'en ont pas
 * besoin : leur refus intervient avant l'appel provider.
 */
trait UsesScriptedProvider
{
    protected function scriptStripe(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_fixture_only');
        ProviderRegistry::registerAdapter('stripe', new ScriptedProviderAdapter('stripe'));
    }

    protected function unscriptStripe(): void
    {
        ProviderRegistry::resetAdapters();
        ScriptedProviderAdapter::$calls = [];
        putenv('PROVIDER_STRIPE_ENABLED');
        putenv('PROVIDER_STRIPE_ENV');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY');
    }
}
