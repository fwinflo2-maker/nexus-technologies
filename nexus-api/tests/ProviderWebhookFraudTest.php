<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\ProviderWebhookController;
use Nexus\Core\Correlation;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\PawaPayPublicKeyCache;
use Nexus\Providers\PawaPaySignature;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Fraude webhooks pawaPay : signatures RFC-9421 RÉELLES (paire de clés
 * générée), jamais un HMAC générique ni un CONNECTED simulé.
 *
 * Mutations : user_id, référence, montant, devise, duplicata, signature
 * périmée, signature invalide. Le système doit refuser toute mutation
 * non authentifiée ou incohérente, sans créer ni perdre d'argent.
 */
final class ProviderWebhookFraudTest extends TestCase
{
    private const KEY_ID = 'CYCLE4_EC_P256_KEY:1';
    private const HOST = '127.0.0.1:8098';
    private const PATH = '/api/providers/webhook/pawapay';

    private PDO $pdo;
    private string $privateKeyPem = '';
    private string $publicKeyPem = '';

    /** @var list<int> */
    private array $users = [];
    /** @var list<int> */
    private array $wallets = [];
    /** @var list<string> */
    private array $operations = [];
    /** @var list<int> */
    private array $txs = [];
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        self::assertSame('nexus_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        Response::enableTestMode(true);
        Correlation::reset();
        PawaPayPublicKeyCache::clear();

        $cfg = self::opensslConfig();
        $key = openssl_pkey_new($cfg + [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) {
            $this->markTestSkipped('Extension OpenSSL sans support EC P-256.');
        }
        openssl_pkey_export($key, $this->privateKeyPem, null, $cfg);
        $details = openssl_pkey_get_details($key);
        $this->publicKeyPem = (string) ($details['key'] ?? '');

        putenv('PROVIDER_PAWAPAY_ENABLED=true');
        putenv('PROVIDER_PAWAPAY_ENV=sandbox');
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=local_cycle4_token');
        ProviderWebhookController::overridePawaPayPublicKeyResolver(
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = self::PATH;
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=UTF-8';
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        Correlation::reset();
        PawaPayPublicKeyCache::clear();
        ProviderWebhookController::overridePawaPayPublicKeyResolver(null);
        putenv('PROVIDER_PAWAPAY_ENABLED');
        putenv('PROVIDER_PAWAPAY_ENV');
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
        foreach ([
            'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST', 'CONTENT_TYPE',
            'HTTP_SIGNATURE', 'HTTP_SIGNATURE_INPUT', 'HTTP_CONTENT_DIGEST',
            'HTTP_SIGNATURE_DATE', 'HTTP_CONTENT_TYPE', 'HTTP_X_REQUEST_ID',
        ] as $k) {
            unset($_SERVER[$k]);
        }
        try {
            $this->pdo->exec('DELETE FROM provider_webhook_events WHERE provider = \'pawapay\'');
            foreach ($this->keys as $key) {
                $this->pdo->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = :k')->execute(['k' => $key]);
            }
            if ($this->operations !== []) {
                $ph = implode(',', array_fill(0, count($this->operations), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->operations);
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)")->execute($this->operations);
            }
            if ($this->txs !== []) {
                $ph = implode(',', array_fill(0, count($this->txs), '?'));
                $this->pdo->prepare("DELETE FROM transactions WHERE id IN ($ph)")->execute($this->txs);
            }
            if ($this->users !== []) {
                $ph = implode(',', array_fill(0, count($this->users), '?'));
                $this->pdo->prepare("DELETE FROM notifications WHERE user_id IN ($ph)")->execute($this->users);
                $this->pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ($ph)")->execute($this->users);
                $this->pdo->prepare("DELETE FROM wallets WHERE user_id IN ($ph)")->execute($this->users);
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->users);
            }
        } catch (Throwable) {
        }
    }

    /** @return array{userId:int, walletId:int, operationId:string, txId:int} */
    private function processingSend(): array
    {
        $email = 'fraud.' . bin2hex(random_bytes(4)) . '@nexus.test';
        $this->pdo->prepare(
            "INSERT INTO users (full_name,email,password_hash,account_type,status,kyc_level)
             VALUES ('Fraud','$email','x','personal','ACTIVE','none')"
        )->execute();
        $userId = $this->users[] = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO wallets (user_id,currency,balance,available_balance,hold_balance)
             VALUES (?,'EUR','500.00','500.00','0.00')"
        )->execute([$userId]);
        $walletId = $this->wallets[] = (int) $this->pdo->lastInsertId();

        $operationId = self::uuidV4();
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        $holdKey = 'op:' . $operationId . ':hold';
        $this->keys[] = $holdKey;
        $hold = WalletService::createHold($userId, $walletId, '100.00', 'EUR', $holdKey, 'Envoi EUR → XAF', ['operation_id' => $operationId], $context);
        $this->operations[] = $hold['operation_id'];
        $captureKey = 'op:' . $operationId . ':capture';
        $this->keys[] = $captureKey;
        WalletService::captureHold($hold['operation_id'], $userId, $captureKey, $context);

        $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, dest_amount, dest_currency,
                 status, provider, provider_operation_id, provider_status, environment)
             VALUES (?, 'send', 'out', 'Envoi EUR → XAF', '100.00', 'EUR', '65000.00', 'XAF',
                     'processing', 'pawapay', ?, 'ACCEPTED', 'sandbox')"
        )->execute([$userId, $operationId]);
        $txId = $this->txs[] = (int) $this->pdo->lastInsertId();

        return compact('userId', 'walletId', 'operationId', 'txId');
    }

    /** @param array<string,mixed> $body */
    private function signed(array $body, ?int $created = null, ?int $expires = null): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        $sigDate = PawaPaySignature::signatureDate();
        $digest = PawaPaySignature::contentDigest($json);
        $components = ['@method', '@authority', '@path', 'signature-date', 'content-digest', 'content-type'];
        $created ??= time();
        $expires ??= $created + 60;
        $params = PawaPaySignature::signatureParams($components, 'ecdsa-p256-sha256', self::KEY_ID, $created, $expires);
        $base = PawaPaySignature::signatureBase([
            '@method'        => 'POST',
            '@authority'     => self::HOST,
            '@path'          => self::PATH,
            'signature-date' => $sigDate,
            'content-digest' => $digest,
            'content-type'   => 'application/json; charset=UTF-8',
        ], $params);
        $raw = PawaPaySignature::sign($base, $this->privateKeyPem, 'ecdsa-p256-sha256');

        return [
            'body' => $body,
            'headers' => [
                'content-type'   => 'application/json; charset=UTF-8',
                'content-digest' => $digest,
                'signature-date' => $sigDate,
                'signature'      => PawaPaySignature::signatureHeader($raw),
                'signature-input'=> PawaPaySignature::SIGNATURE_PARAM_NAME . '=' . $params,
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function send(array $signed): array
    {
        foreach ($signed['headers'] as $name => $value) {
            $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $_SERVER[$server] = $value;
        }
        $_SERVER['CONTENT_TYPE'] = $signed['headers']['content-type'];
        $_SERVER['HTTP_HOST'] = self::HOST;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = self::PATH;
        $_SERVER['HTTP_X_REQUEST_ID'] = 'cycle4-' . bin2hex(random_bytes(4));

        $request = new Request($signed['body']);
        $request->setParams(['slug' => 'pawapay']);
        try {
            ProviderWebhookController::handle($request);
            return ['status' => 0, 'code' => null, 'data' => []];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);
            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'data'   => is_array($decoded) ? ($decoded['data'] ?? []) : [],
            ];
        }
    }

    private function wallet(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        self::assertIsArray($row);
        return $row;
    }

    private function tx(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        self::assertIsArray($row);
        return $row;
    }

    public function test_signed_completed_settles_once_and_correlates_ids(): void
    {
        $f = $this->processingSend();
        $before = $this->wallet($f['walletId']);
        self::assertSame('400.00', $before['available_balance']);

        $res = $this->send($this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
            'providerTransactionId' => 'mmo-cycle4-1',
            'user_id' => 999999,
        ]));

        self::assertSame(200, $res['status']);
        self::assertSame('processed', $res['data']['status']);
        self::assertSame($f['txId'], $res['data']['settlement']['transaction_id']);
        self::assertSame($f['operationId'], $res['data']['settlement']['provider_operation_id']);
        self::assertSame('mmo-cycle4-1', $res['data']['settlement']['provider_transaction_id']);
        self::assertNotSame('', $res['data']['request_id']);
        self::assertSame('completed', $this->tx($f['txId'])['status']);

        $after = $this->wallet($f['walletId']);
        self::assertSame($before['available_balance'], $after['available_balance'], 'Pas de second débit wallet au règlement.');

        $audit = $this->pdo->query(
            "SELECT metadata FROM audit_logs WHERE action = 'transfer.settled' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $meta = json_decode((string) $audit, true);
        self::assertIsArray($meta);
        self::assertSame($f['operationId'], $meta['provider_operation_id']);
        self::assertSame($res['data']['request_id'], $meta['request_id']);
        self::assertSame($f['operationId'] . ':COMPLETED', $meta['event_id']);
        self::assertArrayNotHasKey('api_token', $meta);
    }

    public function test_user_id_forge_ne_redirige_pas_le_reglement(): void
    {
        $f = $this->processingSend();
        $email = 'attacker.' . bin2hex(random_bytes(4)) . '@nexus.test';
        $this->pdo->prepare(
            "INSERT INTO users (full_name,email,password_hash,account_type,status,kyc_level)
             VALUES ('Attacker',?,'x','personal','ACTIVE','none')"
        )->execute([$email]);
        $attacker = $this->users[] = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO wallets (user_id,currency,balance,available_balance,hold_balance)
             VALUES (?,'EUR',0,0,0)"
        )->execute([$attacker]);
        $attackerWallet = $this->wallets[] = (int) $this->pdo->lastInsertId();

        $this->send($this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
            'user_id' => $attacker,
        ]));

        self::assertSame('completed', $this->tx($f['txId'])['status']);
        self::assertSame('0.00', $this->wallet($attackerWallet)['available_balance']);
        self::assertSame($f['userId'], (int) $this->tx($f['txId'])['user_id']);
    }

    public function test_montant_mute_refuse_sans_ecriture(): void
    {
        $f = $this->processingSend();
        $res = $this->send($this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '1.00',
            'currency' => 'XAF',
        ]));
        self::assertSame(409, $res['status']);
        self::assertSame('PROVIDER_AMOUNT_MISMATCH', $res['code']);
        self::assertSame('processing', $this->tx($f['txId'])['status']);
        self::assertSame('400.00', $this->wallet($f['walletId'])['available_balance']);
    }

    public function test_devise_mutee_refuse(): void
    {
        $f = $this->processingSend();
        $res = $this->send($this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'USD',
        ]));
        self::assertSame(409, $res['status']);
        self::assertSame('PROVIDER_AMOUNT_MISMATCH', $res['code']);
        self::assertSame('processing', $this->tx($f['txId'])['status']);
    }

    public function test_reference_mutee_refuse(): void
    {
        $f = $this->processingSend();
        $res = $this->send($this->signed([
            'payoutId' => self::uuidV4(),
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
        ]));
        self::assertSame(409, $res['status']);
        self::assertSame('UNKNOWN_PROVIDER_OPERATION', $res['code']);
        self::assertSame('processing', $this->tx($f['txId'])['status']);
    }

    public function test_duplicata_n_ecrit_pas_deux_fois(): void
    {
        $f = $this->processingSend();
        $payload = [
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
        ];
        $first = $this->send($this->signed($payload));
        $second = $this->send($this->signed($payload));
        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertTrue($second['data']['duplicate']);
        self::assertSame('completed', $this->tx($f['txId'])['status']);
        self::assertSame('400.00', $this->wallet($f['walletId'])['available_balance']);
    }

    public function test_signature_perimee_refuse(): void
    {
        $f = $this->processingSend();
        $res = $this->send($this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
        ], time() - 400, time() - 10));
        self::assertSame(401, $res['status']);
        self::assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
        self::assertSame('processing', $this->tx($f['txId'])['status']);
    }

    public function test_signature_invalide_refuse(): void
    {
        $f = $this->processingSend();
        $signed = $this->signed([
            'payoutId' => $f['operationId'],
            'status' => 'COMPLETED',
            'amount' => '65000.00',
            'currency' => 'XAF',
        ]);
        $signed['headers']['signature'] = 'sig-pp=:AAAA:';
        $res = $this->send($signed);
        self::assertSame(401, $res['status']);
        self::assertSame('processing', $this->tx($f['txId'])['status']);
    }

    public function test_malformed_json_refuse_apres_signature_du_corps_alteree(): void
    {
        $signed = $this->signed(['payoutId' => self::uuidV4(), 'status' => 'COMPLETED']);
        $signed['body'] = ['payoutId' => self::uuidV4(), 'status' => 'FAILED'];
        $res = $this->send($signed);
        self::assertSame(401, $res['status']);
        self::assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
    }

    /** @return array<string,string> */
    private static function opensslConfig(): array
    {
        foreach ([
            'C:/xampp/php/extras/openssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ] as $path) {
            if (is_file($path)) {
                return ['config' => $path];
            }
        }
        return [];
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
