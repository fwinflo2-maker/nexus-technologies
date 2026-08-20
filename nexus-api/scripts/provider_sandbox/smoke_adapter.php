<?php

declare(strict_types=1);

/**
 * Smoke test : pilote le VRAI PawaPayAdapter contre le harness sandbox.
 *
 * Usage : php scripts/provider_sandbox/smoke_adapter.php
 *
 * Varie les env suivantes (sinon défauts) :
 *   PROVIDER_PAWAPAY_SANDBOX_BASE_URL  (défaut http://127.0.0.1:8901)
 *   PROVIDER_PAWAPAY_SANDBOX_API_TOKEN (défaut harness_test_token)
 */

require __DIR__ . '/../../src/Core/Database.php';
require __DIR__ . '/../../src/Core/HttpException.php';
require __DIR__ . '/../../src/Core/Crypto.php';
require __DIR__ . '/../../src/Services/ProviderCatalog.php';
require __DIR__ . '/../../src/Providers/ProviderStatus.php';
require __DIR__ . '/../../src/Providers/ProviderAdapter.php';
require __DIR__ . '/../../src/Providers/ProviderConfig.php';
require __DIR__ . '/../../src/Providers/PawaPaySignature.php';
require __DIR__ . '/../../src/Providers/ProviderOperationNotImplemented.php';
require __DIR__ . '/../../src/Providers/AbstractProviderAdapter.php';
require __DIR__ . '/../../src/Providers/PawaPayAdapter.php';
require __DIR__ . '/../../src/Services/ProviderCredentialService.php';

use Nexus\Providers\PawaPayAdapter;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? "  PASS " : "  FAIL ") . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    $ok ? $pass++ : $fail++;
}

putenv('PROVIDER_PAWAPAY_ENABLED=true');
putenv('PROVIDER_PAWAPAY_ENV=sandbox');
putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=' . (getenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN') ?: 'harness_test_token'));
putenv('PROVIDER_PAWAPAY_SANDBOX_BASE_URL=' . (getenv('PROVIDER_PAWAPAY_SANDBOX_BASE_URL') ?: 'http://127.0.0.1:8901'));

$adapter = new PawaPayAdapter();

echo "\n— Test de connexion (GET /balances) —\n";
$conn = $adapter->testConnection('sandbox');
check('testConnection', ($conn['status'] ?? '') === 'CONNECTION_SUCCESS', json_encode($conn));

echo "\n— Solde —\n";
$balance = $adapter->getBalance();
check('getBalance', ($balance['balances'][0]['currency'] ?? '') === 'XAF', json_encode($balance['balances'] ?? []));

echo "\n— Payout (CM / MTN / XAF) —\n";
$operationId = sprintf('%04x%04x-%04x-%04x-%04x-%012x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff) & 0x0fff | 0x4000, random_int(0, 0x3fff) & 0x3fff | 0x8000, random_int(0, 0xffffffffffff));
$payment = $adapter->createPayment([
    'operation_id'      => $operationId,
    'dest_amount'       => 5000,
    'dest_currency'     => 'XAF',
    'destination'       => '237691234567',
    'dest_country'      => 'CM',
    'receiving_method'  => 'mobile_money',
    'operator'          => 'MTN',
    'environment'       => 'sandbox',
]);
check('createPayment', in_array($payment['status'], ['processing', 'completed'], true), json_encode($payment));
check('provider_operation_id', ($payment['provider_operation_id'] ?? '') === $operationId, (string) ($payment['provider_operation_id'] ?? ''));
echo '  → statut provider : ' . ($payment['provider_status'] ?? '?') . "\n";

echo "\n— Statut (GET /payouts/{id}) —\n";
$status = $adapter->getPaymentStatus($operationId);
check('getPaymentStatus', ($status['provider_status'] ?? '') !== '', json_encode($status));
echo '  → statut provider : ' . ($status['provider_status'] ?? '?') . "\n";

echo "\n— Idempotence (rejeu du même payoutId) —\n";
$dup = $adapter->createPayment([
    'operation_id'      => $operationId,
    'dest_amount'       => 5000,
    'dest_currency'     => 'XAF',
    'destination'       => '237691234567',
    'dest_country'      => 'CM',
    'receiving_method'  => 'mobile_money',
    'operator'          => 'MTN',
    'environment'       => 'sandbox',
]);
check('rejeu → DUPLICATE_IGNORED', ($dup['duplicate'] ?? false) === true, json_encode($dup));

echo "\n— Erreur : bénéficiaire invalide —\n";
try {
    $adapter->createPayment([
        'operation_id'     => $operationId,
        'dest_amount'      => 5000,
        'dest_currency'    => 'XAF',
        'destination'      => '123',
        'dest_country'     => 'CM',
        'receiving_method' => 'mobile_money',
        'operator'         => 'MTN',
        'environment'      => 'sandbox',
    ]);
    check('MSISDN invalide rejeté', false, 'aucune exception levée');
} catch (\Nexus\Core\HttpException $e) {
    check('MSISDN invalide rejeté', $e->statusCode() === 422, 'HTTP ' . $e->statusCode() . ' ' . $e->getMessage());
}

echo "\n— Erreur : pays non couvert —\n";
try {
    $adapter->createPayment([
        'operation_id'     => $operationId,
        'dest_amount'      => 5000,
        'dest_currency'    => 'USD',
        'destination'      => '123456789',
        'dest_country'     => 'US',
        'receiving_method' => 'mobile_money',
        'operator'         => 'MTN',
        'environment'      => 'sandbox',
    ]);
    check('pays non couvert rejeté', false, 'aucune exception levée');
} catch (\Nexus\Core\HttpException $e) {
    check('pays non couvert rejeté', $e->statusCode() === 422, 'HTTP ' . $e->statusCode());
}

echo "\n— Erreur : credentials invalides (401) —\n";
putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=mauvais_token');
$bad = $adapter->testConnection('sandbox');
check('testConnection mauvais token', ($bad['status'] ?? '') === 'INVALID_CREDENTIALS', json_encode($bad));
putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=harness_test_token');

echo "\n══════════════════════════════════════\n";
echo "Résultat : $pass PASS / $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
