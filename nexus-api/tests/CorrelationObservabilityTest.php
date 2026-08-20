<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Correlation;
use Nexus\Core\Request;
use PHPUnit\Framework\TestCase;

final class CorrelationObservabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Correlation::reset();
        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function test_request_id_header_est_honore_s_il_est_sur(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'req-cycle4-abc12345';
        $request = new Request([]);
        Correlation::bindFromRequest($request);
        self::assertSame('req-cycle4-abc12345', Correlation::id());
        self::assertSame('req-cycle4-abc12345', $request->requestId());
    }

    public function test_request_id_invalide_est_regénere(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'bad id with spaces';
        $request = new Request([]);
        $id = $request->requestId();
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);
    }
}
