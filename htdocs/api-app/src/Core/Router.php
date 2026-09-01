<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Routeur minimal : association méthode HTTP + pattern de chemin vers un handler.
 *
 * Patterns supportés : `/users/{id}` (paramètres nommés).
 * Les chemins sont exprimés sans préfixe `/api`.
 */
final class Router
{
    /** @var array<string, list<array{regex: string, handler: callable}>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[strtoupper($method)][] = [
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Résout la requête et exécute le handler correspondant.
     * Répond 404 si la route n'existe pas, 405 si la méthode n'est pas autorisée.
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        $route = $this->match($method, $path);
        if ($route !== null) {
            $request->setParams($route['params']);
            call_user_func($route['handler'], $request);
            return;
        }

        // Le chemin existe mais pas pour cette méthode.
        if ($this->pathExists($path)) {
            Response::error('Méthode non autorisée pour cette route', 405, 'METHOD_NOT_ALLOWED');
        }

        Response::notFound('Route introuvable');
    }

    /** Convertit un pattern (/users/{id}) en expression régulière. */
    private function compile(string $pattern): string
    {
        $regex = preg_quote($pattern, '#');
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $regex
        );

        return '#^' . $regex . '$#';
    }

    /** @return array{handler: callable, params: array<string, string>}|null */
    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                return [
                    'handler' => $route['handler'],
                    'params'  => array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
                ];
            }
        }

        return null;
    }

    private function pathExists(string $path): bool
    {
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
