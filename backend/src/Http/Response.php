<?php

declare(strict_types=1);

namespace ColoManager\Http;

/** JSON-Antwortobjekt, das Status, Nutzdaten und Header gemeinsam transportiert. */
final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public mixed $data = null,
        public array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, $data, $headers);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** Erzeugt eine Binärantwort, zum Beispiel für geschützte Ticketbilder. */
    public static function binary(string $content, string $contentType, string $filename): self
    {
        $safeFilename = str_replace(["\r", "\n", '"'], '', $filename);
        return new self(200, $content, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => sprintf('inline; filename="%s"', $safeFilename),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function send(): never
    {
        http_response_code($this->status);
        $contentType = $this->headers['Content-Type'] ?? 'application/json; charset=utf-8';
        header('Content-Type: ' . $contentType);
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }

        if ($this->status !== 204 && $this->data !== null) {
            echo str_starts_with($contentType, 'application/json')
                ? json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : (string) $this->data;
        }

        exit;
    }
}
