<?php

declare(strict_types=1);

namespace ColoManager\Mail;

/** Austauschbare Transport-Schnittstelle, damit SMTP später ersetzt werden kann. */
interface MailSender
{
    public function send(MailMessage $message): void;
}
