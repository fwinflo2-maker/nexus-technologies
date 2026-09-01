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
        public readonly AccountContext $account,
        public readonly string $requestId,
        public readonly ?string $provider = null,
        public readonly ?string $operation = null,
    ) {
    }

    /**
     * Décline le contexte pour un provider et une opération donnés.
     *
     * Retourne une NOUVELLE instance : l'environnement déjà arbitré est
     * recopié tel quel, jamais recalculé ni ré-arbitré. Le contexte se
     * précise au fil de la traversée, il ne se renégocie pas.
     */
    public function forOperation(string $provider, string $operation): self
    {
        return new self(
            actorUserId:       $this->actorUserId,
            subjectUserId:     $this->subjectUserId,
            accountType:       $this->accountType,
            environment:       $this->environment,
            environmentSource: $this->environmentSource,
            account:           $this->account,
            requestId:         $this->requestId,
            provider:          $provider,
            operation:         $operation,
        );
    }

    /** Identifiant de corrélation, stable pour toute la requête. */
    private static function newRequestId(): string
    {
        return bin2hex(random_bytes(8));
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

        // Tous les canaux d'expression d'une demande d'environnement sont lus
        // et arbitrés par la MÊME policy. Ignorer un canal ne le neutralise
        // pas : cela crée un chemin où l'intention du client diverge
        // silencieusement de l'exécution réelle — et, si ce canal venait à
        // être honoré ailleurs, une porte dérobée.
        $requested = null;
        foreach (self::requestedEnvironmentCandidates($request) as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $requested = $candidate;
                break;
            }
        }

        [$environment, $source] = self::resolveEnvironment($requested);

        $account = AccountContext::fromUser($user, $subjectUserId);

        // ── Autorisation ────────────────────────────────────────────────
        // L'environnement est RÉSOLU ci-dessus ; il est AUTORISÉ ici. Le
        // refus est terminal : un refus en production ne se replie jamais
        // sur la sandbox (et réciproquement). Un repli silencieux ferait
        // d'un « non » un « oui ailleurs », ce qui n'est pas une décision
        // de sécurité mais son contournement.
        self::authorize($account, $environment);

        $context = new self(
            actorUserId:       $actorId,
            subjectUserId:     $subjectUserId ?? $actorId,
            accountType:       (string) ($user['account_type'] ?? 'personal'),
            environment:       $environment,
            environmentSource: $source,
            account:           $account,
            requestId:         self::newRequestId(),
        );

        ExecutionAudit::recordGranted($context);

        return $context;
    }

    /**
     * Canaux par lesquels un client peut exprimer un environnement.
     *
     * Ordre de priorité : en-tête, corps, query. L'ordre départage une
     * requête cohérente ; il ne crée aucun privilège, chaque canal étant
     * ensuite soumis au même arbitrage puis à la même policy.
     *
     * @return list<mixed>
     */
    private static function requestedEnvironmentCandidates(Request $request): array
    {
        $body = $request->body();

        return [
            $request->header(self::HEADER),
            $body['environment'] ?? null,
            $request->query('environment'),
        ];
    }

    /**
     * Applique la policy d'autorisation. Point de passage UNIQUE.
     *
     * @throws HttpException 403 ENVIRONMENT_NOT_ALLOWED
     */
    private static function authorize(AccountContext $account, ExecutionEnvironment $environment): void
    {
        if (ProductionAuthorizationPolicy::isAllowed($account, $environment)) {
            return;
        }

        // §18 — le journal interne conserve le détail ; la réponse au client
        // reste générique et n'apprend rien sur la configuration.
        ExecutionAudit::recordDenied(
            'ENVIRONMENT_NOT_ALLOWED',
            $account->actorId,
            $environment->value,
            ['account_id' => $account->accountId, 'account_type' => $account->accountType]
        );

        throw new HttpException(
            403,
            ProductionAuthorizationPolicy::denialReason($account, $environment),
            'ENVIRONMENT_NOT_ALLOWED'
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
            account:           AccountContext::of(
                accountId:   $subjectUserId ?? $actorUserId,
                accountType: $accountType,
                actorId:     $actorUserId,
            ),
            requestId:         self::newRequestId(),
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
                    ExecutionAudit::recordDenied(
                        'ENVIRONMENT_NOT_ALLOWED',
                        null,
                        null,
                        ['requested_raw' => substr($requested, 0, 100), 'reason' => 'production_deployment']
                    );

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
                ExecutionAudit::recordDenied(
                    'ENVIRONMENT_INVALID',
                    null,
                    null,
                    ['requested_raw' => substr($requested, 0, 100)]
                );

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
            'account_id'         => $this->account->accountId,
            'provider'           => $this->provider,
            'operation'          => $this->operation,
            'environment'        => $this->environment->value,
            'environment_source' => $this->environmentSource,
            'real_money'         => $this->isRealMoney(),
            'request_id'         => $this->requestId,
        ];
    }
}
