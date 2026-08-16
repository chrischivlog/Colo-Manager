<?php

declare(strict_types=1);

namespace ColoManager\Http;

/** Eine registrierte Route inklusive Authentifizierungs- und Rollenanforderungen. */
final readonly class Route
{
    /**
     * @param callable(Request): Response $handler
     * @param list<string> $roles
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public mixed $handler,
        public bool $authenticated = false,
        public array $roles = [],
    ) {
    }

    /** @return array<string, string>|null */
    public function match(string $method, string $path): ?array
    {
        if ($method !== $this->method) {
            return null;
        }

        // Platzhalter wie {id} werden zu genau einem URL-Segment und anschließend
        // wieder als benannte Parameter auf den Request geschrieben.
        $parameterNames = [];
        $quoted = preg_quote($this->pattern, '#');
        $regex = preg_replace_callback('/\\\\\{([a-zA-Z][a-zA-Z0-9_]*)\\\\\}/', static function (array $matches) use (&$parameterNames): string {
            $parameterNames[] = $matches[1];
            return '([^/]+)';
        }, $quoted);

        if (!is_string($regex) || preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);
        return array_combine($parameterNames, array_map('urldecode', $matches)) ?: [];
    }
}
