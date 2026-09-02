<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * CredentialDefinition — description d'UN champ de credential provider (§5, §6).
 *
 * RÈGLE FONDAMENTALE (§6) :
 * ─────────────────────────
 * Une credential est **backend-only par défaut**. Elle n'est exposable au
 * frontend QUE si la documentation officielle du provider confirme
 * explicitement qu'elle est destinée à être publiée côté client.
 *
 * Ne JAMAIS déduire qu'une clé est publique parce que son nom contient
 * « public ». Contre-exemple réel : une « clé publique » de signature
 * (`public_key`) peut servir à vérifier les signatures de requêtes
 * financières côté provider — c'est une clé de configuration serveur,
 * jamais un secret à publier dans un navigateur. À l'inverse, la
 * `publishable_key` de Stripe est
 * explicitement documentée comme « safe to expose ».
 *
 * Chaque définition porte donc une justification (`justification`) qui doit
 * référencer la documentation officielle du provider.
 */
final class CredentialDefinition
{
    /** Secret : ne sort jamais du backend, redacté partout. */
    public const SENSITIVITY_SECRET = 'secret';

    /** Identifiant non secret (ex. account_id) : backend-only par prudence. */
    public const SENSITIVITY_IDENTIFIER = 'identifier';

    /** Public : documenté par le provider comme exposable côté client. */
    public const SENSITIVITY_PUBLIC = 'public';

    /** Usage : authentification API. */
    public const USAGE_API_AUTH = 'api_auth';

    /** Usage : vérification de signature de webhook. */
    public const USAGE_WEBHOOK = 'webhook';

    /** Usage : signature de requêtes sortantes. */
    public const USAGE_SIGNING = 'signing';

    /** Usage : identification de compte/profil. */
    public const USAGE_ACCOUNT = 'account';

    /** Usage : initialisation d'un SDK côté client. */
    public const USAGE_CLIENT_SDK = 'client_sdk';

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required,
        public readonly string $sensitivity,
        public readonly bool $frontendExposable,
        public readonly string $usage,
        public readonly string $justification,
        public readonly string $placeholder = '',
    ) {
    }

    /**
     * Fabrique une credential SECRÈTE (cas par défaut, backend-only).
     */
    public static function secret(
        string $name,
        string $label,
        bool $required,
        string $usage,
        string $justification,
        string $placeholder = ''
    ): self {
        return new self(
            name: $name,
            label: $label,
            required: $required,
            sensitivity: self::SENSITIVITY_SECRET,
            frontendExposable: false,
            usage: $usage,
            justification: $justification,
            placeholder: $placeholder,
        );
    }

    /**
     * Fabrique un IDENTIFIANT non secret mais NON exposable au frontend.
     * (identifiants de compte, profils, marchands…)
     */
    public static function identifier(
        string $name,
        string $label,
        bool $required,
        string $justification,
        string $placeholder = ''
    ): self {
        return new self(
            name: $name,
            label: $label,
            required: $required,
            sensitivity: self::SENSITIVITY_IDENTIFIER,
            frontendExposable: false,
            usage: self::USAGE_ACCOUNT,
            justification: $justification,
            placeholder: $placeholder,
        );
    }

    /**
     * Fabrique une credential PUBLIQUE exposable au frontend.
     *
     * À n'utiliser QUE si la documentation officielle du provider l'affirme
     * explicitement. La justification est OBLIGATOIRE et doit citer la source.
     */
    public static function publicKey(
        string $name,
        string $label,
        bool $required,
        string $justification,
        string $placeholder = ''
    ): self {
        return new self(
            name: $name,
            label: $label,
            required: $required,
            sensitivity: self::SENSITIVITY_PUBLIC,
            frontendExposable: true,
            usage: self::USAGE_CLIENT_SDK,
            justification: $justification,
            placeholder: $placeholder,
        );
    }

    /** Doit-elle être masquée dans toute sortie (logs, API, audit) ? */
    public function mustRedact(): bool
    {
        return !$this->frontendExposable;
    }

    /** Représentation publique — ne contient JAMAIS de valeur. */
    public function toArray(): array
    {
        return [
            'key'                => $this->name,
            'label'              => $this->label,
            'required'           => $this->required,
            'sensitivity'        => $this->sensitivity,
            'frontend_exposable' => $this->frontendExposable,
            'usage'              => $this->usage,
            'placeholder'        => $this->placeholder,
            // Le type d'input est déduit : rien de secret ne doit être en clair.
            'type'               => $this->sensitivity === self::SENSITIVITY_SECRET ? 'password' : 'text',
        ];
    }
}
