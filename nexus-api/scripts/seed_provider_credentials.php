<?php

declare(strict_types=1);

/**
 * Insère des credentials de providers correctement chiffrées (Crypto AES-256-GCM)
 * pour que les endpoints /control/public-keys et /control/credentials montrent
 * les providers comme "configurés" dans le dashboard Super Admin.
 *
 * AUCUNE valeur réelle : tout est fictif, chiffré et jamais exposé en clair.
 * Le registre public-keys ne renvoie que "configuré = true/false".
 *
 * Usage : php scripts/seed_provider_credentials.php
 */

define('APP_KEY', 'nexus-dev-data-key-change-me');
define('APP_ENV', 'development');

require __DIR__ . '/../vendor/autoload.php';

use Nexus\Core\Crypto;

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=nexus;charset=utf8mb4',
    'nexus',
    'nexus_dev_pw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$now = date('Y-m-d H:i:s');

$creds = [
    ['stripe', 'sandbox',     ['secret_key' => 'sk_test_51NsXyzFake000', 'publishable_key' => 'pk_test_51NsXyzFake000', 'webhook_secret' => 'whsec_test_fake000', 'account_id' => 'acct_123456789']],
    ['stripe', 'production',  ['secret_key' => 'sk_live_51NsXyzFake111', 'publishable_key' => 'pk_live_51NsXyzFake111', 'webhook_secret' => 'whsec_live_fake111', 'account_id' => 'acct_987654321']],
    ['pawapay', 'sandbox',    ['api_token' => 'sandbox_bearer_fake000', 'api_key_id' => 'CUSTOMER_TEST_KEY']],
    ['wise', 'sandbox',       ['client_id' => 'wise_sandbox_client_000', 'client_secret' => 'wise_sandbox_secret_000', 'profile_id' => 'profile_000']],
    ['wise', 'production',    ['client_id' => 'wise_live_client_000', 'client_secret' => 'wise_live_secret_000', 'profile_id' => 'profile_000']],
    ['nium', 'sandbox',       ['client_id' => 'nium_sandbox_client_000', 'client_secret' => 'nium_sandbox_secret_000']],
    ['western_union', 'sandbox', ['client_id' => 'wu_sandbox_client_000', 'client_secret' => 'wu_sandbox_secret_000', 'partner_id' => 'wu_partner_000']],
];

$sel = $pdo->prepare('SELECT id FROM provider_credentials WHERE provider_slug = ? AND environment = ? AND (user_id IS NULL OR user_id = ?)');
$upd = $pdo->prepare(
    "UPDATE provider_credentials SET credentials_enc = ?, status = 'active', configured_by = 1,
            last_tested_at = ?, last_error = NULL, updated_at = ?
     WHERE provider_slug = ? AND environment = ? AND (user_id IS NULL OR user_id = ?)"
);
$ins = $pdo->prepare(
    "INSERT INTO provider_credentials (provider_slug, environment, credentials_enc, status, configured_by,
                                       last_tested_at, last_error, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?)"
);

foreach ($creds as [$slug, $env, $fields]) {
    $enc = Crypto::encrypt(json_encode(['credentials' => $fields]));
    $sel->execute([$slug, $env, 1]);
    $row = $sel->fetch();
    if ($row) {
        $upd->execute([$enc, $now, $now, $slug, $env, 1]);
    } else {
        $ins->execute([$slug, $env, $enc, 'active', 1, $now, null, $now, $now]);
    }
    echo "upsert {$slug}/{$env}: configured\n";
}

echo "Done.\n";
