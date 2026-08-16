<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use InvalidArgumentException;

/** Unveränderlicher, direkt aus dem Speicher versendeter E-Mail-Anhang. */
final readonly class MailAttachment
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $content,
    ) {
        if (trim($name) === '' || preg_match('/[\r\n]/', $name) === 1) {
            throw new InvalidArgumentException('Der Dateiname des E-Mail-Anhangs ist ungültig.');
        }
        if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType) !== 1) {
            throw new InvalidArgumentException('Der MIME-Typ des E-Mail-Anhangs ist ungültig.');
        }
    }
}
