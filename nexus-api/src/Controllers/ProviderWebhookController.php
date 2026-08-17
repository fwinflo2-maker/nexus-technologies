<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Providers\WebhookVerifier;
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

    public static function handle(Request $request): void
    {
        $slug = (string) $request->param('slug', '');
        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        // 1) Secret de vérification : credentials plateforme d'abord, variable
        //    d'environnement ensuite. Sans secret, AUCUNE vérification n'est
        //    possible → refus explicite, jamais d'acceptation aveugle.
        $env    = ProviderConfig::activeEnvironment($slug);
        $pdo    = Database::getConnection();
        $creds  = ProviderCredentialService::resolvePlatform($pdo, $slug, $env) ?? [];
        $secret = $creds['webhook_secret'] ?? ProviderConfig::credential($slug, 'WEBHOOK_SECRET', $env);
        if ($secret === null || $secret === '') {
            Response::error(
                'Aucun secret de webhook configuré pour ce provider : les webhooks entrants sont refusés.',
                501,
                'WEBHOOK_NOT_CONFIGURED'
            );
        }

        // 2) Signature AVANT toute interprétation du contenu. Aucun détail
        //    n'est renvoyé en cas d'échec (ne pas aider un attaquant).
        $raw       = $request->rawBody();
        $signature = (string) ($request->header(self::SIGNATURE_HEADER) ?? '');
        if (!WebhookVerifier::verify($raw, $signature, $secret)) {
            Response::error('Signature de webhook invalide.', 401, 'INVALID_WEBHOOK_SIGNATURE');
        }

        // 3) Enveloppe minimale : identifiant d'événement obligatoire.
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::error('Payload de webhook invalide.', 400, 'INVALID_WEBHOOK_PAYLOAD');
        }
        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
        if ($eventId === '') {
            Response::error('Identifiant d\'événement manquant.', 400, 'INVALID_WEBHOOK_PAYLOAD');
        }

        // L'événement peut déclarer son environnement : s'il est déclaré et
        // différent de l'environnement actif du provider, il est rejeté.
        $declaredEnv = ProviderCredentialService::normalizeEnvironment((string) ($payload['environment'] ?? ''));
        if ($declaredEnv !== null && $declaredEnv !== $env) {
            Response::error('Environnement de webhook incohérent.', 409, 'WEBHOOK_ENVIRONMENT_MISMATCH');
        }

        $eventType = substr((string) ($payload['event_type'] ?? $payload['type'] ?? 'unknown'), 0, 100);

        // 4) Persistance idempotente : la base est la clé d'idempotence
        //    (provider, environment, event_id). Un rejeu est acquitté 200.
        $duplicate = self::persist($pdo, $slug, $env, $eventId, $eventType);

        // 5) Audit : identité de l'événement uniquement, jamais le payload,
        //    jamais un secret.
        self::audit($pdo, $slug, $env, $eventId, $eventType, $duplicate, $request);

        Response::success([
            'received'  => true,
            'duplicate' => $duplicate,
            'event_id'  => $eventId,
            'status'    => 'received',
        ]);
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

    private static function audit(PDO $pdo, string $slug, string $env, string $eventId, string $eventType, bool $duplicate, Request $request): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address, environment, created_at)
                 VALUES (NULL, :act, :etype, :eid, :meta, :ip, :env, NOW())'
            );
            $stmt->execute([
                'act'   => $duplicate ? 'provider.webhook.duplicate' : 'provider.webhook.received',
                'etype' => 'provider_webhook_events',
                'eid'   => null,
                'meta'  => json_encode([
                    'provider'   => $slug,
                    'event_id'   => $eventId,
                    'event_type' => $eventType,
                ], JSON_UNESCAPED_UNICODE),
                'ip'  => $request->ipAddress(),
                'env' => $env,
            ]);
        } catch (Throwable $e) {
            error_log('[NEXUS audit] ' . $e->getMessage());
        }
    }
}
