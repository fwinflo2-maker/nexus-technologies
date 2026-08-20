<?php

declare(strict_types=1);

/**
 * HARNESS SANDBOX pawaPay — serveur de développement implémentant le PROTOCOLE
 * WIRE RÉEL de l'API pawaPay (docs.pawapay.io), pour tester l'intégration de
 * bout en bout SANS accès au vrai sandbox.
 *
 *   POST /payouts            → initie un payout (idempotent par payoutId)
 *   GET  /payouts/{id}       → statut
 *   GET  /balances           → soldes
 *   GET  /public-keys        → clé publique de signature des callbacks
 *   POST /__admin/flip/{id}  → surface de contrôle TEST (fait évoluer l'état
 *                              et délivre un CALLBACK SIGNE au callback URL)
 *
 * Ce n'est PAS un mock dans le chemin de production : c'est un serveur externe
 * qui parle le même protocole que api.sandbox.pawapay.io. L'adaptateur Nexus
 * (PawaPayAdapter) est configuré pour le joindre via
 * PROVIDER_PAWAPAY_SANDBOX_BASE_URL — le code appelé est identique à celui
 * qui visera le vrai sandbox. Aucune donnée n'est inventée côté Nexus.
 *
 * Signatures RFC-9421 : les callbacks sont signés avec une paire EC P-256
 * générée au premier lancement (clé privée jamais exposée ; clé publique
 * servie par /public-keys). L'adaptateur Nexus vérifie réellement la
 * signature — c'est le même mécanisme que pawaPay en production.
 *
 * Usage :
 *   export PAWAPAY_HARNESS_TOKEN=harness_test_token
 *   export PAWAPAY_HARNESS_CALLBACK_URL=http://127.0.0.1:8080/api/providers/webhook/pawapay
 *   php -S 127.0.0.1:8901 scripts/provider_sandbox/server.php
 *
 * Options :
 *   PAWAPAY_HARNESS_MODE=instant|async   (défaut instant : COMPLETED direct ;
 *                                         async : ACCEPTED puis flip manuel)
 *   PAWAPAY_HARNESS_TOKEN=...            (jeton bearer attendu, défaut harness_test_token)
 *   PAWAPAY_HARNESS_CALLBACK_URL=...     (URL du webhook Nexus à notifier)
 */

use Nexus\Providers\PawaPaySignature;

require __DIR__ . '/../../src/Providers/PawaPaySignature.php';

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = getenv('PAWAPAY_HARNESS_TOKEN') ?: 'harness_test_token';
$mode  = getenv('PAWAPAY_HARNESS_MODE') ?: 'instant';
$callbackUrl = getenv('PAWAPAY_HARNESS_CALLBACK_URL') ?: '';

$storeDir = __DIR__ . '/.state';
if (!is_dir($storeDir)) {
    mkdir($storeDir, 0700, true);
}
$storeFile = $storeDir . '/payouts.json';
$keysDir   = __DIR__ . '/.keys';
if (!is_dir($keysDir)) {
    mkdir($keysDir, 0700, true);
}
$privateKeyFile = $keysDir . '/harness_ec_p256.pem';
$publicKeyFile  = $keysDir . '/harness_ec_p256.pub.pem';
$keyId = 'HARNESS_EC_P256_KEY:1';

// ── Paire de clés EC P-256 (générée au premier lancement, via l'extension
//    OpenSSL de PHP — jamais d'appel à un binaire externe) ──────────────────
// La génération de clé exige un fichier openssl.cnf (XAMPP : pas de config
// par défaut → openssl_pkey_new échoue sans lui).
function openssl_config_options(): array
{
    $candidates = [
        'C:/xampp/php/extras/openssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
        '/etc/ssl/openssl.cnf',
    ];
    $env = getenv('OPENSSL_CONF');
    if ($env !== false && $env !== '') {
        array_unshift($candidates, $env);
    }
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return ['config' => $path];
        }
    }
    return [];
}

if (!is_file($privateKeyFile) || !is_file($publicKeyFile)) {
    $key = openssl_pkey_new(openssl_config_options() + [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);
    if ($key === false) {
        http_response_code(500);
        echo json_encode(['error' => 'impossible de générer la clé EC (extension OpenSSL requise)']);
        exit;
    }
    $privExport = '';
    $pubExport  = '';
    openssl_pkey_export($key, $privExport, null, openssl_config_options());
    $details = openssl_pkey_get_details($key);
    $pubExport = is_array($details) ? (string) ($details['key'] ?? '') : '';
    file_put_contents($privateKeyFile, $privExport, LOCK_EX);
    file_put_contents($publicKeyFile, $pubExport, LOCK_EX);
}
$privateKey = (string) file_get_contents($privateKeyFile);
$publicKey  = (string) file_get_contents($publicKeyFile);

// ── Store (JSON) avec verrou ──────────────────────────────────────────────
function load_store(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_store(string $file, array $store): void
{
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function http_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function bearer_token(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return trim($m[1]);
    }
    return null;
}

/** Délivre un callback SIGNÉ (RFC-9421) vers le webhook Nexus. */
function deliver_callback(string $url, array $payload, string $privateKey, string $keyId): void
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $parts = parse_url($url);
    $authority = ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path = $parts['path'] ?? '/';

    $created = time();
    $expires = $created + 60;
    $sigDate = PawaPaySignature::signatureDate();
    $digest  = PawaPaySignature::contentDigest($body);
    $components = ['@method', '@authority', '@path', 'signature-date', 'content-digest', 'content-type'];
    $params = PawaPaySignature::signatureParams($components, 'ecdsa-p256-sha256', $keyId, $created, $expires);
    $base = PawaPaySignature::signatureBase([
        '@method'        => 'POST',
        '@authority'     => $authority,
        '@path'          => $path,
        'signature-date' => $sigDate,
        'content-digest' => $digest,
        'content-type'   => 'application/json; charset=UTF-8',
    ], $params);
    $rawSig = PawaPaySignature::sign($base, $privateKey, 'ecdsa-p256-sha256');

    $headers = [
        'Authorization: Bearer ' . (string) (getenv('PAWAPAY_HARNESS_CALLBACK_TOKEN') ?: 'harness_callback_token'),
        'Content-Type: application/json; charset=UTF-8',
        'Content-Digest: ' . $digest,
        'Signature-Date: ' . $sigDate,
        'Signature: ' . PawaPaySignature::signatureHeader($rawSig),
        // RFC-9421 : l'en-tête Signature-Input porte le label (sig-pp=...) ;
        // la ligne @signature-params de la base, elle, n'a pas de label.
        'Signature-Input: ' . PawaPaySignature::SIGNATURE_PARAM_NAME . '=' . $params,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Routes ────────────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/') {
    http_json([
        'name'    => 'pawaPay protocol sandbox harness (dev)',
        'mode'    => $mode,
        'store'   => $storeFile,
        'endpoints' => ['POST /payouts', 'GET /payouts/{id}', 'GET /balances', 'GET /public-keys', 'POST /__admin/flip/{id}'],
    ]);
}

if ($method === 'GET' && $uri === '/public-keys') {
    http_json([['keyId' => $keyId, 'publicKey' => $publicKey]]);
}

if ($method === 'GET' && $uri === '/balances') {
    if (bearer_token() !== $token) {
        http_json(['error' => 'unauthorized'], 401);
    }
    http_json([
        ['currency' => 'XAF', 'availableBalance' => '5000000.00', 'actualBalance' => '5000000.00'],
        ['currency' => 'EUR', 'availableBalance' => '25000.00', 'actualBalance' => '25000.00'],
    ]);
}

if ($method === 'POST' && $uri === '/payouts') {
    if (bearer_token() !== $token) {
        http_json(['error' => 'unauthorized'], 401);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_json(['error' => 'invalid json'], 400);
    }

    $payoutId = (string) ($body['payoutId'] ?? '');
    $store = load_store($storeFile);

    // Idempotence : même payoutId → DUPLICATE_IGNORED (statut du dépôt initial).
    if (isset($store[$payoutId])) {
        http_json(['payoutId' => $payoutId, 'status' => 'DUPLICATE_IGNORED'], 200);
    }

    // Validation wire minimale (même esprit que pawaPay).
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payoutId)
        || !isset($body['amount'], $body['currency'], $body['country'], $body['correspondent'], $body['recipient']['address']['value'])
    ) {
        http_json(['status' => 'REJECTED', 'rejectionReason' => 'payload invalide'], 200);
    }

    // Scénario de test piloté par metadata.nexusScenario (surface dev).
    $scenario = 'instant';
    foreach (($body['metadata'] ?? []) as $meta) {
        if (is_array($meta) && ($meta['fieldName'] ?? '') === 'nexusScenario') {
            $scenario = (string) ($meta['fieldValue'] ?? 'instant');
        }
    }
    if ($mode === 'async' && $scenario === 'instant') {
        $scenario = 'accepted';
    }
    if ($scenario === 'rejected') {
        http_json(['status' => 'REJECTED', 'rejectionReason' => 'REJECTED_BY_HARNESS_SCENARIO'], 200);
    }

    $status = $scenario === 'failed' ? 'ACCEPTED' : ($scenario === 'accepted' ? 'ACCEPTED' : 'COMPLETED');
    $store[$payoutId] = [
        'payoutId'            => $payoutId,
        'status'              => $status,
        'amount'              => (string) $body['amount'],
        'currency'            => (string) $body['currency'],
        'country'             => (string) $body['country'],
        'correspondent'       => (string) $body['correspondent'],
        'recipient'           => $body['recipient'],
        'customerTimestamp'   => (string) ($body['customerTimestamp'] ?? gmdate('c')),
        'statementDescription'=> (string) ($body['statementDescription'] ?? ''),
        'scenario'            => $scenario,
        'created'             => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    save_store($storeFile, $store);

    if ($status === 'COMPLETED') {
        $store[$payoutId]['receivedByRecipient'] = gmdate('Y-m-d\TH:i:s\Z');
        $store[$payoutId]['correspondentIds'] = ['HARNESS_INIT' => 'HX' . substr(md5($payoutId), 0, 12)];
        save_store($storeFile, $store);
    }

    http_json(['payoutId' => $payoutId, 'status' => $status, 'created' => $store[$payoutId]['created']], 200);
}

if ($method === 'GET' && preg_match('#^/payouts/([0-9a-f\-]{36})$#i', $uri, $m)) {
    if (bearer_token() !== $token) {
        http_json(['error' => 'unauthorized'], 401);
    }
    $store = load_store($storeFile);
    $payoutId = strtolower($m[1]);
    if (!isset($store[$payoutId])) {
        http_json([], 200); // pawaPay : liste vide si introuvable
    }
    http_json([$store[$payoutId]], 200);
}

// ── Surface de contrôle TEST (jamais exposée en production) ───────────────
if ($method === 'POST' && preg_match('#^/__admin/flip/([0-9a-f\-]{36})$#i', $uri, $m)) {
    if (bearer_token() !== $token) {
        http_json(['error' => 'unauthorized'], 401);
    }
    $payoutId = strtolower($m[1]);
    $body = json_decode((string) file_get_contents('php://input'), true);
    $target = strtoupper((string) ($body['status'] ?? 'COMPLETED'));
    if (!in_array($target, ['COMPLETED', 'FAILED', 'REVERSED'], true)) {
        http_json(['error' => 'statut cible invalide'], 400);
    }

    $store = load_store($storeFile);
    if (!isset($store[$payoutId])) {
        http_json(['error' => 'payout inconnu'], 404);
    }
    $payout = &$store[$payoutId];
    $current = $payout['status'];
    if (in_array($current, ['COMPLETED', 'FAILED', 'REVERSED'], true)) {
        http_json(['payoutId' => $payoutId, 'status' => $current, 'note' => 'état déjà terminal'], 200);
    }

    $payout['status'] = $target;
    if ($target === 'COMPLETED') {
        $payout['receivedByRecipient'] = gmdate('Y-m-d\TH:i:s\Z');
        $payout['correspondentIds'] = ['HARNESS_INIT' => 'HX' . substr(md5($payoutId), 0, 12)];
    } elseif ($target === 'FAILED') {
        $payout['failureReason'] = ['code' => 'PAYOUT_FAILED_HARNESS', 'description' => 'Scénario de test sandbox'];
    }
    save_store($storeFile, $store);

    // Délivre le callback signé au webhook Nexus (si configuré).
    $delivered = false;
    if ($callbackUrl !== '') {
        deliver_callback($callbackUrl, $payout, $privateKey, $keyId);
        $delivered = true;
    }

    http_json(['payoutId' => $payoutId, 'status' => $target, 'callback_delivered' => $delivered], 200);
}

http_json(['error' => 'not found', 'uri' => $uri], 404);
