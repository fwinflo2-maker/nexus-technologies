<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\FXSourceStatus;
use Nexus\Services\FXService;
use Nexus\Services\SanctionsScreening;
use Nexus\Execution\ExecutionEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cycle 4 — sources FX et sanctions : honnêteté fail-closed, aucun vendor inventé.
 */
final class FXAndSanctionsStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES);
        putenv(SanctionsScreening::ENV_LIST_FILE);
    }

    public function test_source_fx_honnete_parite_officielle_sans_vendor_marche(): void
    {
        $d = FXSourceStatus::describe();
        // Cycle 5 : la parité de droit EUR↔XAF est branchée avec provenance.
        self::assertTrue($d['configured']);
        self::assertNull($d['vendor'], 'Aucun vendor de taux de marché.');
        self::assertFalse($d['market_vendor_configured']);
        self::assertSame(\Nexus\Services\OfficialPegFXProvider::SOURCE, $d['source']);
        self::assertTrue($d['fail_closed']);
        self::assertSame('CONFIGURATION_READY', $d['ladder']);
        self::assertCount(1, $d['providers']);
        self::assertSame('fixed_peg', $d['providers'][0]['kind']);
        self::assertStringContainsString('655,957', $d['providers'][0]['provenance']);
    }

    public function test_paire_de_marche_absente_refuse_sans_taux_invente(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = ? AND quote_currency = ?')
            ->execute(['EUR', 'USD']);
        $this->expectException(RuntimeException::class);
        FXService::resolve('EUR', 'USD', ExecutionEnvironment::SANDBOX);
    }

    public function test_sanctions_sans_source_sont_hors_scope_fail_closed(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES . '=');
        putenv(SanctionsScreening::ENV_LIST_FILE . '=');
        $d = SanctionsScreening::describe();
        self::assertFalse($d['configured']);
        self::assertSame('OUT_OF_SCOPE', $d['scope']);
        self::assertTrue($d['fail_closed_production']);
        self::assertSame('none', $d['source']);
        self::assertFalse(SanctionsScreening::isConfigured());
        $screen = SanctionsScreening::screenCountry('CG');
        self::assertSame(SanctionsScreening::UNAVAILABLE, $screen['status']);
        self::assertFalse($screen['screened']);
    }
}
