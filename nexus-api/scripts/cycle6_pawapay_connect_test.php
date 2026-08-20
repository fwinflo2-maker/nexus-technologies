<?php

declare(strict_types=1);

/**
 * Cycle 6 — test de connexion RÉEL vers api.sandbox.pawapay.io.
 *
 * Prérequis : credential plateforme pawapay/sandbox déjà enregistrée
 * (voir cycle6_register_pawapay_from_env.php) OU env PROVIDER_PAWAPAY_SANDBOX_API_TOKEN.
 *
 * N'affiche jamais le token. Sortie : status + message adapter uniquement.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_ENV', 'development');
define('APP_KEY', getenv('APP_KEY') ?: 'nexus-dev-data-key-change-me');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/database.php';

use Nexus\Core\Database;
use Nexus\Providers\PawaPayAdapter;
use Nexus\Services\ProviderCredentialService;

$pdo = Database::getConnection();
$row = ProviderCredentialService::findPlatformRow($pdo, 'pawapay', 'sandbox');
echo 'credential_row=' . ($row === null ? 'absent' : 'present') . PHP_EOL;
if ($row !== null) {
    echo 'credential_status=' . ($row['status'] ?? '?') . PHP_EOL;
    echo 'last_tested_before=' . (($row['last_tested_at'] ?? null) === null ? 'NULL' : 'SET') . PHP_EOL;
}

$adapter = new PawaPayAdapter();
$result = $adapter->testConnection('sandbox');
$status = (string) ($result['status'] ?? 'UNKNOWN');
$message = (string) ($result['message'] ?? '');

echo 'test_status=' . $status . PHP_EOL;
echo 'test_message=' . $message . PHP_EOL;
echo 'tested_at=' . ($result['tested_at'] ?? '') . PHP_EOL;

$ladder = match ($status) {
    'CONNECTION_SUCCESS' => 'SANDBOX_CONNECTED',
    'PROVIDER_NOT_CONFIGURED' => 'CREDENTIALS_NOT_CONFIGURED',
    'INVALID_CREDENTIALS', 'UNAUTHORIZED', 'CONFIGURATION_ERROR',
    'PROVIDER_UNAVAILABLE', 'TIMEOUT' => 'NOT_VERIFIED',
    default => 'NOT_VERIFIED',
};
echo 'ladder=' . $ladder . PHP_EOL;

// Persiste le résultat de test (sans secret) si une ligne existe.
if ($row !== null) {
    $ok = $status === 'CONNECTION_SUCCESS';
    ProviderCredentialService::markPlatformTested(
        $pdo,
        'pawapay',
        'sandbox',
        $ok ? 'sandbox_only' : 'error',
        $ok ? null : ($status . ': ' . $message)
    );
    echo 'last_tested_updated=yes' . PHP_EOL;
}

exit($status === 'CONNECTION_SUCCESS' ? 0 : 1);
