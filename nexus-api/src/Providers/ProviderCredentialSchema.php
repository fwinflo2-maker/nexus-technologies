<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ProviderCredentialSchema — schéma des credentials attendues par provider (§5, §6, §7).
 *
 * SOURCE : documentation officielle de chaque provider, consultée et citée
 * dans la `justification` de chaque champ. Aucune credential n'est inventée.
 *
 * Lorsqu'une information n'a PAS pu être confirmée par la documentation
 * officielle, le provider est marqué UNKNOWN (voir self::UNKNOWN) plutôt que
 * de faire une supposition (§7, §37).
 *
 * RÈGLE §6 : `frontend_exposable = true` est réservé aux credentials dont la
 * documentation officielle affirme explicitement qu'elles sont destinées au
 * client. Tout le reste est backend-only.
 */
final class ProviderCredentialSchema
{
    /**
     * Providers dont le schéma de credentials n'a PAS été confirmé par la
     * documentation officielle. Ils restent utilisables via le catalogue
     * générique, mais leur schéma n'est pas déclaré comme vérifié.
     */
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }

    /**
     * Schéma vérifié d'un provider.
     *
     * @return list<CredentialDefinition>|null null = schéma non vérifié (UNKNOWN)
     */
    public static function for(string $slug): ?array
    {
        return match ($slug) {
            'stripe'  => self::stripe(),
            'pawapay' => self::pawapay(),
            'wise'    => self::wise(),
            'nium'    => self::nium(),
            default   => null,
        };
    }

    /** Le schéma de ce provider a-t-il été vérifié sur sa doc officielle ? */
    public static function isVerified(string $slug): bool
    {
        return self::for($slug) !== null;
    }

    /**
     * Stripe — https://docs.stripe.com/keys
     *
     * La documentation Stripe est explicite : « Only publishable keys are safe
     * to expose outside your application's backend. You're responsible for
     * protecting other Stripe API keys, including restricted API keys. »
     */
    private static function stripe(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key (sk_)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.stripe.com/keys : « Secret API key sk_... — Safe to expose: No ». '
                    . 'Permissions illimitées sur toutes les API Stripe : backend uniquement.',
                placeholder: 'sk_test_...'
            ),
            CredentialDefinition::publicKey(
                name: 'publishable_key',
                label: 'Publishable Key (pk_)',
                required: false,
                justification: 'docs.stripe.com/keys : « Publishable API key — Safe to expose: Yes. '
                    . 'API key that you can put in front-end code or applications you distribute. » '
                    . 'SEULE credential Stripe explicitement documentée comme exposable au client.',
                placeholder: 'pk_test_...'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Signing Secret',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'docs.stripe.com/keys : les webhook signing secrets ne sont pas des clés '
                    . 'API mais servent à authentifier les webhooks. Jamais exposable.',
                placeholder: 'whsec_...'
            ),
            CredentialDefinition::identifier(
                name: 'account_id',
                label: 'Account ID',
                required: false,
                justification: 'Identifiant de compte Stripe Connect (acct_...). Non secret, mais '
                    . 'backend-only par défaut (§6) : aucune raison documentée de l\'exposer.',
                placeholder: 'acct_...'
            ),
        ];
    }

    /**
     * pawaPay — https://docs.pawapay.io/using_the_api
     *
     * ATTENTION — piège volontairement traité ici (§6) : pawaPay utilise une
     * « clé publique » pour la signature des requêtes financières. Malgré son
     * nom, elle n'est PAS destinée au navigateur : elle est déposée dans le
     * dashboard pawaPay et sert à valider les signatures côté pawaPay.
     * Elle reste donc backend-only.
     */
    private static function pawapay(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_token',
                label: 'API Token (Bearer)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.pawapay.io/using_the_api : « The pawaPay Merchant API uses a bearer '
                    . 'token for authentication ». Le token sandbox ne fonctionne QUE en sandbox et '
                    . 'un token distinct doit être généré en production.',
                placeholder: 'Token pawaPay'
            ),
            CredentialDefinition::secret(
                name: 'private_key',
                label: 'Clé privée de signature',
                required: false,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.pawapay.io : signature optionnelle des requêtes financières '
                    . '(deposit/payout/refund). Clé PRIVÉE : backend uniquement.',
                placeholder: '-----BEGIN EC PRIVATE KEY-----'
            ),
            CredentialDefinition::identifier(
                name: 'api_key_id',
                label: 'Identifiant de clé (keyid)',
                required: false,
                justification: 'Identifiant de la clé de signature déclarée dans le dashboard pawaPay. '
                    . 'Non secret mais backend-only (§6).',
                placeholder: 'CUSTOMER_TEST_KEY'
            ),
        ];
    }

    /**
     * Wise Platform — https://docs.wise.com/guides/developer/auth-and-security
     *
     * La doc Wise est explicite : « Never expose client credentials or tokens
     * in client-side code, logs, or URLs » et « Use separate credentials for
     * sandbox and production ».
     */
    private static function wise(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID (OAuth 2.0)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.wise.com/guides/developer/auth-and-security : « Never expose client '
                    . 'credentials or tokens in client-side code, logs, or URLs ». Le client_id fait '
                    . 'partie des « client credentials » explicitement visées : backend-only.',
                placeholder: 'Client ID Wise'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.wise.com : « Rotate your client secret periodically ». Secret strict.',
                placeholder: 'Client Secret Wise'
            ),
            CredentialDefinition::identifier(
                name: 'profile_id',
                label: 'Profile ID',
                required: false,
                justification: 'Identifiant de profil Wise. Non secret, backend-only par défaut (§6).',
                placeholder: 'Profile ID'
            ),
        ];
    }

    /**
     * Nium — https://docs.nium.com/apis/reference/nium-environments
     *
     * Environnements officiels :
     *   sandbox    → https://gateway.nium.com/api
     *   production → https://api.spend.nium.com/api
     */
    private static function nium(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.nium.com : « you will be provided with a client_id and client_secret '
                    . 'to be used for API authentication ». Credentials serveur : backend-only.',
                placeholder: 'client_id Nium'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.nium.com : credential d\'authentification serveur. Jamais exposable.',
                placeholder: 'client_secret Nium'
            ),
        ];
    }

    /**
     * Champs exposables au frontend pour un provider (§6).
     *
     * @return list<string>
     */
    public static function frontendExposableFields(string $slug): array
    {
        $defs = self::for($slug);
        if ($defs === null) {
            // Schéma non vérifié → on n'expose RIEN (principe de précaution).
            return [];
        }
        $out = [];
        foreach ($defs as $def) {
            if ($def->frontendExposable) {
                $out[] = $def->name;
            }
        }
        return $out;
    }

    /**
     * Un champ donné est-il exposable au frontend ?
     *
     * Retourne false par défaut pour tout champ inconnu ou tout provider
     * dont le schéma n'est pas vérifié (§6 : backend-only par défaut).
     */
    public static function isFrontendExposable(string $slug, string $field): bool
    {
        return in_array($field, self::frontendExposableFields($slug), true);
    }

    /** Description publique du schéma (sans aucune valeur). */
    public static function describe(string $slug): array
    {
        $defs = self::for($slug);
        if ($defs === null) {
            return [
                'verified'    => false,
                'source'      => self::UNKNOWN,
                'credentials' => [],
            ];
        }
        return [
            'verified'    => true,
            'source'      => 'official_documentation',
            'credentials' => array_map(static fn (CredentialDefinition $d) => $d->toArray(), $defs),
        ];
    }
}
