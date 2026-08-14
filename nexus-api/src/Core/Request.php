<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Représentation normalisée de la requête HTTP entrante.
 *
 * Le préfixe `/api` est retiré du chemin : les routes sont enregistrées
 * sans préfixe (ex. `/health` pour `GET /api/health`).
 */
final class Request
{
    /** Préfixe d'URL commun à toutes les routes publiques. */
    private const API_PREFIX = '/api';

    private string $method;
    private string $path;
    private array $query;
    private array $body;
    private array $headers;
    private array $params = [];
    private array $attributes = [];

    /**
     * @param array|null $body Corps pré-décodé. Réservé aux tests : en HTTP réel
     *                         le corps est toujours lu depuis `php://input`, qui
     *                         n'est pas rejouable depuis PHPUnit.
     */
    public function __construct(?array $body = null)
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->headers = $this->parseHeaders();
        $this->query   = $_GET ?? [];
        $this->body    = $body ?? $this->parseBody();
        $this->path    = $this->parsePath();
    }

    /** Méthode HTTP normalisée (GET, POST, PUT, PATCH, DELETE...). */
    public function method(): string
    {
        return $this->method;
    }

    /** Chemin normalisé, sans préfixe /api et sans chaîne de requête. */
    public function path(): string
    {
        return $this->path;
    }

    /** Paramètres de la chaîne de requête (?key=value). */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /** Corps de la requête décodé (JSON ou formulaire). */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * Adresse IP du client, pour la journalisation d'audit.
     *
     * Les en-têtes de proxy ne sont lus que si `TRUSTED_PROXY` est activé :
     * `X-Forwarded-For` est falsifiable par le client et ne doit jamais être
     * pris pour argent comptant en frontal direct. La première entrée de la
     * liste est l'IP d'origine, les suivantes étant les proxys traversés.
     */
    public function ipAddress(): ?string
    {
        $trustProxy = defined('TRUSTED_PROXY')
            ? (bool) constant('TRUSTED_PROXY')
            : filter_var(getenv('TRUSTED_PROXY') ?: 'false', FILTER_VALIDATE_BOOL);

        if ($trustProxy) {
            $forwarded = (string) $this->header('X-Forwarded-For', '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false
            ? $remote
            : null;
    }

    /** User-Agent du client, tronqué pour la journalisation d'audit. */
    public function userAgent(): ?string
    {
        $agent = $this->header('User-Agent', '');

        return $agent === '' ? null : mb_substr((string) $agent, 0, 255);
    }

    /** Valeur d'une clé du corps de la requête. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** En-tête HTTP (insensible à la casse). */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** Token Bearer issu de l'en-tête Authorization, ou null. */
    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }

    /** Paramètres d'URL extraits par le routeur (pattern /x/{id}). */
    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function params(): array
    {
        return $this->params;
    }

    /** Défini par le routeur lors de la résolution du pattern. */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** Attribut arbitraire attaché à la requête (ex. utilisateur authentifié). */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** Lit un attribut attaché à la requête. */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    private function parsePath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        // Retire le préfixe /api pour travailler sur des chemins simples.
        if ($path === self::API_PREFIX) {
            $path = '/';
        } elseif (str_starts_with($path, self::API_PREFIX . '/')) {
            $path = substr($path, strlen(self::API_PREFIX));
        }

        return $path;
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');

        if (str_contains((string) $this->header('Content-Type', ''), 'application/json')) {
            if ($raw === false || $raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // PHP remplit déjà $_POST pour POST (urlencoded ET multipart).
            if ($this->method === 'POST') {
                return $_POST ?: [];
            }

            // PUT/PATCH/DELETE : PHP ne remplit pas $_POST → corps brut.
            // (multipart pour ces méthodes non supporté : API en JSON.)
            $data = [];
            parse_str($raw ?? '', $data);
            return $data ?: [];
        }

        return [];
    }

    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
