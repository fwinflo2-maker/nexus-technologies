<?php
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
    $f = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($f)) {
        require $f;
    }
});

use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Services\ProviderCatalog;

$short = [
    'IMPLEMENTED'     => 'IMPLEMENTED',
    'NOT_IMPLEMENTED' => '—',
    'NOT_SUPPORTED'   => 'N/S',
    'CONFIG_REQUIRED' => 'CONFIG',
];

$cols = ['test_connection', 'balance', 'quote', 'payout', 'refund', 'webhook', 'reconciliation'];

printf("| %-16s | %-14s | %-13s | %-10s | %-7s | %-6s | %-7s | %-7s | %-13s | %-13s |\n", 'Provider', 'Catégorie', 'TestConn', 'Balance', 'Quote', 'Payout', 'Refund', 'Webhook', 'Reconcile', 'Intégration');
printf("|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|\n", str_repeat('-', 18), str_repeat('-', 16), str_repeat('-', 15), str_repeat('-', 12), str_repeat('-', 9), str_repeat('-', 8), str_repeat('-', 9), str_repeat('-', 9), str_repeat('-', 15), str_repeat('-', 15));
foreach (ProviderCatalog::all() as $slug => $p) {
    $caps = ProviderCapabilityMatrix::for($slug);
    $row = [$slug, (string) $p['category']];
    foreach ($cols as $c) {
        $row[] = $short[$caps[$c]];
    }
    $row[] = ProviderCapabilityMatrix::integrationStatus($slug);
    printf("| %-16s | %-14s | %-13s | %-10s | %-7s | %-6s | %-7s | %-7s | %-13s | %-13s |\n", ...$row);
}
