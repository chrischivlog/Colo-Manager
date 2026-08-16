<?php

declare(strict_types=1);

namespace ColoManager\Http;

use RuntimeException;

/** Erwartbarer API-Fehler mit HTTP-Status und maschinenlesbarem Fehlercode. */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
