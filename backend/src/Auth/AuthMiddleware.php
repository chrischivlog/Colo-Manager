<?php

declare(strict_types=1);

namespace ColoManager\Auth;

use ColoManager\Config;
use ColoManager\Http\ApiException;
use ColoManager\Http\Request;
use ColoManager\Repository\SessionRepository;

/** Liest Bearer-Tokens und setzt den AuthContext für nachfolgende Controller. */
final readonly class AuthMiddleware
{
    public function __construct(
        private JwtService $jwt,
        private SessionRepository $sessions,
        private Config $config,
    ) {
    }

    /** @param list<string> $roles */
    public function authenticate(Request $request, array $roles = []): AuthContext
    {
        $authorization = $request->header('authorization');
        if ($authorization === null || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            throw new ApiException(401, 'Für diesen Endpunkt ist ein Bearer-Token erforderlich.', 'authentication_required');
        }

        $context = $this->jwt->decode(trim($matches[1]));
        // Signatur und Ablaufzeit des JWT reichen nicht aus: Die zugehörige
        // MongoDB-Sitzung muss ebenfalls aktiv und innerhalb des Idle-Fensters
        // von fünf Minuten benutzt worden sein.
        $this->sessions->validateAndTouch($context->sessionId, $context->userId, $this->config->sessionIdleTtl);
        if ($roles !== [] && !in_array($context->role, $roles, true)) {
            throw new ApiException(403, 'Für diese Aktion fehlen die erforderlichen Rechte.', 'forbidden');
        }

        $request->setAttribute('auth', $context);
        return $context;
    }
}
