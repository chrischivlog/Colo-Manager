<?php

declare(strict_types=1);

namespace ColoManager\Security;

use ColoManager\Config;
use ColoManager\Http\ApiException;

/** Verschlüsselt administrative Zugangsdaten mit einem zweckgebundenen Schlüssel. */
final readonly class SecretCipher
{
    public function __construct(private Config $config)
    {
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plainText, $nonce, $this->key()));
    }

    public function decrypt(string $encrypted): string
    {
        $payload = base64_decode($encrypted, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ApiException(500, 'Das gespeicherte Verzeichniskennwort ist beschädigt.', 'directory_secret_invalid');
        }
        $plainText = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if (!is_string($plainText)) {
            throw new ApiException(500, 'Das Verzeichniskennwort konnte nicht entschlüsselt werden.', 'directory_secret_invalid');
        }
        return $plainText;
    }

    private function key(): string
    {
        return hash('sha256', 'colo-manager-directory:' . $this->config->jwtSecret, true);
    }
}
