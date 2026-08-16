<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Speichert den chronologischen Nachrichtenverlauf eines Tickets. */
final class TicketMessageRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'ticket_messages');
    }

    /** @return list<array<string, mixed>> */
    public function listForTicket(string $ticketId, bool $includeInternal = true): array
    {
        $filter = ['ticketId' => $this->objectId($ticketId), 'deletedAt' => null];
        if (!$includeInternal) {
            // Legacy-Nachrichten ohne Kennzeichnung gelten als extern sichtbar.
            $filter['internal'] = ['$ne' => true];
        }
        return $this->collection->find(
            $filter,
            ['sort' => ['createdAt' => 1], 'limit' => 500],
        )->toArray();
    }

    /** Prüft die Sichtbarkeit eines Anhangs über seine zugehörige Nachricht. */
    public function isInternalForTicket(string $messageId, string $ticketId): bool
    {
        $message = $this->collection->findOne([
            '_id' => $this->objectId($messageId),
            'ticketId' => $this->objectId($ticketId),
            'deletedAt' => null,
        ]);
        if ($message === null) {
            throw new ApiException(404, 'Die Ticketnachricht wurde nicht gefunden.', 'ticket_message_not_found');
        }
        return ($message['internal'] ?? false) === true;
    }

    /** @param array<string, mixed> $data */
    public function create(string $ticketId, array $data): array
    {
        $now = $this->now();
        $result = $this->collection->insertOne($data + [
            'ticketId' => $this->objectId($ticketId),
            'attachments' => [],
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]);
        return $this->findById((string) $result->getInsertedId());
    }

    /** @param array<string, mixed> $attachment */
    public function addAttachment(string $messageId, array $attachment): array
    {
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($messageId), 'deletedAt' => null],
            ['$push' => ['attachments' => $attachment], '$set' => ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Die Ticketnachricht wurde nicht gefunden.', 'ticket_message_not_found');
        }
        return $this->findById($messageId);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['ticketId' => 1, 'createdAt' => 1]);
    }
}
