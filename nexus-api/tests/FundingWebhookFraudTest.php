<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\FundingController;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\FundingIntentService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fraude funding : mutations user_id / référence / montant / devise,
 * duplicata. Attribution uniquement par intent pré-créé.
 */
final class FundingWebhookFraudTest extends TestCase
{
    private const SECRET = 'funding_cycle4_secret_local';
    private PDO $pdo;
    /** @var list<int> */
    private array $users = [];
    /** @var list<int> */
    private array $wallets = [];
    /** @var list<int> */
    private array $intents = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        self::assertSame('nexus_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        Response::enableTestMode(true);
        putenv('PROVIDER_PAWAPAY_ENV=sandbox');
        putenv('PROVIDER_PAWAPAY_SANDBOX_WEBHOOK_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        putenv('PROVIDER_PAWAPAY_ENV');
        putenv('PROVIDER_PAWAPAY_SANDBOX_WEBHOOK_SECRET');
        unset($_SERVER['HTTP_X_NEXUS_SIGNATURE']);
        $this->pdo->exec(
            "DELETE FROM provider_webhook_events WHERE event_type = 'funding.deposit' AND event_id LIKE 'funding:dep_%'"
        );
        foreach ($this->intents as $id) {
            $this->pdo->prepare('DELETE FROM funding_intents WHERE id = ?')->execute([$id]);
        }
        if ($this->users !== []) {
            $ph = implode(',', array_fill(0, count($this->users), '?'));
            $ops = $this->pdo->prepare("SELECT id FROM wallet_operations WHERE user_id IN ($ph)");
            $ops->execute($this->users);
            $opIds = $ops->fetchAll(PDO::FETCH_COLUMN);
            if ($opIds !== []) {
                $oph = implode(',', array_fill(0, count($opIds), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($oph)")->execute($opIds);
                $this->pdo->prepare("DELETE FROM idempotency_keys WHERE operation_id IN ($oph)")->execute($opIds);
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($oph)")->execute($opIds);
            }
            $this->pdo->prepare("DELETE FROM wallets WHERE user_id IN ($ph)")->execute($this->users);
            $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->users);
        }
    }

    private function user(): int
    {
        $email = 'fund.' . bin2hex(random_bytes(4)) . '@nexus.test';
        $this->pdo->prepare(
            "INSERT INTO users (full_name,email,password_hash,account_type,status,kyc_level)
             VALUES ('Fund',?,'x','personal','ACTIVE','none')"
        )->execute([$email]);
        return $this->users[] = (int) $this->pdo->lastInsertId();
    }

    private function wallet(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO wallets (user_id,currency,balance,available_balance,hold_balance)
             VALUES (?,'EUR',0,0,0)"
        )->execute([$userId]);
        return $this->wallets[] = (int) $this->pdo->lastInsertId();
    }

    /** @return array{status:int, code:?string} */
    private function deposit(array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = time();
        $_SERVER['HTTP_X_NEXUS_SIGNATURE'] =
            't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . (string) $raw, self::SECRET);
        try {
            FundingController::deposit(new Request($payload));
            return ['status' => 0, 'code' => null];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);
            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
            ];
        }
    }

    public function test_montant_mute_refuse(): void
    {
        $uid = $this->user();
        $wid = $this->wallet($uid);
        $ref = 'dep_' . bin2hex(random_bytes(8));
        $intent = FundingIntentService::create(
            $uid, $wid, 'pawapay', $ref, 'EUR', '100.00',
            ExecutionContext::explicit($uid, ExecutionEnvironment::SANDBOX)
        );
        $this->intents[] = $intent['id'];

        $res = $this->deposit([
            'provider' => 'pawapay',
            'provider_reference' => $ref,
            'currency' => 'EUR',
            'amount' => '999.00',
            'status' => 'COMPLETED',
        ]);
        self::assertSame(409, $res['status']);
        self::assertSame('FUNDING_INTENT_MISMATCH', $res['code']);
        $bal = $this->pdo->query("SELECT available_balance FROM wallets WHERE id = $wid")->fetchColumn();
        self::assertSame('0.00', $bal);
    }

    public function test_devise_mutee_refuse(): void
    {
        $uid = $this->user();
        $wid = $this->wallet($uid);
        $ref = 'dep_' . bin2hex(random_bytes(8));
        $intent = FundingIntentService::create(
            $uid, $wid, 'pawapay', $ref, 'EUR', '100.00',
            ExecutionContext::explicit($uid, ExecutionEnvironment::SANDBOX)
        );
        $this->intents[] = $intent['id'];

        $res = $this->deposit([
            'provider' => 'pawapay',
            'provider_reference' => $ref,
            'currency' => 'USD',
            'amount' => '100.00',
            'status' => 'COMPLETED',
        ]);
        self::assertSame(409, $res['status']);
        self::assertSame('FUNDING_INTENT_MISMATCH', $res['code']);
    }

    public function test_reference_inconnue_refuse(): void
    {
        $res = $this->deposit([
            'provider' => 'pawapay',
            'provider_reference' => 'dep_unknown_' . bin2hex(random_bytes(4)),
            'currency' => 'EUR',
            'amount' => '100.00',
            'status' => 'COMPLETED',
        ]);
        self::assertSame(409, $res['status']);
        self::assertSame('UNKNOWN_FUNDING_INTENT', $res['code']);
    }

    public function test_duplicata_ne_double_pas_le_credit(): void
    {
        $uid = $this->user();
        $wid = $this->wallet($uid);
        $ref = 'dep_' . bin2hex(random_bytes(8));
        $intent = FundingIntentService::create(
            $uid, $wid, 'pawapay', $ref, 'EUR', '50.00',
            ExecutionContext::explicit($uid, ExecutionEnvironment::SANDBOX)
        );
        $this->intents[] = $intent['id'];
        $payload = [
            'provider' => 'pawapay',
            'provider_reference' => $ref,
            'currency' => 'EUR',
            'amount' => '50.00',
            'status' => 'COMPLETED',
        ];
        self::assertSame(200, $this->deposit($payload)['status']);
        self::assertSame(200, $this->deposit($payload)['status']);
        $bal = $this->pdo->query("SELECT available_balance FROM wallets WHERE id = $wid")->fetchColumn();
        self::assertSame('50.00', $bal);
    }

    public function test_signature_invalide_refuse(): void
    {
        $payload = [
            'provider' => 'pawapay',
            'provider_reference' => 'dep_x',
            'currency' => 'EUR',
            'amount' => '10.00',
            'status' => 'COMPLETED',
        ];
        $_SERVER['HTTP_X_NEXUS_SIGNATURE'] = 'deadbeef';
        try {
            FundingController::deposit(new Request($payload));
            self::fail('ResponseSent attendue.');
        } catch (ResponseSent $sent) {
            self::assertSame(401, $sent->statusCode());
        }
    }
}
