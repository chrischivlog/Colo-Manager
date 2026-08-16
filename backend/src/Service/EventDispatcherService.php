<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Event\DomainEventInterface;
use Psr\Log\LoggerInterface;

/** Einfacher Event Dispatcher, der Events derzeit nur loggt (für spätere Erweiterungen) */
final class EventDispatcherService
{
    private const LOG_CHANNEL = 'domain_events';

    /** @var list<callable> */
    private array $listeners = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Registriert einen Listener für ein bestimmtes Event
     */
    public function on(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Versendet ein Domain Event an alle registrierten Listener
     */
    public function dispatch(DomainEventInterface $event): void
    {
        $eventName = $event->getName();
        
        // Immer im Log ausgeben
        $this->logEvent($event);
        
        // Alle Listener für dieses Event aufrufen
        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $listener) {
                try {
                    $listener($event);
                } catch (\Throwable $e) {
                    // Fehler in Listenern sollen den Dispatch nicht blockieren
                    if ($this->logger !== null) {
                        $this->logger->error(sprintf(
                            'Fehler im Event-Listener für %s: %s',
                            $eventName,
                            $e->getMessage(),
                        ), ['exception' => $e]);
                    }
                }
            }
        }
    }

    /**
     * Loggt das Event
     */
    private function logEvent(DomainEventInterface $event): void
    {
        $logEntry = [
            'event' => $event->getName(),
            'payload' => $event->getPayload(),
            'timestamp' => $event->getCreatedAt(),
            'channel' => self::LOG_CHANNEL,
        ];

        if ($this->logger !== null) {
            $this->logger->info(sprintf('[%s] %s', self::LOG_CHANNEL, $event->getName()), $logEntry);
        } else {
            // Fallback-Logging falls kein Logger vorhanden
            error_log(sprintf(
                "[DOMAIN_EVENT] [%s] %s at %s\n",
                $event->getName(),
                json_encode($event->getPayload()),
                $event->getCreatedAt(),
            ));
        }
    }
}
