<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Auth\JwtService;
use ColoManager\Http\ApiException;
use ColoManager\Config;
use ColoManager\Repository\SessionRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Repository\DirectoryConfigurationRepository;
use ColoManager\Security\DirectoryAuthenticator;
use ColoManager\Security\TotpService;
use ColoManager\Support\DocumentSerializer;

/**
 * Kapselt die Anmeldung und verhindert, dass Passwort-Hashes jemals an einen
 * Controller oder an die API-Antwort weitergegeben werden.
 */
final readonly class AuthService
{
    public function __construct(
        private UserRepository $users,
        private JwtService $jwt,
        private SessionRepository $sessions,
        private TotpService $totp,
        private Config $config,
        private DirectoryConfigurationRepository $directories,
        private DirectoryAuthenticator $directoryAuthenticator,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password, ?string $totpCode = null): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
        }

        $authSource = (string) ($user['authSource'] ?? 'local');
        if ($authSource === 'local') {
            if (!password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
                throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
            }
        } else {
            // Verzeichnisfehler werden beim öffentlichen Login absichtlich auf
            // dieselbe Antwort wie falsche Zugangsdaten reduziert. So lässt sich
            // nicht erkennen, welche E-Mail-Adressen externe Konten besitzen.
            try {
                $directory = $this->directories->findById((string) ($user['directoryId'] ?? ''));
                if (($directory['active'] ?? false) !== true) throw new \RuntimeException('Directory inactive');
                $identity = $this->directoryAuthenticator->authenticate(
                    $directory,
                    strtolower(trim($email)),
                    (string) ($user['directoryUsername'] ?? strstr($email, '@', true) ?: $email),
                    $password,
                );
                $user = $this->users->recordDirectoryLogin(
                    (string) $user['_id'],
                    isset($identity['dn']) ? (string) $identity['dn'] : null,
                    isset($identity['name']) ? (string) $identity['name'] : null,
                );
            } catch (\Throwable) {
                throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
            }
        }

        if (isset($user['twoFactor']['encryptedSecret'])) {
            if ($totpCode === null || trim($totpCode) === '') {
                throw new ApiException(401, 'Bitte geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein.', 'two_factor_required', [
                    'field' => 'totpCode',
                ]);
            }
            $secret = $this->totp->decrypt((string) $user['twoFactor']['encryptedSecret']);
            if (!$this->totp->verify($secret, $totpCode)) {
                throw new ApiException(401, 'Der Authenticator-Code ist ungültig oder abgelaufen.', 'invalid_two_factor_code', [
                    'field' => 'totpCode',
                ]);
            }
        }

        // Erst nach vollständig erfolgreicher Passwort- und 2FA-Prüfung wird
        // der Login-Zeitpunkt aktualisiert. Externe Konten wurden bereits beim
        // Directory-Bind aktualisiert und werden hier bewusst vereinheitlicht.
        $user = $this->users->recordLogin((string) $user['_id'], $authSource !== 'local');

        $sessionId = $this->sessions->create((string) $user['_id'], $this->config->jwtTtl);
        $token = $this->jwt->issue($user, $sessionId);

        return $token + [
            'idleTimeoutSeconds' => $this->config->sessionIdleTtl,
            'user' => $this->publicUser($user),
        ];
    }

    /** @return array<string, mixed> */
    public function currentUser(AuthContext $auth): array
    {
        return $this->publicUser($this->users->findById($auth->userId));
    }

    public function heartbeat(AuthContext $auth): array
    {
        return ['active' => true, 'idleTimeoutSeconds' => $this->config->sessionIdleTtl];
    }

    public function logout(AuthContext $auth): array
    {
        $this->sessions->revoke($auth->sessionId, $auth->userId);
        return ['loggedOut' => true];
    }

    /** @param array<string, mixed> $user */
    private function publicUser(array $user): array
    {
        $user['twoFactorEnabled'] = isset($user['twoFactor']['encryptedSecret']);
        $user['authSource'] ??= 'local';
        unset($user['passwordHash'], $user['passwordReset'], $user['twoFactor'], $user['twoFactorSetup'], $user['deletedAt']);

        return DocumentSerializer::serialize($user);
    }
}
