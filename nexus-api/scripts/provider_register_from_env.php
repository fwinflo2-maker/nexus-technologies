<?php

declare(strict_types=1);

/**
 * Enregistre des credentials plateforme depuis l'ENVIRONNEMENT (jamais CLI).
 *
 * Usage (PowerShell) :
 *   $env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN = '<token>'
 *   php scripts/provider_register_from_env.php --provider=pawapay
 *   Remove-Item Env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN -ErrorAction SilentlyContinue
 *
 * Lit uniquement PROVIDER_{SLUG}_{ENV}_{FIELD} pour les champs du catalogue.
 * N'écrit jamais la valeur d'un secret dans la sortie.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_ENV', 'development');
define('APP_KEY', getenv('APP_KEY') ?: 'nexus-dev-data-key-change-me');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/database.php';

use Nexus\Core\Database;
use Nexus\Providers\ProviderConfig;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;

$opts = getopt('', ['provider:', 'env:', 'help']);

if (isset($opts['help']) || !isset($opts['provider'])) {
    fwrite(STDOUT, "usage: php scripts/provider_register_from_env.php --provider=pawapay [--env=sandbox]\n");
    exit(isset($opts['help']) ? 0 : 3);
}

$slug = strtolower(trim((string) $opts['provider']));
if ($slug === 'onafriq') {
    $slug = 'onfriq';
}
$environment = isset($opts['env']) && $opts['env'] === 'production' ? 'production' : 'sandbox';

if (!ProviderCatalog::exists($slug)) {
    fwrite(STDERR, "UNKNOWN_PROVIDER: {$slug}\n");
    exit(3);
}

$provider = ProviderCatalog::get($slug);
$fields = [];
$missingRequired = [];
$envKeysToClear = [];
$fieldNamesStored = [];

foreach (($provider['credentials'] ?? []) as $field) {
    $key = (string) $field['key'];
    $value = ProviderConfig::credential($slug, $key, $environment);
    $envName = 'PROVIDER_' . strtoupper($slug) . '_' . strtoupper($environment) . '_' . strtoupper($key);
    if ($value !== null && $value !== '') {
        $normalized = (str_contains($key, 'private_key') || str_contains($key, 'cert') || str_contains($key, 'key_path'))
            ? str_replace(['\\n', "\r\n"], "\n", $value)
            : $value;
        $fields[$key] = $normalized;
        $fieldNamesStored[] = $key;
        $envKeysToClear[] = $envName;
    } elseif ($field['required'] ?? false) {
        $missingRequired[] = $key;
        echo $envName . '=absent' . PHP_EOL;
    }
}

if ($missingRequired !== []) {
    fwrite(STDERR, 'CREDENTIALS_NOT_CONFIGURED: missing required fields: ' . implode(',', $missingRequired) . PHP_EOL);
    exit(2);
}

if ($fields === []) {
    fwrite(STDERR, "CREDENTIALS_NOT_CONFIGURED: no credential fields present in environment.\n");
    exit(2);
}

$pdo = Database::getConnection();
$status = $environment === 'production' ? 'active' : 'sandbox_only';
ProviderCredentialService::upsertPlatform(
    $pdo,
    $slug,
    $environment === 'production'
        ? ProviderCredentialService::ENV_PRODUCTION
        : ProviderCredentialService::ENV_SANDBOX,
    $fields,
    $status,
    0
);

// Nettoyage immédiat de l'env process (limite la fenêtre d'exposition).
foreach ($envKeysToClear as $name) {
    putenv($name);
}
// Efface les valeurs en mémoire locale.
foreach (array_keys($fields) as $k) {
    $fields[$k] = '';
}
unset($fields);

$row = ProviderCredentialService::findPlatformRow($pdo, $slug, $environment);
echo 'registered=yes' . PHP_EOL;
echo 'provider=' . $slug . PHP_EOL;
echo 'environment=' . $environment . PHP_EOL;
echo 'status=' . ($row['status'] ?? '?') . PHP_EOL;
echo 'has_blob=' . (($row['credentials_enc'] ?? '') !== '' ? '1' : '0') . PHP_EOL;
echo 'last_tested_at=' . (($row['last_tested_at'] ?? null) === null ? 'NULL' : 'SET') . PHP_EOL;
echo 'fields_stored=' . implode(',', $fieldNamesStored) . PHP_EOL;
echo "secret_echoed=no\n";
