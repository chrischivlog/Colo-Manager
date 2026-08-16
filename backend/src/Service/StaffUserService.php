<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\DirectoryConfigurationRepository;
use ColoManager\Repository\SessionRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Verwaltet ausschließlich interne Admin- und Mitarbeiterkonten. */
final readonly class StaffUserService
{
    private const ROLES = ['platform_admin', 'datacenter_staff'];
    private const SOURCES = ['local', 'ldap'];
    private const DEPARTMENTS = ['Technik', 'Vertrieb', 'Betrieb', 'Management', 'Sonstiges'];

    public function __construct(
        private UserRepository $users,
        private DirectoryConfigurationRepository $directories,
        private SessionRepository $sessions,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function list(AuthContext $auth): array
    {
        $this->requireAdmin($auth);
        return ['items' => array_map($this->publicUser(...), $this->users->listInternalUsers())];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireAdmin($auth);
        Validator::required($payload, ['name', 'email', 'role', 'authSource']);
        Validator::email($payload, 'email');
        $email = strtolower(trim((string) $payload['email']));
        if ($this->users->findAnyByEmail($email) !== null) {
            throw new ApiException(409, 'Diese E-Mail-Adresse wird bereits verwendet.', 'email_already_exists');
        }
        $data = $this->validated($payload, false);
        return $this->publicUser($this->users->create($data));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireAdmin($auth);
        $existing = $this->users->findById($id);
        if (!in_array($existing['role'] ?? null, self::ROLES, true)) {
            throw new ApiException(404, 'Der Mitarbeiter wurde nicht gefunden.', 'staff_user_not_found');
        }
        $merged = array_replace($existing, $payload);
        if (isset($payload['email'])) {
            Validator::email($payload, 'email');
            $other = $this->users->findAnyByEmail((string) $payload['email']);
            if ($other !== null && (string) $other['_id'] !== $id) throw new ApiException(409, 'Diese E-Mail-Adresse wird bereits verwendet.', 'email_already_exists');
        }
        $data = $this->validated($merged, true);
        if ($id === $auth->userId && (($data['active'] ?? true) === false || ($data['role'] ?? $existing['role']) !== 'platform_admin')) {
            throw new ApiException(409, 'Das eigene aktive Plattform-Administratorkonto darf nicht entzogen werden.', 'cannot_demote_self');
        }
        $updated = $this->users->updateInternalUser($id, $data, ($data['authSource'] ?? 'local') !== 'local');
        if (($updated['active'] ?? false) === false) $this->sessions->revokeAllSessions($id);
        return $this->publicUser($updated);
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireAdmin($auth);
        if ($id === $auth->userId) throw new ApiException(409, 'Das eigene Konto kann nicht gelöscht werden.', 'cannot_delete_self');
        $user = $this->users->findById($id);
        if (!in_array($user['role'] ?? null, self::ROLES, true)) throw new ApiException(404, 'Der Mitarbeiter wurde nicht gefunden.', 'staff_user_not_found');
        $this->users->softDelete($id);
        $this->sessions->revokeAllSessions($id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validated(array $payload, bool $update): array
    {
        Validator::enum($payload, 'role', self::ROLES);
        Validator::enum($payload, 'authSource', self::SOURCES);
        Validator::enum($payload, 'department', self::DEPARTMENTS);
        $source = (string) ($payload['authSource'] ?? 'local');
        $role = (string) $payload['role'];
        $data = [
            'name' => trim((string) $payload['name']),
            'email' => strtolower(trim((string) $payload['email'])),
            'role' => $role,
            'department' => $role === 'platform_admin' ? null : ($payload['department'] ?? 'Sonstiges'),
            'authSource' => $source,
            'active' => filter_var($payload['active'] ?? true, FILTER_VALIDATE_BOOL),
            'customerId' => null,
        ];
        if ($data['name'] === '' || mb_strlen($data['name']) > 160) throw new ApiException(422, 'Bitte geben Sie einen gültigen Namen ein.', 'validation_failed', ['field' => 'name']);
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) throw new ApiException(422, 'Die E-Mail-Adresse ist ungültig.', 'validation_failed', ['field' => 'email']);
        if ($source === 'local') {
            $password = (string) ($payload['password'] ?? '');
            if ($password !== '') {
                $this->validatePassword($password);
                $data['passwordHash'] = password_hash($password, PASSWORD_ARGON2ID);
            } elseif (!$update || empty($payload['passwordHash'])) {
                throw new ApiException(422, 'Für ein lokales Konto ist ein Passwort erforderlich.', 'validation_failed', ['field' => 'password']);
            } else {
                $data['passwordHash'] = $payload['passwordHash'];
            }
            $data['directoryId'] = null;
            $data['directoryUsername'] = null;
        } else {
            Validator::required($payload, ['directoryId', 'directoryUsername']);
            $directory = $this->directories->findById((string) $payload['directoryId']);
            if (($directory['active'] ?? false) !== true) throw new ApiException(422, 'Die gewählte Verzeichnisverbindung ist nicht aktiv.', 'directory_inactive');
            $data['directoryId'] = (string) $directory['_id'];
            $data['directoryUsername'] = trim((string) $payload['directoryUsername']);
            if ($data['directoryUsername'] === '') throw new ApiException(422, 'Bitte geben Sie den LDAP-/AD-Benutzernamen ein.', 'validation_failed', ['field' => 'directoryUsername']);
        }
        return $data;
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 128 || preg_match('/[a-z]/',$password)!==1 || preg_match('/[A-Z]/',$password)!==1 || preg_match('/\d/',$password)!==1) {
            throw new ApiException(422, 'Das Passwort benötigt 12 bis 128 Zeichen sowie Großbuchstaben, Kleinbuchstaben und eine Zahl.', 'validation_failed', ['field' => 'password']);
        }
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function publicUser(array $user): array
    {
        $user['authSource'] ??= 'local';
        $user['twoFactorEnabled'] = isset($user['twoFactor']['encryptedSecret']);
        unset($user['passwordHash'],$user['passwordReset'],$user['twoFactor'],$user['twoFactorSetup'],$user['deletedAt'],$user['customerId']);
        return DocumentSerializer::serialize($user);
    }

    private function requireAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) throw new ApiException(403, 'Nur Plattform-Administratoren dürfen Mitarbeiter verwalten.', 'forbidden');
    }
}
