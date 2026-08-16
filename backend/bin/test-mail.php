<?php

declare(strict_types=1);

use ColoManager\Config;
use ColoManager\Mail\MailFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Liest den lokalen Testempfänger; produktive Empfänger werden nie fest codiert. */
function testMailRecipient(): string
{
    $value = $_ENV['MAIL_TEST_RECIPIENT'] ?? $_SERVER['MAIL_TEST_RECIPIENT'] ?? getenv('MAIL_TEST_RECIPIENT');
    return is_string($value) && $value !== '' ? $value : 'developer@colomanager.local';
}

$config = Config::fromEnvironment();
$recipient = testMailRecipient();
$notifications = MailFactory::notifications($config);

$notifications->sendSystemUpdate(
    email: $recipient,
    name: 'Developer',
    title: 'COLO MANAGER Mailversand erfolgreich',
    message: 'Die SMTP-Verbindung und das transaktionale E-Mail-Template funktionieren.',
    portalUrl: $config->appUrl,
);

fwrite(STDOUT, sprintf("Test-E-Mail an %s versendet.\n", $recipient));
