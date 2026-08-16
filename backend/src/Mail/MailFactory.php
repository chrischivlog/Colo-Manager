<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use ColoManager\Config;
use ColoManager\Support\DocumentBranding;

/** Zentraler Aufbau des Mail-Moduls für HTTP-Anwendung, Worker und CLI-Befehle. */
final class MailFactory
{
    public static function notifications(Config $config, ?DocumentBranding $branding = null): NotificationMailService
    {
        $branding ??= new DocumentBranding();
        return new NotificationMailService(
            new SymfonyMailSender($config, $branding->companyName),
            new MailTemplateRenderer($branding),
        );
    }
}
