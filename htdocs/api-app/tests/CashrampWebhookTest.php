<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\Cashramp\CashrampStatusMapper;
use PHPUnit\Framework\TestCase;

final class CashrampWebhookTest extends TestCase
{
    public function testMapsPaymentRequestUpdatedStatuses(): void
    {
        self::assertSame('completed', CashrampStatusMapper::mapPaymentRequest('completed'));
        self::assertSame('failed', CashrampStatusMapper::mapPaymentRequest('failed'));
    }

    public function testMapsOnchainStatuses(): void
    {
        self::assertSame('processing', CashrampStatusMapper::mapOnchain('processing'));
        self::assertSame('completed', CashrampStatusMapper::mapOnchain('completed'));
    }

    public function testRecognizesOfficialEventTypes(): void
    {
        self::assertSame('payment', CashrampStatusMapper::mapWebhookEventType('payment_request.updated'));
        self::assertSame('onchain_withdrawal', CashrampStatusMapper::mapWebhookEventType('onchain_tx.updated'));
    }
}
