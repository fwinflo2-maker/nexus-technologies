<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\ProviderWebhookController;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Webhooks PROVIDERS — fail-closed et idempotence (§13).
 *
 * Vérifie le contrat de l'endpoint :
 *  - AUCUN secret configuré → refus (501) : on n'accepte jamais un webhook
 *    non vérifiable ;
 *  - signature HMAC exigée AVANT toute interprétation du contenu ;
 *  - idempotence par (provider, environment, event_id) — le rejeu est
 *    acquitté 200 sans traitement ;
 *  - environnement déclaré incohérent → refus (409) ;
 *  - l'événement est persisté (identité uniquement, jamais de payload).
 */
final class ProviderWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_webhook_local';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        Response::enableTestMode(true);
        $this->pdo->exec('DELETE FROM provider_webhook_events');
        putenv('PROVIDER_STRIPE_SANDBOX_WEBHOOK_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        putenv('PROVIDER_STRIPE_SANDBOX_WEBHOOK_SECRET');
        unset($_SERVER['HTTP_X_NEXUS_SIGNATURE']);
        $this->pdo->exec('DELETE FROM provider_webhook_events');
    }

    /**
     * Signe le corps EXACT que rawBody() restituera (json_encode sans
     * échappement des slashes ni des caractères Unicode).
     */
    private function sign(array $payload, ?string $secret = self::SECRET): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', (string) $raw, (string) $secret);
    }

    /** @return array{status:int, code:?string, data:array<string,mixed>} */
    private function send(array $payload, ?string $signature = null): array
    {
        if ($signature === null) {
            $signature = $this->sign($payload);
        }
        $_SERVER['HTTP_X_NEXUS_SIGNATURE'] = $signature;

        $request = new Request($payload);
        $request->setParams(['slug' => 'stripe']);

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

    private function countEvents(string $eventId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM provider_webhook_events WHERE provider = :p AND event_id = :e'
        );
        $stmt->execute(['p' => 'stripe', 'e' => $eventId]);

        return (int) $stmt->fetchColumn();
    }

    public function test_sans_secret_configure_le_webhook_est_refuse(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_WEBHOOK_SECRET');

        $res = $this->send(['event_id' => 'evt_nosecret', 'type' => 'payment.succeeded']);

        $this->assertSame(501, $res['status']);
        $this->assertSame('WEBHOOK_NOT_CONFIGURED', $res['code']);
        $this->assertSame(0, $this->countEvents('evt_nosecret'));
    }

    public function test_webhook_signe_accepte_et_persiste(): void
    {
        $res = $this->send(['event_id' => 'evt_ok_1', 'type' => 'payment.succeeded']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['received']);
        $this->assertFalse($res['data']['duplicate']);
        $this->assertSame('evt_ok_1', $res['data']['event_id']);
        $this->assertSame(1, $this->countEvents('evt_ok_1'));

        // Seule l'identité est persistée, jamais le payload.
        $row = $this->pdo->query(
            "SELECT provider, environment, event_id, event_type, status FROM provider_webhook_events WHERE event_id = 'evt_ok_1'"
        )->fetch();
        $this->assertSame('stripe', $row['provider']);
        $this->assertSame('sandbox', $row['environment']);
        $this->assertSame('payment.succeeded', $row['event_type']);
        $this->assertSame('received', $row['status']);
    }

    public function test_webhook_rejoue_repond_200_duplicate_sans_double_traitement(): void
    {
        $payload = ['event_id' => 'evt_replay_1', 'type' => 'payment.succeeded'];

        $first  = $this->send($payload);
        $second = $this->send($payload);

        $this->assertSame(200, $first['status']);
        $this->assertFalse($first['data']['duplicate']);
        $this->assertSame(200, $second['status']);
        $this->assertTrue($second['data']['duplicate']);
        // Un rejeu ne crée jamais une seconde ligne.
        $this->assertSame(1, $this->countEvents('evt_replay_1'));
    }

    public function test_webhook_sans_signature_refuse(): void
    {
        $res = $this->send(['event_id' => 'evt_nosig', 'type' => 'payment.succeeded'], '');

        $this->assertSame(401, $res['status']);
        $this->assertSame('INVALID_WEBHOOK_SIGNATURE', $res['code']);
        $this->assertSame(0, $this->countEvents('evt_nosig'));
    }

    public function test_webhook_signature_invalide_refuse(): void
    {
        $res = $this->send(['event_id' => 'evt_badsig', 'type' => 'payment.succeeded'], 'deadbeef');

        $this->assertSame(401, $res['status']);
        $this->assertSame(0, $this->countEvents('evt_badsig'));
    }

    public function test_webhook_payload_modifie_refuse(): void
    {
        // Signature calculée sur le payload original ; le contenu est falsifié.
        $original = ['event_id' => 'evt_tampered', 'type' => 'payment.pending'];
        $signature = $this->sign($original);

        $res = $this->send(['event_id' => 'evt_tampered', 'type' => 'payment.succeeded'], $signature);

        $this->assertSame(401, $res['status']);
        $this->assertSame(0, $this->countEvents('evt_tampered'));
    }

    public function test_webhook_environnement_declare_incoherent_refuse(): void
    {
        $res = $this->send([
            'event_id'    => 'evt_env',
            'type'        => 'payment.succeeded',
            'environment' => 'production',
        ]);

        $this->assertSame(409, $res['status']);
        $this->assertSame('WEBHOOK_ENVIRONMENT_MISMATCH', $res['code']);
        $this->assertSame(0, $this->countEvents('evt_env'));
    }

    public function test_webhook_sans_identifiant_devenement_refuse(): void
    {
        $res = $this->send(['type' => 'payment.succeeded']);

        $this->assertSame(400, $res['status']);
        $this->assertSame('INVALID_WEBHOOK_PAYLOAD', $res['code']);
    }

}
