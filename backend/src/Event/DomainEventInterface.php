<?php

declare(strict_types=1);

namespace ColoManager\Event;

/** Marker-Interface für alle Domain Events im System. */
interface DomainEventInterface
{
    /** Gibt den Namen des Events zurück */
    public function getName(): string;
    
    /** Gibt die Daten des Events zurück */
    public function getPayload(): array;
    
    /** Gibt den Zeitpunkt des Events zurück */
    public function getCreatedAt(): string;
}
