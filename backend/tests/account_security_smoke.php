<?php

declare(strict_types=1);

use ColoManager\Config;
use ColoManager\Database\MongoConnection;
use ColoManager\Security\TotpService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Isolierter Sicherheitstest mit einem temporären Benutzer. Dadurch verändert
 * der Test weder Passwörter noch 2FA-Einstellungen realer Seed-Konten.
 *
 * @param array<string, mixed>|null $body
 * @return array{status: int, body: array<string, mixed>}
 */
function securityRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    if (!is_string($raw)) {
        throw new RuntimeException('HTTP-Aufruf fehlgeschlagen: ' . $method . ' ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function securityAssert(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status) {
        throw new RuntimeException(sprintf('%s: Status %d statt %d: %s', $label, $response['status'], $status, json_encode($response['body'], JSON_UNESCAPED_UNICODE)));
    }
    fwrite(STDOUT, sprintf("[OK] %s (%d)\n", $label, $status));
}

$config = Config::fromEnvironment();
$database = (new MongoConnection($config))->database();
$users = $database->selectCollection('users');
$sessions = $database->selectCollection('auth_sessions');
$userId = new ObjectId();
$email = 'security-' . bin2hex(random_bytes(6)) . '@example.invalid';
$changedEmail = 'changed-' . bin2hex(random_bytes(6)) . '@example.invalid';
$password = 'Security12345!';
$newPassword = 'NewSecurity12345!';
$now = new UTCDateTime();

$users->insertOne([
    '_id' => $userId,
    'email' => $email,
    'name' => 'Security Smoke Test',
    'passwordHash' => password_hash($password, PASSWORD_ARGON2ID),
    'role' => 'customer_admin',
    'customerId' => null,
    'active' => true,
    'createdAt' => $now,
    'updatedAt' => $now,
    'deletedAt' => null,
]);

try {
    $login = securityRequest('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    securityAssert($login, 200, 'Login erzeugt serverseitige Fünf-Minuten-Sitzung');
    if (($login['body']['data']['idleTimeoutSeconds'] ?? null) !== 300) {
        throw new RuntimeException('Der Login liefert nicht das erwartete Inaktivitätslimit von 300 Sekunden.');
    }
    $token = (string) $login['body']['data']['accessToken'];

    securityAssert(securityRequest('GET', '/api/v1/account', token: $token), 200, 'Eigenes Konto laden');
    $setup = securityRequest('POST', '/api/v1/account/2fa/setup', ['currentPassword' => $password], $token);
    securityAssert($setup, 200, 'Authenticator-Einrichtung starten');
    $secret = (string) ($setup['body']['data']['secret'] ?? '');
    if ($secret === '' || !str_starts_with((string) ($setup['body']['data']['provisioningUri'] ?? ''), 'otpauth://totp/')) {
        throw new RuntimeException('Die 2FA-Einrichtung enthält kein Secret oder keine Provisioning-URI.');
    }
    $totp = new TotpService($config);
    // Der Service wird für die Testcode-Erzeugung nur über Reflection auf die
    // identische RFC-6238-Berechnung geprüft; das Secret bleibt temporär.
    $counter = intdiv(time(), 30);
    $method = new ReflectionMethod(TotpService::class, 'codeAt');
    $code = (string) $method->invoke($totp, $secret, $counter);
    securityAssert(securityRequest('POST', '/api/v1/account/2fa/confirm', ['code' => $code], $token), 200, 'Authenticator-Code bestätigen');

    $missingTwoFactor = securityRequest('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    securityAssert($missingTwoFactor, 401, 'Login fordert zweiten Faktor an');
    if (($missingTwoFactor['body']['error']['code'] ?? null) !== 'two_factor_required') {
        throw new RuntimeException('Beim 2FA-Login fehlt der maschinenlesbare Fehlercode.');
    }
    $twoFactorLogin = securityRequest('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password, 'totpCode' => $code]);
    securityAssert($twoFactorLogin, 200, 'Login mit Authenticator-Code');
    $token = (string) $twoFactorLogin['body']['data']['accessToken'];

    securityAssert(securityRequest('PATCH', '/api/v1/account/email', ['email' => $changedEmail, 'currentPassword' => $password, 'totpCode' => $code], $token), 200, 'E-Mail mit Passwort und 2FA ändern');
    securityAssert(securityRequest('PATCH', '/api/v1/account/password', ['currentPassword' => $password, 'newPassword' => $newPassword, 'newPasswordConfirmation' => $newPassword, 'totpCode' => $code], $token), 200, 'Passwort mit Passwort und 2FA ändern');
    securityAssert(securityRequest('DELETE', '/api/v1/account/2fa', ['currentPassword' => $newPassword, 'totpCode' => $code], $token), 200, 'Zwei-Faktor-Schutz bestätigt deaktivieren');

    // Der Test setzt die Aktivität gezielt zurück, statt fünf Minuten real zu
    // warten, und prüft damit den verbindlichen serverseitigen Ablauf.
    $sessions->updateMany(
        ['sessionId' => ['$exists' => true], 'userId' => $userId, 'revokedAt' => null],
        ['$set' => ['lastActivityAt' => new UTCDateTime(new DateTimeImmutable('-6 minutes'))]],
    );
    $expired = securityRequest('GET', '/api/v1/account', token: $token);
    securityAssert($expired, 401, 'Inaktive Sitzung serverseitig ablehnen');
    if (($expired['body']['error']['code'] ?? null) !== 'session_expired') {
        throw new RuntimeException('Der Inaktivitätsablauf liefert nicht session_expired.');
    }
} finally {
    $sessions->deleteMany(['userId' => $userId]);
    $users->deleteOne(['_id' => $userId]);
}

fwrite(STDOUT, "Alle Konto- und Sicherheitstests erfolgreich.\n");
