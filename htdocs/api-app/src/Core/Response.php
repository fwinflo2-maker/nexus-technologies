<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Réponses HTTP JSON au format uniforme :
 *
 *   Succès  : { "success": true,  "data": {...} }
 *   Erreur  : { "success": false, "error": "...", "code": "..." }
 *
 * En-tête : Content-Type: application/json; charset=utf-8
 */
final class Response
{
    /**
     * Mode test : lorsque `true`, `json()` lève une ResponseSent au lieu
     * d'appeler `exit`, ce qui permet à PHPUnit de piloter les contrôleurs
     * en direct sans que le process de test soit tué au premier envoi.
     *
     * Reste `false` en production : le comportement HTTP est inchangé.
     */
    private static bool $testMode = false;

    /** Active/désactive le mode test (appelé uniquement par la suite de tests). */
    public static function enableTestMode(bool $enabled = true): void
    {
        self::$testMode = $enabled;
    }

    /** Indique si le mode test est actif. */
    public static function isTestMode(): bool
    {
        return self::$testMode;
    }

    /** Envoie un payload JSON quelconque et termine le script. */
    public static function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . Correlation::id());

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        // Sécurité : ne jamais envoyer de JSON invalide vers le client.
        if ($json === false) {
            $json = json_encode([
                'success' => false,
                'error'   => 'Erreur interne du serveur',
                'code'    => '500',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // En test, on remonte la réponse au lieu d'écrire sur la sortie standard
        // et de tuer le process PHPUnit.
        if (self::$testMode) {
            throw new ResponseSent($status, $json);
        }

        echo $json;
        exit;
    }

    /** Réponse de succès au format uniforme { success, data }. */
    public static function success(mixed $data = null, int $status = 200): never
    {
        $payload = ['success' => true];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::json($payload, $status);
    }

    /** Réponse d'erreur au format uniforme { success, error, code }. */
    public static function error(string $message, int $status, ?string $code = null): never
    {
        self::json([
            'success' => false,
            'error'   => $message,
            'code'    => $code ?? (string) $status,
        ], $status);
    }

    // --- Raccourcis pour les codes d'erreur normalisés -------------------------

    /** 400 — Validation : la requête est mal formée. */
    public static function badRequest(string $message = 'Requête invalide'): never
    {
        self::error($message, 400, 'VALIDATION_ERROR');
    }

    /** 401 — L'authentification est requise ou a échoué. */
    public static function unauthorized(string $message = 'Authentification requise'): never
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    /** 403 — L'utilisateur est identifié mais n'a pas le droit d'agir. */
    public static function forbidden(string $message = 'Accès interdit'): never
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    /** 404 — Ressource ou route introuvable. */
    public static function notFound(string $message = 'Ressource introuvable'): never
    {
        self::error($message, 404, 'NOT_FOUND');
    }

    /** 409 — Conflit avec l'état actuel de la ressource. */
    public static function conflict(string $message = 'Conflit avec l’état actuel de la ressource'): never
    {
        self::error($message, 409, 'CONFLICT');
    }

    /** 429 — Limite de débit dépassée. */
    public static function tooManyRequests(string $message = 'Trop de requêtes, réessayez plus tard'): never
    {
        self::error($message, 429, 'RATE_LIMITED');
    }

    /** 500 — Erreur interne, message volontairement générique. */
    public static function serverError(string $message = 'Erreur interne du serveur'): never
    {
        self::error($message, 500, 'INTERNAL_ERROR');
    }
}
