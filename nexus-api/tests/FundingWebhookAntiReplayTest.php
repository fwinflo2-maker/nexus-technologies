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
use Nexus\Providers\WebhookVerifier;
use Nexus\Services\FundingIntentService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Anti-rejeu funding (Cycle 5, P1 Cycle 4) :
 *   - signature horodatée t=...,v1=... (HMAC couvre t.corps) ;
 *   - fenêtre de validité ±300 s — signature capturée rejouée tard = refus ;
 *   - format legacy (HMAC nu du corps) refusé ;
 *   - rejeu par event_id journalisé, jamais de double crédit ;
 *   - rotation : plusieurs v1, une valide suffit.
 */
final class FundingWebhookAntiReplayTest extends TestCase
{
    private const SECRET = 'funding_cycle5_replay_secret_local';
    private PDO $pdo;
    /** @var list<int> */
    private array $users = [];
    /** @var list<int> */
    private array $wallets = [];
    /** @var list<string> */
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
        $this->pdo->exec(
            "DELETE FROM audit_logs WHERE action LIKE 'funding.webhook.%'"
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
        $email = 'replay.' . bin2hex(random_bytes(4)) . '@nexus.test';
        $this->pdo->prepare(
            "INSERT INTO users (full_name,email,password_hash,account_type,status,kyc_level)
             VALUES ('Replay',?,'x','personal','ACTIVE','none')"
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

    /** @return array{uid:int, wid:int, ref:string} */
    private function intent(string $amount = '25.00'): array
    {
        $uid = $this->user();
        $wid = $this->wallet($uid);
        $ref = 'dep_' . bin2hex(random_bytes(8));
        $intent = FundingIntentService::create(
            $uid, $wid, 'pawapay', $ref, 'EUR', $amount,
            ExecutionContext::explicit($uid, ExecutionEnvironment::SANDBOX)
        );
        $this->intents[] = $intent['id'];
        return ['uid' => $uid, 'wid' => $wid, 'ref' => $ref];
    }

    private function sign(string $raw, int $timestamp, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
    }

    /** @return array{status:int, code:?string, body:array} */
    private function post(array $payload, string $signatureHeader): array
    {
        $_SERVER['HTTP_X_NEXUS_SIGNATURE'] = $signatureHeader;
        try {
            FundingController::deposit(new Request($payload));
            return ['status' => 0, 'code' => null, 'body' => []];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);
            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'body'   => is_array($decoded) ? $decoded : [],
            ];
        }
    }

    private function payload(string $ref, string $amount = '25.00'): array
    {
        return [
            'provider' => 'pawapay',
            'provider_reference' => $ref,
            'currency' => 'EUR',
            'amount' => $amount,
            'status' => 'COMPLETED',
        ];
    }

    private function raw(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function balance(int $walletId): string
    {
        return (string) $this->pdo
            ->query("SELECT available_balance FROM wallets WHERE id = $walletId")
            ->fetchColumn();
    }

    public function test_signature_fraiche_acceptee(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $res = $this->post($payload, $this->sign($this->raw($payload), time()));
        self::assertSame(200, $res['status']);
        self::assertSame('25.00', $this->balance($ctx['wid']));
    }

    public function test_signature_capturee_rejouee_apres_fenetre_refusee(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        // Signature parfaitement valide... émise il y a 6 minutes.
        $stale = $this->sign($this->raw($payload), time() - 360);
        $res = $this->post($payload, $stale);
        self::assertSame(401, $res['status']);
        self::assertSame('WEBHOOK_SIGNATURE_STALE', $res['code']);
        self::assertSame('0.00', $this->balance($ctx['wid']), 'Aucun crédit sur signature rejouée.');
    }

    public function test_timestamp_futur_hors_fenetre_refuse(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $res = $this->post($payload, $this->sign($this->raw($payload), time() + 600));
        self::assertSame(401, $res['status']);
        self::assertSame('WEBHOOK_SIGNATURE_STALE', $res['code']);
    }

    public function test_format_legacy_sans_timestamp_refuse(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        // Ancien format Cycle 4 : HMAC nu du corps — refusé désormais.
        $legacy = hash_hmac('sha256', $this->raw($payload), self::SECRET);
        $res = $this->post($payload, $legacy);
        self::assertSame(401, $res['status']);
        self::assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
        self::assertSame('0.00', $this->balance($ctx['wid']));
    }

    public function test_timestamp_valide_mais_hmac_faux_refuse(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $res = $this->post($payload, $this->sign($this->raw($payload), time(), 'wrong_secret'));
        self::assertSame(401, $res['status']);
        self::assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
    }

    public function test_timestamp_non_signe_ne_deplace_pas_la_fenetre(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $raw = $this->raw($payload);
        // HMAC calculé pour t-360 (stale) mais en-tête t=now : mismatch.
        $staleTs = time() - 360;
        $forged = 't=' . time() . ',v1=' . hash_hmac('sha256', $staleTs . '.' . $raw, self::SECRET);
        $res = $this->post($payload, $forged);
        self::assertSame(401, $res['status']);
        self::assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
    }

    public function test_rotation_plusieurs_v1_une_valide_suffit(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $raw = $this->raw($payload);
        $ts = time();
        $good = hash_hmac('sha256', $ts . '.' . $raw, self::SECRET);
        $old  = hash_hmac('sha256', $ts . '.' . $raw, 'previous_rotated_secret');
        $res = $this->post($payload, 't=' . $ts . ',v1=' . $old . ',v1=' . $good);
        self::assertSame(200, $res['status']);
        self::assertSame('25.00', $this->balance($ctx['wid']));
    }

    public function test_rejeu_event_id_acquitte_sans_double_credit(): void
    {
        $ctx = $this->intent('40.00');
        $payload = $this->payload($ctx['ref'], '40.00');
        $raw = $this->raw($payload);

        $first = $this->post($payload, $this->sign($raw, time()));
        self::assertSame(200, $first['status']);
        self::assertFalse((bool) ($first['body']['data']['duplicate'] ?? true));

        // Rejeu dans la fenêtre avec une signature fraîche : détecté par
        // event_id, acquitté, un seul crédit.
        $second = $this->post($payload, $this->sign($raw, time()));
        self::assertSame(200, $second['status']);
        self::assertTrue((bool) ($second['body']['data']['duplicate'] ?? false));
        self::assertSame('40.00', $this->balance($ctx['wid']));

        $events = $this->pdo->query(
            "SELECT COUNT(*) FROM provider_webhook_events
             WHERE provider = 'pawapay' AND event_id = 'funding:{$ctx['ref']}:COMPLETED'"
        )->fetchColumn();
        self::assertSame(1, (int) $events, 'Un seul événement enregistré pour l\'event_id.');

        $dupAudit = $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'funding.webhook.duplicate'"
        )->fetchColumn();
        self::assertGreaterThanOrEqual(1, (int) $dupAudit, 'Le rejeu est audité.');
    }

    public function test_event_id_distinct_meme_reference_un_seul_credit(): void
    {
        $ctx = $this->intent('15.00');
        $payloadA = $this->payload($ctx['ref'], '15.00') + ['event_id' => 'evt_a_' . bin2hex(random_bytes(4))];
        $payloadB = $this->payload($ctx['ref'], '15.00') + ['event_id' => 'evt_b_' . bin2hex(random_bytes(4))];

        self::assertSame(200, $this->post($payloadA, $this->sign($this->raw($payloadA), time()))['status']);
        self::assertSame(200, $this->post($payloadB, $this->sign($this->raw($payloadB), time()))['status']);
        self::assertSame('15.00', $this->balance($ctx['wid']), 'Idempotence métier même si event_id change.');
        // Nettoyage des events à event_id fourni (hors motif dep_).
        $this->pdo->exec(
            "DELETE FROM provider_webhook_events WHERE event_type = 'funding.deposit' AND event_id LIKE 'funding:evt_%'"
        );
    }

    public function test_signature_stale_est_auditee(): void
    {
        $ctx = $this->intent();
        $payload = $this->payload($ctx['ref']);
        $this->post($payload, $this->sign($this->raw($payload), time() - 3600));
        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'funding.webhook.rejected'
               AND metadata LIKE '%stale_timestamp%'"
        )->fetchColumn();
        self::assertGreaterThanOrEqual(1, (int) $count);
    }

    public function test_verifier_unitaire_fenetre_et_raisons(): void
    {
        $raw = '{"a":1}';
        $secret = 'unit_secret';
        $ts = 1_700_000_000;
        $header = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $raw, $secret);

        $ok = WebhookVerifier::verifyTimestamped($raw, $header, $secret, 300, $ts + 299);
        self::assertTrue($ok['valid']);

        $stale = WebhookVerifier::verifyTimestamped($raw, $header, $secret, 300, $ts + 301);
        self::assertFalse($stale['valid']);
        self::assertSame('stale_timestamp', $stale['reason']);

        $missing = WebhookVerifier::verifyTimestamped($raw, 'v1=abcdef', $secret, 300, $ts);
        self::assertFalse($missing['valid']);
        self::assertSame('missing_timestamp', $missing['reason']);

        $bad = WebhookVerifier::verifyTimestamped($raw, 't=' . $ts . ',v1=deadbeef', $secret, 300, $ts);
        self::assertFalse($bad['valid']);
        self::assertSame('signature_mismatch', $bad['reason']);
    }
}
