<?php

declare(strict_types=1);

/**
 * Runner générique — test de connexion provider (provider-agnostic).
 *
 * Usage :
 *   php scripts/provider_connect_test.php --provider=pawapay
 *   php scripts/provider_connect_test.php --provider=stripe --env=sandbox
 *   php scripts/provider_connect_test.php --all
 *   php scripts/provider_connect_test.php --all --no-connect
 *
 * Sortie structurée (jamais de secret) :
 *   credentials=CONFIGURED|CREDENTIALS_NOT_CONFIGURED
 *   connection=CONNECTED|BLOCKED|CONNECTION_FAILED|NOT_TESTED
 *
 * Exit codes :
 *   0  — CONNECTED (ou --all avec au moins un CONNECTED / sans erreur fatale)
 *   1  — CONNECTION_FAILED
 *   2  — BLOCKED / CREDENTIALS_NOT_CONFIGURED / NOT_IMPLEMENTED test
 *   3  — usage / provider inconnu
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_ENV', 'development');
define('APP_KEY', getenv('APP_KEY') ?: 'nexus-dev-data-key-change-me');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/database.php';

use Nexus\Providers\ProviderOperationalAudit;
use Nexus\Services\ProviderCatalog;

$opts = getopt('', ['provider:', 'env:', 'all', 'no-connect', 'json', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
provider_connect_test.php — audit / test de connexion provider-agnostic

  --provider=SLUG   Un provider du catalogue (ex: pawapay, stripe)
  --all             Tous les providers du catalogue
  --env=sandbox|production   (défaut: sandbox)
  --no-connect      N'appelle pas testConnection() (classifie seulement)
  --json            Sortie JSON
  --help

TXT);
    exit(0);
}

$environment = isset($opts['env']) && $opts['env'] === 'production' ? 'production' : 'sandbox';
$attemptConnection = !isset($opts['no-connect']);
$asJson = isset($opts['json']);

if (isset($opts['all'])) {
    $rows = ProviderOperationalAudit::auditAll($environment, $attemptConnection);
    emitAll($rows, $asJson);
    exit(summarizeExit($rows));
}

$provider = isset($opts['provider']) ? strtolower(trim((string) $opts['provider'])) : '';
if ($provider === '') {
    fwrite(STDERR, "usage: php scripts/provider_connect_test.php --provider=pawapay\n");
    fwrite(STDERR, "       php scripts/provider_connect_test.php --all\n");
    exit(3);
}

// Alias organisationnel : onafriq → onfriq (slug catalogue historique).
if ($provider === 'onafriq') {
    $provider = 'onfriq';
}

if (!ProviderCatalog::exists($provider)) {
    fwrite(STDERR, "UNKNOWN_PROVIDER: {$provider}\n");
    exit(3);
}

$row = ProviderOperationalAudit::audit($provider, $environment, $attemptConnection);
emitOne($row, $asJson);
exit(exitFor($row));

/** @param array<string,mixed> $row */
function emitOne(array $row, bool $asJson): void
{
    if ($asJson) {
        echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo 'provider=' . $row['provider'] . PHP_EOL;
    echo 'name=' . ($row['name'] ?? '') . PHP_EOL;
    echo 'environment=' . $row['environment'] . PHP_EOL;
    echo 'priority=' . $row['priority'] . PHP_EOL;
    echo 'implementation=' . $row['implementation'] . PHP_EOL;
    echo 'adapter=' . $row['adapter'] . PHP_EOL;
    echo 'credentials=' . $row['credentials'] . PHP_EOL;
    echo 'credentials_source=' . ($row['credentials_source'] ?? '') . PHP_EOL;
    echo 'connection=' . $row['connection'] . PHP_EOL;
    echo 'sandbox=' . $row['sandbox'] . PHP_EOL;
    echo 'production=' . $row['production'] . PHP_EOL;
    echo 'available=' . ($row['available'] ? 'yes' : 'no') . PHP_EOL;
    echo 'capability_payout=' . $row['capability_payout'] . PHP_EOL;
    echo 'schema_verified=' . ($row['schema_verified'] ? 'yes' : 'no') . PHP_EOL;
    if (($row['missing_required'] ?? []) !== []) {
        echo 'missing_required=' . implode(',', $row['missing_required']) . PHP_EOL;
    }
    if (($row['test_status'] ?? null) !== null) {
        echo 'test_status=' . $row['test_status'] . PHP_EOL;
    }
    if (($row['test_message'] ?? null) !== null && $row['test_message'] !== '') {
        echo 'test_message=' . $row['test_message'] . PHP_EOL;
    }
    echo 'env_var_prefix=' . ($row['env_var_prefix'] ?? '') . PHP_EOL;
}

/** @param list<array<string,mixed>> $rows */
function emitAll(array $rows, bool $asJson): void
{
    if ($asJson) {
        echo json_encode(['providers' => $rows, 'counts' => counts($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    $c = counts($rows);
    echo "=== PROVIDER AUDIT ({$c['total']} providers) ===\n";
    foreach ($rows as $row) {
        printf(
            "%-16s impl=%-16s cred=%-28s conn=%-18s avail=%s prio=%s\n",
            $row['provider'],
            $row['implementation'],
            $row['credentials'],
            $row['connection'],
            $row['available'] ? 'yes' : 'no',
            $row['priority']
        );
    }
    echo "--- counts ---\n";
    foreach ($c as $k => $v) {
        echo $k . '=' . $v . PHP_EOL;
    }
}

/** @param list<array<string,mixed>> $rows @return array<string,int> */
function counts(array $rows): array
{
    $c = [
        'total' => count($rows),
        'implemented' => 0,
        'not_implemented' => 0,
        'credentials_configured' => 0,
        'credentials_not_configured' => 0,
        'connected' => 0,
        'blocked' => 0,
        'connection_failed' => 0,
        'available' => 0,
    ];
    foreach ($rows as $row) {
        if ($row['implementation'] === 'IMPLEMENTED') {
            $c['implemented']++;
        } else {
            $c['not_implemented']++;
        }
        if ($row['credentials'] === 'CONFIGURED') {
            $c['credentials_configured']++;
        } else {
            $c['credentials_not_configured']++;
        }
        match ($row['connection']) {
            'CONNECTED' => $c['connected']++,
            'CONNECTION_FAILED' => $c['connection_failed']++,
            default => $c['blocked']++,
        };
        if ($row['available']) {
            $c['available']++;
        }
    }
    return $c;
}

/** @param array<string,mixed> $row */
function exitFor(array $row): int
{
    return match ($row['connection']) {
        'CONNECTED' => 0,
        'CONNECTION_FAILED' => 1,
        default => 2,
    };
}

/** @param list<array<string,mixed>> $rows */
function summarizeExit(array $rows): int
{
    $anyFailed = false;
    $anyConnected = false;
    foreach ($rows as $row) {
        if ($row['connection'] === 'CONNECTED') {
            $anyConnected = true;
        }
        if ($row['connection'] === 'CONNECTION_FAILED') {
            $anyFailed = true;
        }
    }
    if ($anyFailed) {
        return 1;
    }
    if ($anyConnected) {
        return 0;
    }
    return 2;
}
