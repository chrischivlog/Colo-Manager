<?php

declare(strict_types=1);

namespace ColoManager\Security;

use ColoManager\Http\ApiException;

/**
 * Authentifiziert Mitarbeiter gegen generisches LDAP oder Microsoft Active
 * Directory. Rollen werden absichtlich nicht aus Gruppen übernommen, sondern
 * bleiben im lokalen Mitarbeiterprofil kontrolliert.
 */
final readonly class DirectoryAuthenticator
{
    public function __construct(private SecretCipher $secrets)
    {
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function authenticate(array $configuration, string $email, string $username, string $password): array
    {
        if ($password === '') {
            throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
        }
        $connection = $this->connect($configuration);
        $bindTemplate = trim((string) ($configuration['userBindTemplate'] ?? ''));
        if ($bindTemplate !== '') {
            $identity = $this->template($bindTemplate, $email, $username);
            $this->bind($connection, $identity, $password, false);
            return ['dn' => $identity, 'email' => $email, 'username' => $username];
        }

        $this->serviceBind($connection, $configuration);
        $entry = $this->findUser($connection, $configuration, $email, $username);
        $this->bind($connection, (string) $entry['dn'], $password, false);
        return $entry;
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function test(array $configuration): array
    {
        $startedAt = microtime(true);
        $connection = $this->connect($configuration);
        $bindDn = trim((string) ($configuration['bindDn'] ?? ''));
        if ($bindDn !== '') {
            $this->serviceBind($connection, $configuration);
        }
        return [
            'connected' => true,
            'serviceBindVerified' => $bindDn !== '',
            'latencyMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function connect(array $configuration): \LDAP\Connection
    {
        if (!extension_loaded('ldap')) {
            throw new ApiException(503, 'Die LDAP-Erweiterung ist auf dem API-Server nicht verfügbar.', 'ldap_extension_missing');
        }
        $encryption = (string) ($configuration['encryption'] ?? 'starttls');
        $scheme = $encryption === 'ldaps' ? 'ldaps' : 'ldap';
        $host = trim((string) ($configuration['host'] ?? ''));
        $port = (int) ($configuration['port'] ?? ($encryption === 'ldaps' ? 636 : 389));
        $connection = @ldap_connect(sprintf('%s://%s:%d', $scheme, $host, $port));
        if (!$connection instanceof \LDAP\Connection) {
            throw new ApiException(502, 'Die Verzeichnisverbindung konnte nicht aufgebaut werden.', 'directory_connection_failed');
        }
        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, max(1, min(15, (int) ($configuration['timeoutSeconds'] ?? 5))));
        if ($encryption === 'starttls' && !@ldap_start_tls($connection)) {
            throw new ApiException(502, 'StartTLS konnte für die Verzeichnisverbindung nicht aktiviert werden.', 'directory_tls_failed');
        }
        return $connection;
    }

    /** @param array<string, mixed> $configuration */
    private function serviceBind(\LDAP\Connection $connection, array $configuration): void
    {
        $bindDn = trim((string) ($configuration['bindDn'] ?? ''));
        $encrypted = (string) ($configuration['bindPasswordEncrypted'] ?? '');
        if ($bindDn === '' || $encrypted === '') {
            throw new ApiException(422, 'Für die Benutzersuche fehlen Bind-DN oder Bind-Kennwort.', 'directory_bind_incomplete');
        }
        $this->bind($connection, $bindDn, $this->secrets->decrypt($encrypted), true);
    }

    private function bind(\LDAP\Connection $connection, string $identity, string $password, bool $administrative): void
    {
        if (!@ldap_bind($connection, $identity, $password)) {
            if ($administrative) {
                throw new ApiException(502, 'Der technische LDAP-/AD-Bind ist fehlgeschlagen.', 'directory_bind_failed');
            }
            throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
        }
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function findUser(\LDAP\Connection $connection, array $configuration, string $email, string $username): array
    {
        $baseDn = trim((string) ($configuration['userSearchBase'] ?? $configuration['baseDn'] ?? ''));
        $template = trim((string) ($configuration['userFilter'] ?? ''));
        if ($baseDn === '' || $template === '') {
            throw new ApiException(422, 'Suchbasis und Benutzerfilter müssen konfiguriert sein.', 'directory_search_incomplete');
        }
        $filter = $this->template($template, $email, $username, true);
        $attributes = array_values(array_unique(array_filter([
            (string) ($configuration['emailAttribute'] ?? 'mail'),
            (string) ($configuration['nameAttribute'] ?? 'displayName'),
            (string) ($configuration['usernameAttribute'] ?? 'uid'),
        ])));
        $search = @ldap_search($connection, $baseDn, $filter, $attributes, 0, 2);
        if ($search === false) {
            throw new ApiException(502, 'Die LDAP-/AD-Benutzersuche ist fehlgeschlagen.', 'directory_search_failed');
        }
        $entries = ldap_get_entries($connection, $search);
        if (($entries['count'] ?? 0) !== 1) {
            throw new ApiException(401, 'E-Mail-Adresse oder Passwort ist falsch.', 'invalid_credentials');
        }
        $entry = $entries[0];
        return [
            'dn' => (string) $entry['dn'],
            'email' => $this->attribute($entry, (string) ($configuration['emailAttribute'] ?? 'mail')) ?: $email,
            'name' => $this->attribute($entry, (string) ($configuration['nameAttribute'] ?? 'displayName')),
            'username' => $this->attribute($entry, (string) ($configuration['usernameAttribute'] ?? 'uid')) ?: $username,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function attribute(array $entry, string $name): ?string
    {
        $key = mb_strtolower($name);
        $value = $entry[$key][0] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function template(string $template, string $email, string $username, bool $filter = false): string
    {
        $sanitize = static function (string $value) use ($filter): string {
            if ($filter) {
                return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
            }
            // Direkte Bind-Vorlagen werden als LDAP-DN beziehungsweise UPN
            // verwendet. DN-Escaping verhindert, dass Kontonamen zusätzliche
            // RDN-Bestandteile einschleusen können.
            return ldap_escape(str_replace(["\0", "\r", "\n"], '', $value), '', LDAP_ESCAPE_DN);
        };
        return strtr($template, ['{email}' => $sanitize($email), '{username}' => $sanitize($username)]);
    }
}
