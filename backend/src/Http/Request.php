<?php

declare(strict_types=1);

namespace ColoManager\Http;

/** Frameworkunabhängige Repräsentation einer eingehenden HTTP-Anfrage. */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, list<array{name: string, type: string, tmp_name: string, error: int, size: int}>> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];

        foreach (getallheaders() ?: [] as $name => $value) {
            $headers[strtolower($name)] = (string) $value;
        }

        $contentType = strtolower($headers['content-type'] ?? '');
        $body = str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')
            ? $_POST
            : [];
        $raw = file_get_contents('php://input');
        if (str_contains($contentType, 'application/json') && is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            } catch (\JsonException) {
                throw new ApiException(400, 'Der Request enthält kein gültiges JSON.', 'invalid_json');
            }
        }

        return new self($method, '/' . ltrim($path, '/'), $headers, $_GET, $body, self::normalizeFiles($_FILES));
    }

    /**
     * Vereinheitlicht die verschachtelte PHP-Struktur von Einzel- und
     * Mehrfachuploads. Controller erhalten dadurch pro Feld immer eine Liste.
     *
     * @param array<string, mixed> $files
     * @return array<string, list<array{name: string, type: string, tmp_name: string, error: int, size: int}>>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }

            $names = is_array($file['name']) ? $file['name'] : [$file['name']];
            foreach (array_keys($names) as $index) {
                $normalized[$field][] = [
                    'name' => (string) ($names[$index] ?? ''),
                    'type' => (string) (is_array($file['type'] ?? null) ? ($file['type'][$index] ?? '') : ($file['type'] ?? '')),
                    'tmp_name' => (string) (is_array($file['tmp_name'] ?? null) ? ($file['tmp_name'][$index] ?? '') : ($file['tmp_name'] ?? '')),
                    'error' => (int) (is_array($file['error'] ?? null) ? ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($file['error'] ?? UPLOAD_ERR_NO_FILE)),
                    'size' => (int) (is_array($file['size'] ?? null) ? ($file['size'][$index] ?? 0) : ($file['size'] ?? 0)),
                ];
            }
        }

        return $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $name): string
    {
        return $this->routeParams[$name] ?? '';
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
