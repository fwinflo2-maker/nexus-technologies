<?php

declare(strict_types=1);

namespace Nexus\Tests\Fixtures;

use Nexus\Providers\AbstractProviderAdapter;

/**
 * Adaptateur SCRIPTÉ — fixture de test LÉGITIME (§15).
 *
 * Exerce le chemin nominal complet de la saga d'exécution (hold → provider →
 * capture) sans réseau : `createPayment()` répond comme un provider réel qui
 * accepte l'opération. Enregistré par les tests via
 * `ProviderRegistry::registerAdapter()`, JAMAIS par le runtime de production.
 *
 * La configuration, elle, reste réelle : les tests posent les variables
 * d'environnement `PROVIDER_STRIPE_*` (le mécanisme d'un vrai déploiement) ;
 * seule la réponse API est scriptée.
 */
final class ScriptedProviderAdapter extends AbstractProviderAdapter
{
    /** @var list<array<string, mixed>> Derniers appels reçus par createPayment(). */
    public static array $calls = [];

    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }

    protected function declaredMethods(): array
    {
        return ['mobile_money', 'bank'];
    }

    /**
     * Réponse scriptée d'une API provider qui accepte l'opération.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createPayment(array $params): array
    {
        self::$calls[] = $params;

        return [
            'status'    => 'succeeded',
            'id'        => 'pay_' . bin2hex(random_bytes(8)),
            'provider'  => $this->slug,
            'operation' => $params['operation_id'] ?? null,
        ];
    }

    /**
     * Fixture : la « connexion » réussit sans réseau (test de connectivité).
     *
     * @param array<string, string>|null $credentials
     * @return array<string, mixed>
     */
    public function testConnection(string $environment, ?array $credentials = null): array
    {
        return [
            'status'    => 'CONNECTION_SUCCESS',
            'message'   => 'Connexion scriptée (fixture de test).',
            'tested_at' => gmdate(DATE_ATOM),
        ];
    }
}
