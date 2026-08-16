<?php

declare(strict_types=1);

namespace ColoManager\Auth;

use ColoManager\Config;
use ColoManager\Http\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/** Erstellt kurzlebige HS256-Tokens und prüft Signatur sowie zentrale Claims. */
final readonly class JwtService
{
    public function __construct(private Config $config)
    {
    }

    /** @param array<string, mixed> $user */
    public function issue(array $user, string $sessionId): array
    {
        $now = time();
        $expiresAt = $now + $this->config->jwtTtl;
        $payload = [
            'iss' => $this->config->appUrl,
            'aud' => 'colo-manager-api',
            'sub' => (string) $user['_id'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'email' => $user['email'],
            'role' => $user['role'],
            'customerId' => isset($user['customerId']) ? (string) $user['customerId'] : null,
            // Die zufällige Sitzungs-ID verknüpft das signierte Token mit der
            // serverseitig widerrufbaren Inaktivitätssitzung in MongoDB.
            'sid' => $sessionId,
        ];

        return [
            'accessToken' => JWT::encode($payload, $this->config->jwtSecret, 'HS256'),
            'tokenType' => 'Bearer',
            'expiresIn' => $this->config->jwtTtl,
            'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
        ];
    }

    public function decode(string $token): AuthContext
    {
        try {
            $payload = JWT::decode($token, new Key($this->config->jwtSecret, 'HS256'));
        } catch (Throwable) {
            throw new ApiException(401, 'Das Zugriffstoken ist ungültig oder abgelaufen.', 'invalid_token');
        }

        if (($payload->iss ?? null) !== $this->config->appUrl || ($payload->aud ?? null) !== 'colo-manager-api') {
            throw new ApiException(401, 'Das Zugriffstoken ist für diese Anwendung nicht gültig.', 'invalid_token_claims');
        }

        return new AuthContext(
            userId: (string) ($payload->sub ?? ''),
            email: (string) ($payload->email ?? ''),
            role: (string) ($payload->role ?? ''),
            customerId: isset($payload->customerId) ? (string) $payload->customerId : null,
            sessionId: (string) ($payload->sid ?? ''),
        );
    }
}
