<?php

declare(strict_types=1);

namespace ColoManager\Support;

/**
 * Erzeugt portable RFC-5545-Termine. UTC-Zeitwerte vermeiden Abweichungen
 * zwischen Outlook, Apple Kalender und Google Kalender bei Sommerzeitwechseln.
 */
final class IcalendarGenerator
{
    public function generate(
        string $uid,
        int $sequence,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $summary,
        string $description,
        string $location,
        string $organizerEmail,
        string $organizerName,
        string $attendeeEmail,
        string $attendeeName,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Colo Manager//Onboarding//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'SEQUENCE:' . max(0, $sequence),
            'DTSTAMP:' . (new \DateTimeImmutable('now', $utc))->format('Ymd\THis\Z'),
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape($description),
            'LOCATION:' . $this->escape($location),
            sprintf('ORGANIZER;CN=%s:mailto:%s', $this->parameter($organizerName), $organizerEmail),
            sprintf('ATTENDEE;CN=%s;ROLE=REQ-PARTICIPANT:mailto:%s', $this->parameter($attendeeName), $attendeeEmail),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'BEGIN:VALARM',
            'TRIGGER:-PT30M',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . $this->escape('Onboarding-Termin beginnt in 30 Minuten'),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\n", "\r", ';', ','], ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'], trim($value));
    }

    private function parameter(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], trim($value)) . '"';
    }

    /** Faltet lange UTF-8-Zeilen ohne ein Mehrbytezeichen zu zerschneiden. */
    private function fold(string $line): string
    {
        $parts = [];
        $remaining = $line;
        $limit = 73;
        while (strlen($remaining) > $limit) {
            $chunk = mb_strcut($remaining, 0, $limit, 'UTF-8');
            $parts[] = $chunk;
            $remaining = substr($remaining, strlen($chunk));
            $limit = 72;
        }
        $parts[] = $remaining;
        return implode("\r\n ", $parts);
    }
}
