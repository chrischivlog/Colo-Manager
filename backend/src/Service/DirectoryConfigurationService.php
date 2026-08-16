<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\DirectoryConfigurationRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Security\DirectoryAuthenticator;
use ColoManager\Security\SecretCipher;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Administration sicherer LDAP- und Active-Directory-Verbindungen. */
final readonly class DirectoryConfigurationService
{
    private const TYPES = ['ldap', 'active_directory'];
    private const ENCRYPTIONS = ['none', 'starttls', 'ldaps'];

    public function __construct(
        private DirectoryConfigurationRepository $directories,
        private UserRepository $users,
        private DirectoryAuthenticator $authenticator,
        private SecretCipher $secrets,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function list(AuthContext $auth): array
    {
        $this->requireAdmin($auth);
        return ['items' => array_map($this->publicConfiguration(...), $this->directories->list())];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireAdmin($auth);
        $data = $this->validated($payload, false);
        return $this->publicConfiguration($this->directories->create($data, $auth->userId));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireAdmin($auth);
        $existing = $this->directories->findById($id);
        $data = $this->validated(array_replace($existing, $payload), true);
        $unset = [];
        if (array_key_exists('bindPassword', $payload) && trim((string) $payload['bindPassword']) === '') {
            unset($data['bindPasswordEncrypted']);
        }
        return $this->publicConfiguration($this->directories->update($id, $data, $unset, $auth->userId));
    }

    /** @return array<string, mixed> */
    public function test(AuthContext $auth, string $id): array
    {
        $this->requireAdmin($auth);
        return $this->authenticator->test($this->directories->findById($id));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireAdmin($auth);
        foreach ($this->users->listInternalUsers() as $user) {
            if (($user['authSource'] ?? 'local') !== 'local' && (string) ($user['directoryId'] ?? '') === $id) {
                throw new ApiException(409, 'Die Verbindung wird noch von einem Mitarbeiterkonto verwendet.', 'directory_in_use');
            }
        }
        $this->directories->delete($id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validated(array $payload, bool $update): array
    {
        Validator::required($payload, ['name', 'type', 'host', 'port', 'encryption']);
        Validator::enum($payload, 'type', self::TYPES);
        Validator::enum($payload, 'encryption', self::ENCRYPTIONS);
        Validator::number($payload, 'port', 1, 65535);
        Validator::number($payload, 'timeoutSeconds', 1, 15);
        $type = (string) $payload['type'];
        $defaults = $type === 'active_directory' ? [
            'userFilter' => '(&(objectCategory=person)(objectClass=user)(|(mail={email})(userPrincipalName={email})(sAMAccountName={username})))',
            'emailAttribute' => 'mail', 'nameAttribute' => 'displayName', 'usernameAttribute' => 'sAMAccountName',
        ] : [
            'userFilter' => '(&(objectClass=person)(|(mail={email})(uid={username})))',
            'emailAttribute' => 'mail', 'nameAttribute' => 'cn', 'usernameAttribute' => 'uid',
        ];
        $data = Validator::only($payload, [
            'name', 'type', 'host', 'port', 'encryption', 'baseDn', 'bindDn', 'userSearchBase',
            'userFilter', 'userBindTemplate', 'emailAttribute', 'nameAttribute', 'usernameAttribute',
            'timeoutSeconds', 'active',
        ]);
        foreach (['name','host','baseDn','bindDn','userSearchBase','userFilter','userBindTemplate','emailAttribute','nameAttribute','usernameAttribute'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] = trim((string) $data[$field]);
        }
        if (!preg_match('/^[a-z0-9._:-]+$/i', (string) $data['host'])) {
            throw new ApiException(422, 'Bitte geben Sie nur den Hostnamen oder die IP-Adresse ohne URL-Schema ein.', 'validation_failed', ['field' => 'host']);
        }
        $data['port'] = (int) $data['port'];
        $data['timeoutSeconds'] = (int) ($data['timeoutSeconds'] ?? 5);
        $data['active'] = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOL);
        foreach ($defaults as $field => $value) {
            if (trim((string) ($data[$field] ?? '')) === '') $data[$field] = $value;
        }
        if (trim((string) ($data['userBindTemplate'] ?? '')) === '' && (trim((string) ($data['bindDn'] ?? '')) === '' || trim((string) ($data['userSearchBase'] ?? $data['baseDn'] ?? '')) === '')) {
            throw new ApiException(422, 'Ohne direkte Bind-Vorlage sind Bind-DN und Suchbasis erforderlich.', 'directory_configuration_incomplete');
        }
        $bindPassword = trim((string) ($payload['bindPassword'] ?? ''));
        if ($bindPassword !== '') {
            $data['bindPasswordEncrypted'] = $this->secrets->encrypt($bindPassword);
        } elseif (!$update && trim((string) ($data['userBindTemplate'] ?? '')) === '') {
            throw new ApiException(422, 'Bitte hinterlegen Sie das technische Bind-Kennwort.', 'validation_failed', ['field' => 'bindPassword']);
        } elseif ($update && isset($payload['bindPasswordEncrypted'])) {
            $data['bindPasswordEncrypted'] = $payload['bindPasswordEncrypted'];
        }
        return $data;
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function publicConfiguration(array $configuration): array
    {
        $configuration['hasBindPassword'] = isset($configuration['bindPasswordEncrypted']);
        unset($configuration['bindPasswordEncrypted'], $configuration['createdByUserId'], $configuration['updatedByUserId'], $configuration['deletedAt']);
        return DocumentSerializer::serialize($configuration);
    }

    private function requireAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) throw new ApiException(403, 'Nur Plattform-Administratoren dürfen Anmeldequellen verwalten.', 'forbidden');
    }
}
