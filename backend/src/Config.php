<?php

declare(strict_types=1);

namespace ColoManager;

use RuntimeException;

/** Zentrale, unveränderliche Laufzeitkonfiguration aus Umgebungsvariablen. */
final readonly class Config
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $appUrl,
        public string $frontendUrl,
        public string $mongoUri,
        public string $mongoDatabase,
        public string $jwtSecret,
        public int $jwtTtl,
        public int $sessionIdleTtl,
        public string $corsAllowedOrigin,
        public string $mailerDsn,
        public string $mailFromAddress,
        public string $mailFromName,
        public ?string $mailReplyTo,
    ) {
        if (strlen($jwtSecret) < 32) {
            throw new RuntimeException('JWT_SECRET must contain at least 32 characters.');
        }
        if (filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('MAIL_FROM_ADDRESS must be a valid email address.');
        }
        if ($mailReplyTo !== null && filter_var($mailReplyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('MAIL_REPLY_TO must be a valid email address.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            environment: self::env('APP_ENV', 'production'),
            debug: filter_var(self::env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            appUrl: rtrim(self::env('APP_URL', 'http://localhost:8080'), '/'),
            frontendUrl: rtrim(self::env('FRONTEND_URL', 'http://localhost:3000'), '/'),
            mongoUri: self::env('MONGODB_URI', 'mongodb://mongo:27017'),
            mongoDatabase: self::env('MONGODB_DATABASE', 'colo_manager'),
            jwtSecret: self::env('JWT_SECRET', 'local-development-secret-change-before-production'),
            jwtTtl: max(300, (int) self::env('JWT_TTL', '3600')),
            // Wie beim Online-Banking endet eine Sitzung nach fünf Minuten
            // ohne Aktivität. Der Wert bleibt konfigurierbar, wird aber nie
            // unter eine Minute abgesenkt.
            sessionIdleTtl: max(60, (int) self::env('SESSION_IDLE_TTL', '300')),
            corsAllowedOrigin: self::env('CORS_ALLOWED_ORIGIN', 'http://localhost:3000'),
            mailerDsn: self::env('MAILER_DSN', 'smtp://mailpit:1025'),
            mailFromAddress: self::env('MAIL_FROM_ADDRESS', 'no-reply@colomanager.local'),
            mailFromName: self::env('MAIL_FROM_NAME', 'COLO MANAGER'),
            mailReplyTo: self::nullableEnv('MAIL_REPLY_TO'),
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function nullableEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
