<?php

declare(strict_types=1);

namespace Nexus\Execution;

/**
 * AccountContext — identité du compte pour lequel une opération est décidée.
 *
 * Abstraction volontairement neutre : elle décrit QUI agit et POUR QUI, sans
 * présumer du type de compte. Elle permet de distinguer aujourd'hui `personal`
 * et `business`, et demain `connect` (compte opéré pour le compte d'un tiers),
 * SANS multiplier les implémentations : c'est la policy qui décide, pas une
 * sous-classe par type.
 *
 * `actorId` et `accountId` sont distincts : un membre d'équipe (acteur) peut
 * agir sur l'espace d'une entreprise (compte). Confondre les deux rendrait
 * impossible l'audit « qui a fait quoi, sur quel espace ».
 */
final class AccountContext
{
    /**
     * @param list<string> $permissions Permissions effectives de l'acteur sur
     *        ce compte (rôle projeté). Vide = aucune permission particulière.
     */
    private function __construct(
        public readonly int $accountId,
        public readonly string $accountType,
        public readonly int $actorId,
        public readonly array $permissions,
    ) {
    }

    /**
     * Construit le contexte depuis l'utilisateur authentifié.
     *
     * @param array<string,mixed> $user      utilisateur (AuthMiddleware)
     * @param int|null            $accountId espace ciblé ; défaut = l'acteur
     * @param list<string>        $permissions
     */
    public static function fromUser(array $user, ?int $accountId = null, array $permissions = []): self
    {
        $actorId = (int) ($user['id'] ?? 0);

        return new self(
            accountId:   $accountId ?? $actorId,
            accountType: (string) ($user['account_type'] ?? 'personal'),
            actorId:     $actorId,
            permissions: $permissions,
        );
    }

    /** Contexte explicite (tâches planifiées, tests, CLI). */
    public static function of(
        int $accountId,
        string $accountType = 'personal',
        ?int $actorId = null,
        array $permissions = []
    ): self {
        return new self(
            accountId:   $accountId,
            accountType: $accountType,
            actorId:     $actorId ?? $accountId,
            permissions: $permissions,
        );
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** L'acteur agit-il sur son propre espace ? */
    public function isSelfService(): bool
    {
        return $this->actorId === $this->accountId;
    }

    /** @return array<string,mixed> Représentation d'audit (aucun secret). */
    public function toArray(): array
    {
        return [
            'account_id'   => $this->accountId,
            'account_type' => $this->accountType,
            'actor_id'     => $this->actorId,
        ];
    }
}
