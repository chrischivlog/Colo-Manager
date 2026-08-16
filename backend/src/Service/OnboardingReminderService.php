<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use MongoDB\BSON\UTCDateTime;

/**
 * Hintergrundprozess für genau einmalige Techniker-Erinnerungen am Termintag.
 * Die atomare Reservierung im Repository verhindert Doppelversand durch zwei
 * parallel laufende Worker.
 */
final readonly class OnboardingReminderService
{
    public function __construct(
        private TicketRepository $tickets,
        private TicketMessageRepository $messages,
        private UserRepository $users,
        private NotificationMailService $notifications,
        private string $frontendUrl,
    ) {
    }

    /** @return array{sent: int, failed: int, skipped: int} */
    public function sendDue(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        for ($processed = 0; $processed < max(1, $limit); $processed++) {
            $ticket = $this->tickets->claimDueOnboardingReminder();
            if ($ticket === null) {
                break;
            }
            $ticketId = (string) $ticket['_id'];
            $appointment = $ticket['leadProcess']['onboarding']['appointment'] ?? [];
            try {
                $start = $this->dateTime($appointment['startAt'] ?? null);
                if ($start <= new \DateTimeImmutable()) {
                    $this->tickets->update($ticketId, [
                        'leadProcess.onboarding.appointment.reminder.status' => 'skipped',
                        'leadProcess.onboarding.appointment.reminder.skippedAt' => new UTCDateTime(),
                        'leadProcess.onboarding.appointment.reminder.reason' => 'appointment_already_started',
                    ]);
                    $result['skipped']++;
                    continue;
                }
                $technician = $this->users->findTechnician((string) ($ticket['leadProcess']['onboarding']['assignedToUserId'] ?? ''));
                $technicianEmail = (string) ($technician['email'] ?? '');
                if (filter_var($technicianEmail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \RuntimeException('Der zugewiesene Techniker hat keine gültige E-Mail-Adresse.');
                }
                $timezone = (string) ($appointment['timezone'] ?? 'Europe/Berlin');
                $duration = max(15, (int) ($appointment['durationMinutes'] ?? 60));
                $this->notifications->sendOnboardingTechnicianReminder(
                    $technicianEmail,
                    (string) ($technician['name'] ?? $technicianEmail),
                    (string) $ticket['number'],
                    (string) ($ticket['requester']['company'] ?? $ticket['requester']['name'] ?? 'den Kunden'),
                    $this->appointmentLabel($start->setTimezone(new \DateTimeZone($timezone)), $duration, $timezone),
                    (string) ($appointment['location'] ?? ''),
                    $this->frontendUrl . '/admin.html?ticket=' . $ticketId,
                );
                $sentAt = new UTCDateTime();
                $this->tickets->update($ticketId, [
                    'leadProcess.onboarding.appointment.reminder.status' => 'sent',
                    'leadProcess.onboarding.appointment.reminder.sentAt' => $sentAt,
                    'leadProcess.onboarding.appointment.reminder.lastError' => null,
                ]);
                $this->addSystemMessage(
                    $ticketId,
                    'Techniker-Erinnerung versendet',
                    sprintf('Das Ticketsystem hat %s am Termintag automatisch an das anstehende Onboarding erinnert.', (string) ($technician['name'] ?? $technicianEmail)),
                );
                $result['sent']++;
            } catch (MailDeliveryException $exception) {
                // Bis zum Termin wird ein temporärer SMTP-Fehler alle 15
                // Minuten erneut versucht; danach greift der Überspringpfad.
                $this->tickets->update($ticketId, [
                    'leadProcess.onboarding.appointment.reminder.status' => 'pending',
                    'leadProcess.onboarding.appointment.reminder.dueAt' => new UTCDateTime((time() + 900) * 1000),
                    'leadProcess.onboarding.appointment.reminder.lastError' => mb_substr($exception->getMessage(), 0, 300),
                ]);
                $result['failed']++;
            } catch (\Throwable $exception) {
                $this->tickets->update($ticketId, [
                    'leadProcess.onboarding.appointment.reminder.status' => 'failed',
                    'leadProcess.onboarding.appointment.reminder.failedAt' => new UTCDateTime(),
                    'leadProcess.onboarding.appointment.reminder.lastError' => mb_substr($exception->getMessage(), 0, 300),
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTimeImmutable();
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Der Onboarding-Termin enthält keine Startzeit.');
        }
        return new \DateTimeImmutable($value);
    }

    private function appointmentLabel(\DateTimeImmutable $start, int $durationMinutes, string $timezone): string
    {
        $weekdays = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $end = $start->modify('+' . $durationMinutes . ' minutes');
        return sprintf('%s, %d. %s %s von %s bis %s Uhr (%s)',
            $weekdays[(int) $start->format('N')],
            (int) $start->format('j'),
            $months[(int) $start->format('n')],
            $start->format('Y'),
            $start->format('H:i'),
            $end->format('H:i'),
            $timezone,
        );
    }

    private function addSystemMessage(string $ticketId, string $title, string $text): void
    {
        $this->messages->create($ticketId, [
            'bodyHtml' => sprintf('<p><strong>%s.</strong> %s</p>', htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'bodyText' => $title . '. ' . $text,
            'author' => ['type' => 'system', 'name' => 'Ticketsystem'],
            'internal' => true,
            'eventType' => 'onboarding_technician_reminder_sent',
        ]);
        $this->tickets->registerMessage($ticketId, true);
    }
}
