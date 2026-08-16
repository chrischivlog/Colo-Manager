<?php

declare(strict_types=1);

namespace ColoManager\Event;

use DateTime;

/** Domain Event für kritische Störungen */
final readonly class IncidentMarkedCritical implements DomainEventInterface
{
    public function __construct(
        private string $incidentId,
        private string $title,
        private bool $isCritical,
        private string $triggeredBy,
        private DateTime $createdAt,
    ) {
    }

    public function getName(): string
    {
        return 'IncidentMarkedCritical';
    }

    public function getPayload(): array
    {
        return [
            'incidentId' => $this->incidentId,
            'title' => $this->title,
            'isCritical' => $this->isCritical,
            'triggeredBy' => $this->triggeredBy,
        ];
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt->format(DATE_ATOM);
    }

    /**
     * Gibt die Incident-ID zurück
     */
    public function getIncidentId(): string
    {
        return $this->incidentId;
    }

    /**
     * Gibt den Titel der Störung zurück
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Gibt zurück ob die Störung kritisch ist
     */
    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    /**
     * Gibt den Auslöser zurück
     */
    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }
}
