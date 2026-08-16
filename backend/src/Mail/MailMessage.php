<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use InvalidArgumentException;

/** Unveränderliches Transportobjekt für eine vollständig gerenderte E-Mail. */
final readonly class MailMessage
{
    /**
     * @param list<string> $recipients
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param list<MailAttachment> $attachments
     */
    public function __construct(
        public array $recipients,
        public string $subject,
        public string $html,
        public string $text,
        public array $cc = [],
        public array $bcc = [],
        public ?string $replyTo = null,
        public array $attachments = [],
    ) {
        if ($recipients === []) {
            throw new InvalidArgumentException('Mindestens ein E-Mail-Empfänger ist erforderlich.');
        }

        foreach (array_merge($recipients, $cc, $bcc, $replyTo === null ? [] : [$replyTo]) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(sprintf('Ungültige E-Mail-Adresse: %s', $address));
            }
        }
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof MailAttachment) {
                throw new InvalidArgumentException('E-Mail-Anhänge müssen als MailAttachment übergeben werden.');
            }
        }
    }
}
