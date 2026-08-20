<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Core\Correlation;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Providers\PawaPayPublicKeyCache;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Providers\PawaPayAdapter;
use Nexus\Providers\PawaPaySignature;
use Nexus\Providers\StripeAdapter;
use Nexus\Providers\WebhookVerifier;
use Nexus\Services\ExecutionSettlementService;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;
use PDO;
use Throwable;

/**
 * Webhooks PROVIDERS — ingestion préparée, fail-closed (§13).
 *
 *   POST /api/providers/webhook/{slug}
 *
 * Route PUBLIQUE (le provider n'a pas de session utilisateur) : l'authentification
 * se fait par SIGNATURE HMAC, vérifiée AVANT toute interprétation du contenu.
 *
 * Contrat :
 *  - signature attendue dans l'en-tête `X-Nexus-Signature` (hex brut ou
 *    `sha256=…`) ; l'adaptateur de chaque provider adaptera le nom d'en-tête
 *    propre au provider lors de son intégration réelle ;
 *  - secret résolu depuis les credentials de la plateforme (`webhook_secret`)
 *    puis, à défaut, depuis l'environnement (`PROVIDER_{SLUG}_{ENV}_WEBHOOK_SECRET`) ;
 *  - AUCUN secret configuré → refus (501 WEBHOOK_NOT_CONFIGURED) : on n'accepte
 *    jamais un webhook non vérifiable ;
 *  - idempotence par (provider, environment, event_id) — un rejeu est acquitté
 *    200 sans traitement ;
 *  - aucun payload ni secret journalisé : seul l'identité de l'événement est
 *    persistée (la source de vérité documentaire reste le provider) ;
 *  - aucune transition d'état métier n'est dérivée à ce stade (aucune
 *    intégration réelle) : l'événement est persisté et acquitté pour la future
 *    réconciliation — jamais un état inventé (§9).
 */
final class ProviderWebhookController
{
    /** En-tête de signature attendu (convention Nexus). */
    private const SIGNATURE_HEADER = 'X-Nexus-Signature';

    /** @var null|callable(string):?string */
    private static $pawaPayPublicKeyOverride = null;

    /**
     * Injecte un résolveur de clé publique pawaPay (tests RFC-9421).
     * Production laisse null : la clé vient de GET /v2/public-key/http.
     *
     * @param null|callable(string):?string $resolver
     */
    public static function overridePawaPayPublicKeyResolver(?callable $resolver): void
    {
        self::$pawaPayPublicKeyOverride = $resolver;
    }

    public static function handle(Request $request): void
    {
        Correlation::bindFromRequest($request);
        $slug = (string) $request->param('slug', '');
        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        // 1) Credentials de vérification. Chaque provider utilise son contrat
        //    natif ; aucune signature générique ne remplace Stripe-Signature
        //    ou RFC-9421.
        $env    = ProviderConfig::activeEnvironment($slug);
        $pdo    = Database::getConnection();
        $creds  = ProviderCredentialService::resolvePlatform($pdo, $slug, $env) ?? [];

        // 2) Signature AVANT toute interprétation du contenu.
        $raw = $request->rawBody();
        if (!self::verifyProviderSignature($request, $slug, $env, $creds, $raw)) {
            self::audit($pdo, $slug, $env, '', 'rejected', false, $request, 'INVALID_WEBHOOK_SIGNATURE');
            Response::error('Signature de webhook invalide.', 401, 'INVALID_WEBHOOK_SIGNATURE');
        }

        // 3) Enveloppe minimale : identifiant d'événement obligatoire.
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::error('Payload de webhook invalide.', 400, 'INVALID_WEBHOOK_PAYLOAD');
        }
        $eventId = self::eventId($slug, $payload);
        if ($eventId === '') {
            Response::error('Identifiant d\'événement manquant.', 400, 'INVALID_WEBHOOK_PAYLOAD');
        }

        // L'événement peut déclarer son environnement : s'il est déclaré et
        // différent de l'environnement actif du provider, il est rejeté.
        $declaredEnv = ProviderCredentialService::normalizeEnvironment((string) ($payload['environment'] ?? ''));
        if ($declaredEnv !== null && $declaredEnv !== $env) {
            Response::error('Environnement de webhook incohérent.', 409, 'WEBHOOK_ENVIRONMENT_MISMATCH');
        }

        $eventType = substr((string) ($payload['event_type'] ?? $payload['type'] ?? $payload['status'] ?? 'unknown'), 0, 100);

        // 4) Persistance idempotente : la base est la clé d'idempotence
        //    (provider, environment, event_id). Un rejeu est acquitté 200.
        $duplicate = self::persist($pdo, $slug, $env, $eventId, $eventType);

        // 5) Résolution par référence provider uniquement. Un user_id reçu
        //    dans le payload n'est jamais consulté.
        $settlement = null;
        if (!$duplicate) {
            $settlement = self::settleKnownOperation($pdo, $slug, $env, $payload, $eventId);
        }

        // 6) Audit : identité de l'événement uniquement, jamais le payload,
        //    jamais un secret.
        self::audit($pdo, $slug, $env, $eventId, $eventType, $duplicate, $request);

        Response::success([
            'received'   => true,
            'duplicate'  => $duplicate,
            'event_id'   => $eventId,
            'request_id' => Correlation::id(),
            'status'     => $settlement === null ? 'received' : 'processed',
            'settlement' => $settlement,
        ]);
    }

    /** @param array<string,string> $creds */
    private static function verifyProviderSignature(
        Request $request,
        string $slug,
        string $env,
        array $creds,
        string $raw
    ): bool {
        if ($slug === 'stripe') {
            $secret = $creds['webhook_secret'] ?? ProviderConfig::credential($slug, 'WEBHOOK_SECRET', $env);
            if (!is_string($secret) || $secret === '') {
                Response::error('Secret Stripe webhook non configuré.', 501, 'WEBHOOK_NOT_CONFIGURED');
            }
            return StripeAdapter::verifyStripeSignature(
                $raw,
                (string) ($request->header('Stripe-Signature') ?? ''),
                $secret
            );
        }

        if ($slug === 'pawapay') {
            $token = $creds['api_token'] ?? ProviderConfig::credential($slug, 'API_TOKEN', $env);
            if (!is_string($token) || $token === '') {
                Response::error('Token pawaPay non configuré : clé callback non résoluble.', 501, 'WEBHOOK_NOT_CONFIGURED');
            }
            $headers = $request->headers();
            $headers['@method'] = $request->method();
            $headers['@authority'] = (string) ($request->header('Host') ?? '');
            $headers['@path'] = '/api' . $request->path();

            $resolver = self::$pawaPayPublicKeyOverride
                ?? static fn (string $keyId): ?string => self::resolvePawaPayPublicKey($env, $token, $keyId);

            return PawaPaySignature::verifyCallback($raw, $headers, $resolver);
        }

        $secret = $creds['webhook_secret'] ?? ProviderConfig::credential($slug, 'WEBHOOK_SECRET', $env);
        if (!is_string($secret) || $secret === '') {
            Response::error('Secret webhook non configuré.', 501, 'WEBHOOK_NOT_CONFIGURED');
        }
        return WebhookVerifier::verify(
            $raw,
            (string) ($request->header(self::SIGNATURE_HEADER) ?? ''),
            $secret
        );
    }

    private static function resolvePawaPayPublicKey(string $env, string $token, string $keyId): ?string
    {
        $cached = PawaPayPublicKeyCache::get($env, $keyId);
        if ($cached !== null) {
            return $cached;
        }
        $pem = self::fetchPawaPayPublicKey($env, $token, $keyId);
        if ($pem !== null) {
            PawaPayPublicKeyCache::put($env, $keyId, $pem);
        }
        return $pem;
    }

    private static function fetchPawaPayPublicKey(string $env, string $token, string $keyId): ?string
    {
        $url = rtrim(ProviderConfig::baseUrl('pawapay', $env), '/') . '/v2/public-key/http';
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            return null;
        }
        $keys = json_decode($body, true);
        if (!is_array($keys)) {
            return null;
        }
        foreach ($keys as $key) {
            if (is_array($key) && hash_equals((string) ($key['id'] ?? ''), $keyId)) {
                $pem = (string) ($key['key'] ?? '');
                return $pem !== '' ? $pem : null;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    private static function eventId(string $slug, array $payload): string
    {
        if ($slug === 'pawapay') {
            $payoutId = (string) ($payload['payoutId'] ?? '');
            $status = strtoupper((string) ($payload['status'] ?? ''));
            return $payoutId !== '' && $status !== '' ? $payoutId . ':' . $status : '';
        }
        return (string) ($payload['event_id'] ?? $payload['id'] ?? '');
    }

    /** @param array<string,mixed> $payload */
    private static function settleKnownOperation(
        PDO $pdo,
        string $slug,
        string $env,
        array $payload,
        string $eventId
    ): ?array {
        $providerOperationId = '';
        $rawStatus = '';
        $mapped = null;

        if ($slug === 'pawapay') {
            $providerOperationId = (string) ($payload['payoutId'] ?? '');
            $rawStatus = strtoupper((string) ($payload['status'] ?? ''));
            $mapped = PawaPayAdapter::STATUS_MAP[$rawStatus] ?? null;
        } elseif ($slug === 'stripe') {
            $type = (string) ($payload['type'] ?? '');
            $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
            $providerOperationId = (string) ($object['id'] ?? '');
            $rawStatus = $type;
            $mapped = match ($type) {
                'payment_intent.succeeded', 'payout.paid' => 'completed',
                'payment_intent.payment_failed', 'payment_intent.canceled', 'payout.failed', 'payout.canceled' => 'failed',
                default => null,
            };
        }

        if ($mapped === null || $mapped === 'processing') {
            return null;
        }
        if ($providerOperationId === '') {
            Response::error('Référence provider absente.', 400, 'INVALID_WEBHOOK_PAYLOAD');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM transactions
             WHERE provider = :provider AND provider_operation_id = :operation AND environment = :environment
             LIMIT 1'
        );
        $stmt->execute(['provider' => $slug, 'operation' => $providerOperationId, 'environment' => $env]);
        $tx = $stmt->fetch();
        if ($tx === false) {
            self::markEvent($pdo, $slug, $env, $eventId, 'unmatched');
            Response::error('Opération provider inconnue.', 409, 'UNKNOWN_PROVIDER_OPERATION');
        }

        // Les montants du webhook ne pilotent jamais le ledger. Ils servent
        // uniquement à détecter une divergence avant le règlement.
        if ($slug === 'pawapay' && isset($payload['amount'], $payload['currency'])) {
            $expectedAmount = (string) ($tx['dest_amount'] ?? '');
            $expectedCurrency = strtoupper((string) ($tx['dest_currency'] ?? ''));
            if ($expectedAmount === '' || bccomp($expectedAmount, (string) $payload['amount'], 8) !== 0
                || $expectedCurrency !== strtoupper((string) $payload['currency'])) {
                self::markEvent($pdo, $slug, $env, $eventId, 'reconciliation_required');
                Response::error('Montant ou devise provider divergent.', 409, 'PROVIDER_AMOUNT_MISMATCH');
            }
        }
        if ($slug === 'stripe') {
            $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
            if (isset($object['amount'], $object['currency'])) {
                $expectedCurrency = strtoupper((string) (($tx['dest_currency'] ?: $tx['currency']) ?? ''));
                $expectedAmount = (string) (($tx['dest_amount'] ?: $tx['amount']) ?? '');
                $providerCurrency = strtoupper((string) $object['currency']);
                $providerMinor = (string) $object['amount'];
                $zeroDecimal = in_array($providerCurrency, ['XAF', 'XOF', 'JPY', 'KRW', 'VND', 'CLP', 'UGX', 'RWF'], true);
                $providerMajor = $zeroDecimal
                    ? bcadd($providerMinor, '0', 2)
                    : bcdiv($providerMinor, '100', 2);
                if ($expectedAmount === '' || $expectedCurrency !== $providerCurrency
                    || bccomp($expectedAmount, $providerMajor, 2) !== 0) {
                    self::markEvent($pdo, $slug, $env, $eventId, 'reconciliation_required');
                    Response::error('Montant ou devise provider divergent.', 409, 'PROVIDER_AMOUNT_MISMATCH');
                }
            }
        }

        $providerTxnId = '';
        if ($slug === 'pawapay') {
            $providerTxnId = (string) ($payload['providerTransactionId'] ?? '');
        } elseif ($slug === 'stripe') {
            $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
            $providerTxnId = (string) ($object['balance_transaction'] ?? $object['id'] ?? '');
        }

        $result = ExecutionSettlementService::settle($tx, $mapped, $rawStatus, [
            'source' => 'webhook',
            'event_id' => $eventId,
            'request_id' => Correlation::id(),
            'provider_transaction_id' => $providerTxnId !== '' ? $providerTxnId : null,
        ]);
        self::markEvent($pdo, $slug, $env, $eventId, 'processed');
        return [
            'transaction_id'         => (int) $tx['id'],
            'action'                 => $result['action'] ?? 'settled',
            'request_id'             => Correlation::id(),
            'event_id'               => $eventId,
            'provider_operation_id'  => $providerOperationId,
            'provider_transaction_id'=> $providerTxnId !== '' ? $providerTxnId : null,
        ];
    }

    private static function markEvent(PDO $pdo, string $slug, string $env, string $eventId, string $status): void
    {
        $pdo->prepare(
            'UPDATE provider_webhook_events SET status = :status
             WHERE provider = :provider AND environment = :environment AND event_id = :event'
        )->execute(['status' => $status, 'provider' => $slug, 'environment' => $env, 'event' => $eventId]);
    }

    private static function persist(PDO $pdo, string $slug, string $env, string $eventId, string $eventType): bool
    {
        $stmt = $pdo->prepare(
            'INSERT INTO provider_webhook_events (provider, environment, event_id, event_type, status)
             VALUES (:p, :e, :i, :t, :s)'
        );
        try {
            $stmt->execute([
                'p' => $slug,
                'e' => $env,
                'i' => $eventId,
                't' => $eventType,
                's' => 'received',
            ]);

            return false;
        } catch (\PDOException $e) {
            // 1062 = duplicate entry sur uq_provider_webhook_event : rejeu.
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
            throw $e;
        }
    }

    private static function audit(
        PDO $pdo,
        string $slug,
        string $env,
        string $eventId,
        string $eventType,
        bool $duplicate,
        Request $request,
        ?string $reason = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address, environment, created_at)
                 VALUES (NULL, :act, :etype, :eid, :meta, :ip, :env, NOW())'
            );
            $meta = [
                'provider'   => $slug,
                'event_id'   => $eventId,
                'event_type' => $eventType,
                'request_id' => Correlation::id(),
            ];
            if ($reason !== null) {
                $meta['reason'] = $reason;
            }
            $stmt->execute([
                'act'   => $reason !== null
                    ? 'provider.webhook.rejected'
                    : ($duplicate ? 'provider.webhook.duplicate' : 'provider.webhook.received'),
                'etype' => 'provider_webhook_events',
                'eid'   => null,
                'meta'  => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip'  => $request->ipAddress(),
                'env' => $env,
            ]);
        } catch (Throwable $e) {
            error_log('[NEXUS audit] ' . $e->getMessage());
        }
    }
}
