<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        parent::tearDown();
    }

    public function testExistingProviderCanBeResolved(): void
    {
        $adapter = ProviderRegistry::get('cashramp');

        self::assertSame('cashramp', $adapter->slug());
        self::assertSame($adapter, ProviderRegistry::adapter('cashramp'));
    }

    public function testHasReturnsTrueForCatalogProvider(): void
    {
        self::assertTrue(ProviderRegistry::has('cashramp'));
        self::assertInstanceOf(\Nexus\Providers\CashrampAdapter::class, ProviderRegistry::get('cashramp'));
    }

    public function testUnknownProviderStillBuildsGenericAdapter(): void
    {
        self::assertFalse(ProviderRegistry::has('unknown_provider_xyz'));
        $adapter = ProviderRegistry::get('unknown_provider_xyz');
        self::assertSame('unknown_provider_xyz', $adapter->slug());
    }

    public function testAllReturnsCatalogSlugs(): void
    {
        self::assertContains('cashramp', ProviderRegistry::all());
    }

    public function testEnabledIncludesCashramp(): void
    {
        self::assertContains('cashramp', ProviderRegistry::enabled());
    }
}
