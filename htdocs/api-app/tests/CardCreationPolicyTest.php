<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\CashrampCardCreationPolicyService;
use PHPUnit\Framework\TestCase;

final class CardCreationPolicyTest extends TestCase
{
    public function testDefaultMinimumIsOneUsd(): void
    {
        self::assertSame('1.00', CashrampCardCreationPolicyService::DEFAULT_MINIMUM_USD);
    }
}
