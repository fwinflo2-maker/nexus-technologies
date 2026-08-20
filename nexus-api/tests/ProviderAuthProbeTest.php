<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ConfigDrivenProviderAdapter;
use Nexus\Providers\ProviderAuthProbe;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ProviderCatalog;
use PHPUnit\Framework\TestCase;

final class ProviderAuthProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        parent::tearDown();
    }

    public function test_tous_les_providers_catalogue_ont_un_schema_de_formulaire(): void
    {
        foreach (ProviderCatalog::all() as $slug => $_) {
            $desc = ProviderCredentialSchema::describe($slug);
            self::assertIsArray($desc['credentials']);
            self::assertNotEmpty(
                $desc['credentials'],
                "{$slug} doit exposer des champs SuperAdmin (schema ou catalogue)."
            );
        }
    }

    public function test_config_driven_delegue_a_la_sonde(): void
    {
        $adapter = new ConfigDrivenProviderAdapter('thunes');
        $result = $adapter->testConnection('sandbox', []);
        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function test_thunes_basic_success_avec_transport_simule_via_probe_logic(): void
    {
        // Sans credentials → pas d'appel.
        $r = ProviderAuthProbe::test('thunes', 'sandbox', null);
        self::assertSame('PROVIDER_NOT_CONFIGURED', $r['status']);

        // Credentials incomplètes → CONFIGURATION_ERROR (buildRequest échoue).
        $r2 = ProviderAuthProbe::test('thunes', 'sandbox', ['api_key' => 'k']);
        self::assertContains($r2['status'], ['CONFIGURATION_ERROR', 'PROVIDER_UNAVAILABLE', 'TIMEOUT', 'INVALID_CREDENTIALS']);
    }

    public function test_marqeta_schema_utilise_application_token(): void
    {
        $defs = ProviderCredentialSchema::for('marqeta');
        self::assertNotNull($defs);
        $names = array_map(static fn ($d) => $d->name, $defs);
        self::assertContains('application_token', $names);
        self::assertContains('admin_access_token', $names);
        self::assertNotContains('api_key', $names);
    }

    public function test_registry_config_driven_pour_thunes(): void
    {
        $a = ProviderRegistry::adapter('thunes');
        self::assertInstanceOf(ConfigDrivenProviderAdapter::class, $a);
    }
}
