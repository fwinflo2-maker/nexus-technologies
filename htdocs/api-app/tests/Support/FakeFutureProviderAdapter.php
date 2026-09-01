<?php

declare(strict_types=1);

namespace Nexus\Tests\Support;

use Nexus\Providers\AbstractProviderAdapter;

/**
 * Adaptateur fictif — PHPUnit uniquement, jamais en production.
 */
final class FakeFutureProviderAdapter extends AbstractProviderAdapter
{
    public function __construct(string $slug = 'future_test')
    {
        parent::__construct($slug);
    }

    public function createPayment(array $params): array
    {
        return [
            'id'     => 'fake-' . ($params['operation_id'] ?? 'op'),
            'status' => 'ACCEPTED',
        ];
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        return [
            'status'    => 'CONNECTION_SUCCESS',
            'message'   => 'Fake provider connected.',
            'tested_at' => gmdate(DATE_ATOM),
        ];
    }
}
