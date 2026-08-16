<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Auth\JwtService;
use ColoManager\Config;
use ColoManager\Http\ApiException;
use ColoManager\Repository\SessionRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Security\TotpService;
use ColoManager\Support\DocumentSerializer;
use DateInterval;
use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;

/** Sichere Selbstverwaltung des aktuell angemeldeten Benutzerkontos. */
final readonly class AccountService
{
    public function __construct(
        private UserRepository $users,
        private SessionRepository $sessions,
        private TotpService $totp,
        private JwtService $jwt,
        private Config $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth): array
    {
        return $this->publicAccount($this->users->findById($auth->userId));
    }

    /** @param array<string, mixed> $payload */
    public function changeEmail(AuthContext $auth, array $payload): array
    {
        $this->requireLocalAccount($auth);
        $user = $this->confirmedUser($auth, $payload);
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new ApiException(422, 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'validation_failed', ['field' => 'email']);
        }
        $existing = $this->users->findAnyByEmail($email);
        if ($existing !== null && (string) $existing['_id'] !== $auth->userId) {
            throw new ApiException(409, 'Diese E-Mail-Adresse wird bereits verwendet.', 'email_already_exists', ['field' => 'email']);
        }

        $updated = $this->users->updateEmail($auth->userId, $email);
        $account = $this->publicAccount($updated);
        // Der E-Mail-Claim des aktuellen Tokens wird sofort erneuert, damit
        // nachfolgende Ticket- und Vertragsaktionen bereits die neue Adresse
        // als Bearbeiter- beziehungsweise Absenderadresse protokollieren.
        $account['authentication'] = $this->jwt->issue($updated, $auth->sessionId) + [
            'idleTimeoutSeconds' => $this->config->sessionIdleTtl,
        ];

        return $account;
    }

    /** @param array<string, mixed> $payload */
    public function changePassword(AuthContext $auth, array $payload): array
    {
        $this->requireLocalAccount($auth);
        $this->confirmedUser($auth, $payload);
        $password = (string) ($payload['newPassword'] ?? '');
        $confirmation = (string) ($payload['newPasswordConfirmation'] ?? '');
        if ($password !== $confirmation) {
            throw new ApiException(422, 'Die Passwortbestätigung stimmt nicht überein.', 'validation_failed', ['field' => 'newPasswordConfirmation']);
        }
        $this->validatePassword($password);
        $this->users->updatePassword($auth->userId, password_hash($password, PASSWORD_ARGON2ID));
        $this->sessions->revokeOtherSessions($auth->userId, $auth->sessionId);

        return ['passwordChanged' => true, 'otherSessionsRevoked' => true];
    }

    /** @param array<string, mixed> $payload */
    public function startTwoFactorSetup(AuthContext $auth, array $payload): array
    {
        $this->requireLocalAccount($auth);
        $user = $this->confirmedUser($auth, $payload, false);
        if (isset($user['twoFactor']['encryptedSecret'])) {
            throw new ApiException(409, 'Die Zwei-Faktor-Authentifizierung ist bereits aktiv.', 'two_factor_already_enabled');
        }
        $secret = $this->totp->generateSecret();
        $expiresAt = new DateTimeImmutable('+10 minutes');
        $this->users->setTwoFactorSetup($auth->userId, $this->totp->encrypt($secret), new UTCDateTime($expiresAt));

        return [
            'secret' => $secret,
            'provisioningUri' => $this->totp->provisioningUri((string) $user['email'], $secret),
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function confirmTwoFactor(AuthContext $auth, array $payload): array
    {
        $user = $this->users->findById($auth->userId);
        $setup = $user['twoFactorSetup'] ?? null;
        if (!is_array($setup) || !isset($setup['encryptedSecret'], $setup['expiresAt'])
            || !$setup['expiresAt'] instanceof UTCDateTime
            || $setup['expiresAt']->toDateTime()->getTimestamp() <= time()) {
            throw new ApiException(409, 'Die Einrichtung ist abgelaufen. Bitte starten Sie sie erneut.', 'two_factor_setup_expired');
        }
        $secret = $this->totp->decrypt((string) $setup['encryptedSecret']);
        if (!$this->totp->verify($secret, (string) ($payload['code'] ?? ''))) {
            throw new ApiException(422, 'Der Authenticator-Code ist ungültig oder abgelaufen.', 'invalid_two_factor_code', ['field' => 'code']);
        }
        $this->users->enableTwoFactor($auth->userId, (string) $setup['encryptedSecret']);

        return ['twoFactorEnabled' => true];
    }

    /** @param array<string, mixed> $payload */
    public function disableTwoFactor(AuthContext $auth, array $payload): array
    {
        $this->requireLocalAccount($auth);
        $this->confirmedUser($auth, $payload);
        $this->users->disableTwoFactor($auth->userId);

        return ['twoFactorEnabled' => false];
    }

    /**
     * Passwort und – falls aktiv – Authenticator-Code bestätigen sensible
     * Änderungen. Beim Start der 2FA ist naturgemäß noch kein Code erforderlich.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function confirmedUser(AuthContext $auth, array $payload, bool $requireExistingTwoFactor = true): array
    {
        $user = $this->users->findById($auth->userId);
        if (!password_verify((string) ($payload['currentPassword'] ?? ''), (string) ($user['passwordHash'] ?? ''))) {
            throw new ApiException(401, 'Das aktuelle Passwort ist falsch.', 'invalid_current_password', ['field' => 'currentPassword']);
        }
        if ($requireExistingTwoFactor && isset($user['twoFactor']['encryptedSecret'])) {
            $secret = $this->totp->decrypt((string) $user['twoFactor']['encryptedSecret']);
            if (!$this->totp->verify($secret, (string) ($payload['totpCode'] ?? ''))) {
                throw new ApiException(401, 'Bitte bestätigen Sie die Änderung mit einem gültigen Authenticator-Code.', 'invalid_two_factor_code', ['field' => 'totpCode']);
            }
        }
        return $user;
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 128
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            throw new ApiException(422, 'Das neue Passwort benötigt 12 bis 128 Zeichen sowie Großbuchstaben, Kleinbuchstaben und eine Zahl.', 'validation_failed', ['field' => 'newPassword']);
        }
    }

    private function requireLocalAccount(AuthContext $auth): void
    {
        $user = $this->users->findById($auth->userId);
        if (($user['authSource'] ?? 'local') !== 'local') {
            throw new ApiException(409, 'E-Mail-Adresse und Passwort werden durch LDAP beziehungsweise Active Directory verwaltet.', 'external_account_managed');
        }
    }

    /** @param array<string, mixed> $user */
    private function publicAccount(array $user): array
    {
        $result = [
            'id' => (string) $user['_id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'department' => $user['department'] ?? null,
            'twoFactorEnabled' => isset($user['twoFactor']['encryptedSecret']),
            'authSource' => $user['authSource'] ?? 'local',
            'passwordChangedAt' => $user['passwordChangedAt'] ?? null,
            'emailChangedAt' => $user['emailChangedAt'] ?? null,
        ];
        return DocumentSerializer::serialize($result);
    }
}
