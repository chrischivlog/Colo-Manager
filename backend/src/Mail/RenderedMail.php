<?php

declare(strict_types=1);

namespace ColoManager\Mail;

/** Ergebnis einer Vorlage mit Betreff sowie HTML- und Textversion. */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {
    }
}
