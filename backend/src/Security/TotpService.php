<?php

declare(strict_types=1);

namespace ColoManager\Security;

use ColoManager\Config;
use ColoManager\Http\ApiException;

/** RFC-6238-Authenticator-Codes mit verschlüsselter Speicherung des Secrets. */
final readonly class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private Config $config)
    {
    }

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $email, string $secret): string
    {
        $issuer = 'COLO MANAGER';
        $label = rawurlencode($issuer . ':' . strtolower(trim($email)));

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer),
        );
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $normalized = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($normalized) !== 6) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        // Ein Zeitschritt Toleranz gleicht geringe Uhrabweichungen aus.
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $normalized)) {
                return true;
            }
        }
        return false;
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $this->encryptionKey());

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        $payload = base64_decode($encrypted, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ApiException(500, 'Die Zwei-Faktor-Konfiguration ist beschädigt.', 'two_factor_configuration_invalid');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->encryptionKey());
        if (!is_string($plain)) {
            throw new ApiException(500, 'Die Zwei-Faktor-Konfiguration konnte nicht gelesen werden.', 'two_factor_configuration_invalid');
        }
        return $plain;
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function encryptionKey(): string
    {
        return hash('sha256', 'colo-manager-totp:' . $this->config->jwtSecret, true);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::ALPHABET[bindec($chunk)];
        }
        return $result;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new ApiException(500, 'Das Authenticator-Secret ist ungültig.', 'two_factor_configuration_invalid');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr(bindec($chunk));
            }
        }
        return $result;
    }
}
