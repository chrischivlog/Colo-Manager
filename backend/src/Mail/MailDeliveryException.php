<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use RuntimeException;

/** Domänenspezifischer Fehler für fehlgeschlagene SMTP-Zustellungen. */
final class MailDeliveryException extends RuntimeException
{
}
