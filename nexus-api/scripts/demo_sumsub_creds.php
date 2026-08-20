<?php

declare(strict_types=1);

/**
 * DÉMO — Credentials Sumsub factices (SANDBOX) dans le Credential Manager.
 *
 * Simule exactement ce que fait `PUT /api/providers/sumsub/credentials` :
 * chiffrement AES-256-GCM et stockage plateforme (user_id IS NULL).
 * À NE PAS utiliser avec de vraies clés.
 */

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nexus\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;

$pdo = Database::getConnection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'nexus') {
    fwrite(STDERR, 'Refus : la base courante est « ' . $dbName . ' » — la démo cible la base de dev nexus.' . PHP_EOL);
    exit(1);
}

ProviderCredentialService::upsertPlatform(
    $pdo,
    'sumsub',
    'sandbox',
    [
        'app_token'      => 'sbx_demo_app_token_FAKE',
        'secret_key'     => 'sbx_demo_secret_key_FAKE',
        'webhook_secret' => 'sbx_demo_webhook_secret_FAKE',
    ],
    'sandbox_only',
    1 // admin@nexus-tech.io (seed)
);

// Vérification : déchiffrable et résolvable.
$resolved = ProviderCredentialService::resolvePlatform($pdo, 'sumsub', 'sandbox');
echo 'Credential Sumsub sandbox enregistrée (chiffrée) et résolvable : '
    . ($resolved !== null && ($resolved['app_token'] ?? '') !== '' ? 'OUI' : 'NON')
    . PHP_EOL;
