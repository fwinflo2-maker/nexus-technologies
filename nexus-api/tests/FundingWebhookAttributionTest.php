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

final class FundingWebhookAttributionTest extends TestCase
{
    private const SECRET = 'funding_test_secret_local';
    private PDO $pdo;
    private array $users = [];
    private array $wallets = [];
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
        }
        foreach ($this->wallets as $id) {
            $this->pdo->prepare('DELETE FROM wallets WHERE id = ?')->execute([$id]);
        }
        foreach ($this->users as $id) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    private function user(string $name): int
    {
        $email = strtolower($name) . '.' . bin2hex(random_bytes(4)) . '@nexus.test';
        $this->pdo->prepare(
            "INSERT INTO users (full_name,email,password_hash,account_type,status,kyc_level)
             VALUES (?,?,'','personal','ACTIVE','none')"
        )->execute([$name, $email]);
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

    public function test_payload_user_id_cannot_redirect_funding_credit(): void
    {
        $victim = $this->user('Victim');
        $attacker = $this->user('Attacker');
        $victimWallet = $this->wallet($victim);
        $attackerWallet = $this->wallet($attacker);
        $reference = 'dep_' . bin2hex(random_bytes(8));
        $intent = FundingIntentService::create(
            $victim,
            $victimWallet,
            'pawapay',
            $reference,
            'EUR',
            '100.00',
            ExecutionContext::explicit($victim, ExecutionEnvironment::SANDBOX)
        );
        $this->intents[] = $intent['id'];

        $payload = [
            'provider' => 'pawapay',
            'provider_reference' => $reference,
            'currency' => 'EUR',
            'amount' => '100.00',
            'status' => 'COMPLETED',
            'user_id' => $attacker,
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = time();
        $_SERVER['HTTP_X_NEXUS_SIGNATURE'] =
            't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . (string) $raw, self::SECRET);

        try {
            FundingController::deposit(new Request($payload));
            self::fail('ResponseSent attendue.');
        } catch (ResponseSent $sent) {
            self::assertSame(200, $sent->statusCode());
        }

        $victimBalance = $this->pdo->query("SELECT available_balance FROM wallets WHERE id = $victimWallet")->fetchColumn();
        $attackerBalance = $this->pdo->query("SELECT available_balance FROM wallets WHERE id = $attackerWallet")->fetchColumn();
        self::assertSame('100.00', $victimBalance);
        self::assertSame('0.00', $attackerBalance, 'Le user_id du payload ne reçoit jamais les fonds.');
    }
}
