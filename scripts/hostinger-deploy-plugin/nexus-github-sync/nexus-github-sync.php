<?php
/**
 * Plugin Name: Nexus GitHub Sync
 * Description: Synchronise api-app depuis GitHub main (usage interne Nexus).
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const NEXUS_GITHUB_SYNC_OPTION = 'nexus_github_sync_last_run';
const NEXUS_GITHUB_SYNC_INTERVAL = 300;

function nexus_github_sync_files(): array
{
    return [
        'htdocs/api-app/src/Services/ProviderCustomerService.php' => 'api-app/src/Services/ProviderCustomerService.php',
        'htdocs/api-app/src/Core/Database.php'                       => 'api-app/src/Core/Database.php',
        'htdocs/api-app/migrations/2026_09_01_provider_customers.sql' => 'api-app/migrations/2026_09_01_provider_customers.sql',
        'htdocs/api-app/scripts/apply_provider_customers_migration.php' => 'api-app/scripts/apply_provider_customers_migration.php',
    ];
}

function nexus_github_sync_run(bool $force = false): void
{
    $last = (int) get_option(NEXUS_GITHUB_SYNC_OPTION, 0);
    if (!$force && (time() - $last) < NEXUS_GITHUB_SYNC_INTERVAL) {
        return;
    }

    $base = rtrim(ABSPATH, '/\\');
    $repo = 'https://raw.githubusercontent.com/fwinflo2-maker/nexus-technologies/main';

    foreach (nexus_github_sync_files() as $remote => $local) {
        $dest = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $local);
        $dir  = dirname($dest);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            continue;
        }

        $response = wp_remote_get($repo . '/' . $remote, ['timeout' => 30]);
        if (is_wp_error($response)) {
            continue;
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            continue;
        }
        file_put_contents($dest, $body);
    }

    $migrate = $base . '/api-app/scripts/apply_provider_customers_migration.php';
    if (is_file($migrate)) {
        @include $migrate;
    }

    update_option(NEXUS_GITHUB_SYNC_OPTION, time(), false);
}

register_activation_hook(__FILE__, static function (): void {
    nexus_github_sync_run(true);
});

add_action('init', static function (): void {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    nexus_github_sync_run(false);
});
