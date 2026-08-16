<?php

declare(strict_types=1);

use ColoManager\Config;
use ColoManager\Database\MongoConnection;
use ColoManager\Mail\MailFactory;
use ColoManager\Repository\BrandingAssetRepository;
use ColoManager\Repository\BrandingRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Service\BrandingService;
use ColoManager\Service\OnboardingReminderService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment();
$database = (new MongoConnection($config))->database();
$tickets = new TicketRepository($database);
$messages = new TicketMessageRepository($database);
$users = new UserRepository($database);
$branding = (new BrandingService(
    new BrandingRepository($database),
    new BrandingAssetRepository($database),
))->documentBranding($config->appUrl);
$service = new OnboardingReminderService(
    $tickets,
    $messages,
    $users,
    MailFactory::notifications($config, $branding),
    $config->frontendUrl,
);

$loop = in_array('--loop', $argv, true);
do {
    try {
        $result = $service->sendDue();
        if (array_sum($result) > 0 || !$loop) {
            fwrite(STDOUT, sprintf(
                "[%s] Onboarding-Erinnerungen: %d versendet, %d fehlgeschlagen, %d übersprungen.\n",
                (new DateTimeImmutable())->format(DATE_ATOM),
                $result['sent'],
                $result['failed'],
                $result['skipped'],
            ));
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("[%s] Reminder-Worker: %s\n", (new DateTimeImmutable())->format(DATE_ATOM), $exception->getMessage()));
    }
    if ($loop) {
        sleep(60);
    }
} while ($loop);
