<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Providers\ProviderConfig;

/**
 * ExecutionContext — contexte d'exécution formel d'une opération Nexus.
 *
 * CHAÎNE DE RÉSOLUTION
 * ────────────────────
 *   Request → Utilisateur authentifié → Contexte de compte → Provider
 *           → Environnement d'exécution → Credential → Adapter
 *
 * RÈGLE FONDAMENTALE
 * ──────────────────
 * L'environnement n'est JAMAIS déduit d'une credential disponible.
 *
 * Autrement dit, le système ne raisonne jamais ainsi :
 *
 *     « il existe une credential production pour ce provider,
 *       donc exécutons en production »
 *
 * C'est l'inverse : la POLITIQUE décide de l'environnement, puis la credential
 * de cet environnement est exigée. Si elle est absente, l'opération échoue —
 * elle ne bascule pas vers l'autre environnement. Sans cette inversion, la
 * simple présence d'une clé `sk_live_…` suffirait à faire basculer un test en
 * production.
 *
 * POLITIQUE DE RÉSOLUTION (ordre strict)
 * ──────────────────────────────────────
 *  1. APP_ENV=production      → PRODUCTION imposée, toute demande divergente
 *                               est REFUSÉE (jamais de sandbox en production).
 *  2. Demande explicite du client (en-tête `X-Nexus-Environment` ou champ
 *     `environment`) → validée par le serveur, refusée si non autorisée.
 *  3. Défaut serveur (`PROVIDERS_ENV`) → sandbox sauf configuration contraire.
 *
 * Le client peut DEMANDER, jamais DÉCIDER : toute demande est arbitrée par le
 * serveur, qui reste seul juge (§27).
 */
final class ExecutionContext
{
    public const HEADER = 'X-Nexus-Environment';

    /** Origine de la décision — tracée pour l'audit, jamais devinée. */
    public const SOURCE_SERVER_FORCED = 'server_forced_production';
    public const SOURCE_CLIENT        = 'client_request';
    public const SOURCE_SERVER_DEFAULT = 'server_default';

    private function __construct(
        public readonly int $actorUserId,
        public readonly int $subjectUserId,
        public readonly string $accountType,
        public readonly ExecutionEnvironment $environment,
        public readonly string $environmentSource,
    ) {
    }

    /**
     * Construit le contexte à partir d'une requête authentifiée.
     *
     * @param array<string,mixed> $user   utilisateur authentifié (AuthMiddleware)
     * @param int|null $subjectUserId     espace ciblé (business), défaut = acteur
     *
     * @throws HttpException 403 si l'environnement demandé n'est pas autorisé
     */
    public static function fromRequest(Request $request, array $user, ?int $subjectUserId = null): self
    {
        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId <= 0) {
            // Ne devrait pas arriver : AuthMiddleware garantit l'utilisateur.
            throw new HttpException(401, 'Authentification requise.', 'UNAUTHENTICATED');
        }

        $requested = $request->header(self::HEADER);
        if ($requested === null || trim($requested) === '') {
            $body = $request->body();
            $raw  = $body['environment'] ?? null;
            $requested = is_string($raw) && trim($raw) !== '' ? $raw : null;
        }

        [$environment, $source] = self::resolveEnvironment($requested);

        return new self(
            actorUserId:       $actorId,
            subjectUserId:     $subjectUserId ?? $actorId,
            accountType:       (string) ($user['account_type'] ?? 'personal'),
            environment:       $environment,
            environmentSource: $source,
        );
    }

    /**
     * Contexte explicite, hors requête HTTP (tâches planifiées, tests, CLI).
     *
     * L'environnement doit être fourni : aucune valeur n'est devinée.
     */
    public static function explicit(
        int $actorUserId,
        ExecutionEnvironment $environment,
        ?int $subjectUserId = null,
        string $accountType = 'personal'
    ): self {
        return new self(
            actorUserId:       $actorUserId,
            subjectUserId:     $subjectUserId ?? $actorUserId,
            accountType:       $accountType,
            environment:       $environment,
            environmentSource: self::SOURCE_SERVER_DEFAULT,
        );
    }

    /**
     * Arbitre l'environnement effectif.
     *
     * @return array{0: ExecutionEnvironment, 1: string}
     * @throws HttpException
     */
    private static function resolveEnvironment(?string $requested): array
    {
        // ── 1. Déploiement de production : la sandbox est INTERDITE ──
        // Autoriser un client à demander « sandbox » sur un déploiement de
        // production reviendrait à lui laisser choisir un mode dégradé.
        if (ProviderConfig::isProduction()) {
            if ($requested !== null) {
                $normalized = strtolower(trim($requested));
                if ($normalized !== ExecutionEnvironment::PRODUCTION->value) {
                    throw new HttpException(
                        403,
                        'Environnement « ' . $requested . ' » refusé : ce déploiement exécute exclusivement en production.',
                        'ENVIRONMENT_NOT_ALLOWED'
                    );
                }
            }

            return [ExecutionEnvironment::PRODUCTION, self::SOURCE_SERVER_FORCED];
        }

        // ── 2. Demande explicite du client, validée par le serveur ──
        if ($requested !== null) {
            try {
                $environment = ExecutionEnvironment::fromString($requested);
            } catch (\InvalidArgumentException) {
                // Valeur inconnue : refus explicite. Ne jamais retomber
                // silencieusement sur un défaut — le client croirait sa
                // demande honorée.
                throw new HttpException(
                    400,
                    'Environnement invalide : « ' . $requested . ' ». Attendu : sandbox ou production.',
                    'ENVIRONMENT_INVALID'
                );
            }

            return [$environment, self::SOURCE_CLIENT];
        }

        // ── 3. Défaut serveur ──
        return [
            ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment()),
            self::SOURCE_SERVER_DEFAULT,
        ];
    }

    /** Valeur canonique de l'environnement (`sandbox`|`production`). */
    public function environmentValue(): string
    {
        return $this->environment->value;
    }

    /** L'opération déplace-t-elle de l'argent réel ? */
    public function isRealMoney(): bool
    {
        return $this->environment->isRealMoney();
    }

    /**
     * Représentation destinée à l'audit et aux réponses API.
     *
     * Ne contient aucun secret : uniquement des identifiants et la décision
     * prise, avec son origine.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'actor_user_id'      => $this->actorUserId,
            'subject_user_id'    => $this->subjectUserId,
            'account_type'       => $this->accountType,
            'environment'        => $this->environment->value,
            'environment_source' => $this->environmentSource,
            'real_money'         => $this->isRealMoney(),
        ];
    }
}
