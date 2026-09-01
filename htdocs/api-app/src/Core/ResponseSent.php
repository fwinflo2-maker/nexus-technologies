<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Signale qu'une réponse HTTP a été émise, en mode test uniquement.
 *
 * En production, `Response::json()` termine le script avec `exit`. Ce
 * comportement est incompatible avec PHPUnit, qui perd le process entier au
 * premier appel d'un contrôleur. Lorsque `Response::enableTestMode()` est
 * actif, `json()` lève cette exception à la place : le test peut alors
 * inspecter le code HTTP et le corps réellement produits.
 *
 * Cette exception ne doit jamais être interceptée par le code applicatif :
 * elle n'est pas une erreur métier mais un marqueur de fin de réponse.
 */
final class ResponseSent extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
    ) {
        parent::__construct(
            sprintf('Réponse HTTP %d émise (mode test).', $statusCode),
            $statusCode
        );
    }

    /** Code HTTP effectivement envoyé. */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** Corps JSON brut effectivement envoyé. */
    public function body(): string
    {
        return $this->body;
    }

    /** Corps JSON décodé, ou null si le décodage échoue. */
    public function decoded(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
