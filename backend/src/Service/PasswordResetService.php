<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Http\ApiException;
use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use MongoDB\BSON\UTCDateTime;

/** Sicherer, zeitlich begrenzter und nur einmal nutzbarer Passwort-Reset. */
final readonly class PasswordResetService
{
    private const TOKEN_MINUTES = 30;
    private const REQUEST_COOLDOWN_SECONDS = 60;

    public function __construct(
        private UserRepository $users,
        private NotificationMailService $notifications,
        private string $frontendUrl,
    ) {
    }

    /**
     * Liefert für bekannte und unbekannte Adressen absichtlich dieselbe Antwort,
     * damit sich über diesen Endpunkt keine Portalzugänge ermitteln lassen.
     */
    public function request(string $email): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || ($user['authSource'] ?? 'local') !== 'local') {
            return $this->genericResponse();
        }

        $lastRequest = $user['passwordReset']['requestedAt'] ?? null;
        if ($lastRequest instanceof UTCDateTime
            && $lastRequest->toDateTime()->getTimestamp() > time() - self::REQUEST_COOLDOWN_SECONDS) {
            return $this->genericResponse();
        }

        $token = bin2hex(random_bytes(32));
        $userId = (string) $user['_id'];
        $this->users->setPasswordReset($userId, [
            'tokenHash' => hash('sha256', $token),
            'status' => 'pending',
            'requestedAt' => new UTCDateTime(),
            'expiresAt' => new UTCDateTime((time() + self::TOKEN_MINUTES * 60) * 1000),
            'emailStatus' => 'pending',
        ]);

        try {
            $this->notifications->sendPasswordReset(
                (string) $user['email'],
                (string) ($user['name'] ?? $user['email']),
                $this->frontendUrl . '/passwort-zuruecksetzen.html?token=' . $token,
                self::TOKEN_MINUTES,
            );
            $this->users->setPasswordResetMailStatus($userId, 'sent');
        } catch (MailDeliveryException) {
            // Auch ein SMTP-Fehler darf nicht verraten, ob das Konto existiert.
            $this->users->setPasswordResetMailStatus($userId, 'failed');
        }

        return $this->genericResponse();
    }

    /** Liefert nur maskierte Metadaten für die öffentliche Reset-Seite. */
    public function inspect(string $token): array
    {
        $user = $this->userForToken($token);
        $reset = $user['passwordReset'];
        if (($reset['status'] ?? null) === 'used') {
            return ['status' => 'used', 'email' => $this->maskEmail((string) $user['email'])];
        }
        $this->assertNotExpired($reset);

        return DocumentSerializer::serialize([
            'status' => 'pending',
            'email' => $this->maskEmail((string) $user['email']),
            'expiresAt' => $reset['expiresAt'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function reset(string $token, array $payload): array
    {
        $user = $this->userForToken($token);
        $reset = $user['passwordReset'];
        if (($reset['status'] ?? null) !== 'pending') {
            throw new ApiException(409, 'Dieser Passwort-Link wurde bereits verwendet.', 'password_reset_used');
        }
        $this->assertNotExpired($reset);

        $password = (string) ($payload['password'] ?? '');
        if ($password !== (string) ($payload['passwordConfirmation'] ?? '')) {
            throw new ApiException(422, 'Die Passwortbestätigung stimmt nicht überein.', 'validation_failed', ['field' => 'passwordConfirmation']);
        }
        $this->assertStrongPassword($password);

        $consumed = $this->users->consumePasswordReset(
            hash('sha256', $token),
            password_hash($password, PASSWORD_ARGON2ID),
        );
        if (!$consumed) {
            throw new ApiException(409, 'Dieser Passwort-Link ist nicht mehr gültig.', 'password_reset_not_available');
        }

        return ['status' => 'completed', 'loginUrl' => '/login.html'];
    }

    /** @return array<string, mixed> */
    private function userForToken(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new ApiException(404, 'Dieser Passwort-Link ist ungültig.', 'password_reset_not_found');
        }
        $user = $this->users->findByPasswordResetTokenHash(hash('sha256', $token));
        if ($user === null) {
            throw new ApiException(404, 'Dieser Passwort-Link ist ungültig.', 'password_reset_not_found');
        }
        return $user;
    }

    /** @param array<string, mixed> $reset */
    private function assertNotExpired(array $reset): void
    {
        $expiresAt = $reset['expiresAt'] ?? null;
        if (!$expiresAt instanceof UTCDateTime || $expiresAt->toDateTime()->getTimestamp() <= time()) {
            throw new ApiException(410, 'Dieser Passwort-Link ist abgelaufen. Bitte fordern Sie einen neuen an.', 'password_reset_expired');
        }
    }

    private function assertStrongPassword(string $password): void
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 128
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            throw new ApiException(422, 'Das Passwort benötigt 12 bis 128 Zeichen sowie Großbuchstaben, Kleinbuchstaben und eine Zahl.', 'validation_failed', ['field' => 'password']);
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('•', max(3, min(8, mb_strlen($local) - 1))) . '@' . $domain;
    }

    private function genericResponse(): array
    {
        return [
            'status' => 'accepted',
            'message' => 'Falls ein aktives Konto zu dieser E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen versendet.',
        ];
    }
}
