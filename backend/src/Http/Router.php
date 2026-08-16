<?php

declare(strict_types=1);

namespace ColoManager\Http;

/** Minimaler Router für die bewusst kleine REST-API ohne Framework-Abhängigkeit. */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     * @param list<string> $roles
     */
    public function add(string $method, string $pattern, callable $handler, bool $authenticated = false, array $roles = []): void
    {
        $this->routes[] = new Route(strtoupper($method), $pattern, $handler, $authenticated, $roles);
    }

    /** @return array{route: Route, params: array<string, string>} */
    public function resolve(Request $request): array
    {
        foreach ($this->routes as $route) {
            $params = $route->match($request->method, $request->path);
            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }

        throw new ApiException(404, 'Der angeforderte Endpunkt wurde nicht gefunden.', 'route_not_found');
    }
}
