<?php

declare(strict_types=1);

/**
 * Cycle 6 — enregistre le token sandbox pawaPay via le credential manager.
 *
 * Usage (PowerShell) :
 *   $env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN = '<token>'
 *   php scripts/cycle6_register_pawapay_from_env.php
 *
 * Optionnel :
 *   PROVIDER_PAWAPAY_SANDBOX_API_KEY_ID
 *   PROVIDER_PAWAPAY_SANDBOX_PRIVATE_KEY  (PEM, peut contenir \n)
 *
 * Ne lit jamais un argument CLI (évite l'historique shell).
 * N'écrit jamais la valeur du secret dans la sortie / logs.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_ENV', 'development');
define('APP_KEY', getenv('APP_KEY') ?: 'nexus-dev-data-key-change-me');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/database.php';

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;

$token = getenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
if ($token === false || trim($token) === '') {
    fwrite(STDERR, "CREDENTIALS_NOT_CONFIGURED: set PROVIDER_PAWAPAY_SANDBOX_API_TOKEN (env only).\n");
    exit(2);
}

$fields = [
    'api_token' => trim($token),
];
$keyId = getenv('PROVIDER_PAWAPAY_SANDBOX_API_KEY_ID');
if (is_string($keyId) && trim($keyId) !== '') {
    $fields['api_key_id'] = trim($keyId);
}
$private = getenv('PROVIDER_PAWAPAY_SANDBOX_PRIVATE_KEY');
if (is_string($private) && trim($private) !== '') {
    $fields['private_key'] = str_replace(['\\n', "\r\n"], "\n", trim($private));
}

// Nettoyage immédiat de l'env process pour limiter la fenêtre d'exposition.
putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
putenv('PROVIDER_PAWAPAY_SANDBOX_PRIVATE_KEY');
unset($token, $private);

$pdo = Database::getConnection();
ProviderCredentialService::upsertPlatform(
    $pdo,
    'pawapay',
    ProviderCredentialService::ENV_SANDBOX,
    $fields,
    'sandbox_only',
    0
);

$row = ProviderCredentialService::findPlatformRow($pdo, 'pawapay', 'sandbox');
echo "registered=yes\n";
echo 'environment=sandbox' . PHP_EOL;
echo 'status=' . ($row['status'] ?? '?') . PHP_EOL;
echo 'has_blob=' . (($row['credentials_enc'] ?? '') !== '' ? '1' : '0') . PHP_EOL;
echo 'last_tested_at=' . (($row['last_tested_at'] ?? null) === null ? 'NULL' : 'SET') . PHP_EOL;
echo 'fields_stored=' . implode(',', array_keys($fields)) . PHP_EOL;
echo "secret_echoed=no\n";
