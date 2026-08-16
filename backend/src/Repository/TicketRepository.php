<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Operation\FindOneAndUpdate;

/** Persistiert normale Support- und Lead-Tickets in einer gemeinsamen Queue. */
final class TicketRepository extends BaseRepository
{
    private readonly Collection $counters;

    public function __construct(Database $database)
    {
        parent::__construct($database, 'tickets');
        $this->counters = $database->selectCollection('counters');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(
        int $page,
        int $limit,
        ?string $customerId,
        ?string $type,
        ?string $status,
        ?string $category,
        ?string $search,
        ?string $staffQueueUserId = null,
    ): array
    {
        $filter = ['deletedAt' => null];
        $andConditions = [];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
            // Interne Mitarbeitertickets dürfen selbst bei hinterlegtem Kunden
            // niemals in dessen Portalqueue erscheinen.
            $filter['visibility'] = ['$ne' => 'internal'];
        }
        if ($staffQueueUserId !== null) {
            // Mitarbeiter arbeiten ausschließlich in ihrer eigenen Queue und
            // in der gemeinsamen Annahmequeue. Fremd zugewiesene Tickets
            // werden bereits in MongoDB ausgefiltert, nicht erst im Browser.
            $andConditions[] = ['$or' => [
                ['assignedToUserId' => $this->objectId($staffQueueUserId)],
                ['assignedToUserId' => null],
            ]];
            // Ohne expliziten Statusfilter ist die operative Queue stets offen.
            // Das geschlossene Archiv bleibt über status=closed erreichbar.
            if ($status === null) {
                $filter['status'] = ['$in' => ['open', 'in_progress', 'waiting_customer']];
            }
        }
        if ($type !== null) {
            $filter['type'] = $type;
        }
        if ($status !== null) {
            $filter['status'] = $status;
        }
        if ($category === 'other') {
            // Vor Einführung der Kategorien angelegte Tickets werden fachlich
            // als „Sonstiges“ behandelt und bleiben dadurch filterbar.
            $andConditions[] = ['$or' => [
                ['category' => 'other'],
                ['category' => ['$exists' => false]],
                ['category' => null],
            ]];
        } elseif ($category !== null) {
            $filter['category'] = $category;
        }
        if ($search !== null && $search !== '') {
            $term = preg_quote($search, '/');
            $andConditions[] = ['$or' => [
                ['number' => ['$regex' => $term, '$options' => 'i']],
                ['subject' => ['$regex' => $term, '$options' => 'i']],
                ['requester.email' => ['$regex' => $term, '$options' => 'i']],
                ['requester.company' => ['$regex' => $term, '$options' => 'i']],
            ]];
        }
        if ($andConditions !== []) {
            $filter['$and'] = $andConditions;
        }

        $items = $this->collection->find($filter, [
            // Interne Notizen dürfen die Reihenfolge im Kundenportal nicht
            // beeinflussen. Mitarbeiter sehen weiterhin die Gesamtaktivität.
            'sort' => $customerId !== null
                ? ['lastPublicMessageAt' => -1, 'createdAt' => -1]
                : ['lastMessageAt' => -1, 'createdAt' => -1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = $this->now();
        $number = $this->nextNumber();
        $document = $data + [
            'number' => $number,
            'type' => 'normal',
            'visibility' => 'customer',
            'category' => 'other',
            'status' => 'open',
            'priority' => 'normal',
            'messageCount' => 0,
            'publicMessageCount' => 0,
            'attachmentCount' => 0,
            'publicAttachmentCount' => 0,
            'lastMessageAt' => $now,
            'lastPublicMessageAt' => $now,
            'createdAt' => $now,
            'updatedAt' => $now,
            'closedAt' => null,
            'deletedAt' => null,
        ];
        $document['customerId'] = empty($document['customerId']) ? null : $this->objectId((string) $document['customerId']);
        if (!empty($document['inquiryId'])) {
            $document['inquiryId'] = $this->objectId((string) $document['inquiryId']);
        }
        if (!empty($document['assignedToUserId'])) {
            $document['assignedToUserId'] = $this->objectId((string) $document['assignedToUserId']);
        }

        $result = $this->collection->insertOne($document);
        return $this->findById((string) $result->getInsertedId());
    }

    /** Liefert ein Ticket nur dann, wenn es dem angemeldeten Kunden gehört. */
    public function findByIdForCustomer(string $id, string $customerId): array
    {
        $ticket = $this->collection->findOne([
            '_id' => $this->objectId($id),
            'customerId' => $this->objectId($customerId),
            'visibility' => ['$ne' => 'internal'],
            'deletedAt' => null,
        ]);
        if ($ticket === null) {
            throw new ApiException(404, 'Das Ticket wurde nicht gefunden.', 'ticket_not_found');
        }
        return $ticket;
    }

    /**
     * Liefert Mitarbeitern nur eigene oder noch nicht übernommene Tickets.
     * Geschlossene Tickets bleiben für den expliziten Archivaufruf erreichbar.
     */
    public function findByIdForStaffQueue(string $id, string $userId): array
    {
        $ticket = $this->collection->findOne([
            '_id' => $this->objectId($id),
            '$or' => [
                ['assignedToUserId' => $this->objectId($userId)],
                ['assignedToUserId' => null],
            ],
            'deletedAt' => null,
        ]);
        if ($ticket === null) {
            throw new ApiException(404, 'Das Ticket wurde nicht gefunden oder ist einem anderen Mitarbeiter zugewiesen.', 'ticket_not_found');
        }
        return $ticket;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): array
    {
        $now = $this->now();
        if (array_key_exists('assignedToUserId', $data)) {
            $data['assignedToUserId'] = empty($data['assignedToUserId']) ? null : $this->objectId((string) $data['assignedToUserId']);
        }
        if (($data['status'] ?? null) === 'closed') {
            $data['closedAt'] = $now;
        } elseif (isset($data['status'])) {
            $data['closedAt'] = null;
        }
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedAt' => $now]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Das Ticket wurde nicht gefunden.', 'ticket_not_found');
        }
        return $this->findById($id);
    }

    /** Aktualisiert die Zähler, sobald eine neue Nachricht gespeichert wurde. */
    public function registerMessage(string $id, bool $internal = false): void
    {
        $now = $this->now();
        // Beim ersten Schreiben nach dem Update werden Legacy-Zähler aus dem
        // bisherigen Gesamtzähler übernommen. Interne Notizen erhöhen nur den
        // internen Gesamtzähler, nicht die im Kundenportal sichtbare Anzahl.
        $publicMessageCount = ['$ifNull' => ['$publicMessageCount', ['$ifNull' => ['$messageCount', 0]]]];
        if (!$internal) {
            $publicMessageCount = ['$add' => [$publicMessageCount, 1]];
        }
        $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            [['$set' => [
                'messageCount' => ['$add' => [['$ifNull' => ['$messageCount', 0]], 1]],
                'publicMessageCount' => $publicMessageCount,
                'lastMessageAt' => $now,
                'lastPublicMessageAt' => $internal ? ['$ifNull' => ['$lastPublicMessageAt', '$lastMessageAt']] : $now,
                'updatedAt' => $now,
            ]]],
        );
    }

    public function registerAttachment(string $id, bool $internal = false): void
    {
        $publicAttachmentCount = ['$ifNull' => ['$publicAttachmentCount', ['$ifNull' => ['$attachmentCount', 0]]]];
        if (!$internal) {
            $publicAttachmentCount = ['$add' => [$publicAttachmentCount, 1]];
        }
        $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            [['$set' => [
                'attachmentCount' => ['$add' => [['$ifNull' => ['$attachmentCount', 0]], 1]],
                'publicAttachmentCount' => $publicAttachmentCount,
                'updatedAt' => $this->now(),
            ]]],
        );
    }

    public function findByInquiryId(string $inquiryId): ?array
    {
        return $this->collection->findOne(['inquiryId' => $this->objectId($inquiryId), 'deletedAt' => null]);
    }

    /** Findet ein öffentlich freigegebenes Lead-Angebot ausschließlich über dessen Token-Hash. */
    public function findByLeadOfferTokenHash(string $tokenHash): array
    {
        $ticket = $this->collection->findOne([
            'type' => 'lead',
            'category' => 'lead',
            'leadProcess.offer.tokenHash' => $tokenHash,
            'deletedAt' => null,
        ]);
        if ($ticket === null) {
            throw new ApiException(404, 'Das Angebot wurde nicht gefunden.', 'lead_offer_not_found');
        }
        return $ticket;
    }

    /** Findet eine noch im Lead gespeicherte Portal-Einladung über den Token-Hash. */
    public function findByAccountInvitationTokenHash(string $tokenHash): array
    {
        $ticket = $this->collection->findOne([
            'type' => 'lead',
            'leadProcess.onboarding.invitation.tokenHash' => $tokenHash,
            'deletedAt' => null,
        ]);
        if ($ticket === null) {
            throw new ApiException(404, 'Die Einladung wurde nicht gefunden.', 'account_invitation_not_found');
        }
        return $ticket;
    }

    /**
     * Reserviert genau eine fällige Onboarding-Erinnerung atomar. Ein nach
     * einem Worker-Abbruch länger als 15 Minuten blockierter Datensatz darf
     * erneut übernommen werden.
     *
     * @return array<string, mixed>|null
     */
    public function claimDueOnboardingReminder(): ?array
    {
        $now = $this->now();
        $stale = new \MongoDB\BSON\UTCDateTime(((int) floor(microtime(true) * 1000)) - (15 * 60 * 1000));
        $ticket = $this->collection->findOneAndUpdate(
            [
                'type' => 'lead',
                'category' => 'lead',
                'deletedAt' => null,
                'leadProcess.onboarding.appointment.status' => 'scheduled',
                'leadProcess.onboarding.appointment.reminder.dueAt' => ['$lte' => $now],
                '$or' => [
                    ['leadProcess.onboarding.appointment.reminder.status' => 'pending'],
                    [
                        'leadProcess.onboarding.appointment.reminder.status' => 'processing',
                        'leadProcess.onboarding.appointment.reminder.claimedAt' => ['$lte' => $stale],
                    ],
                ],
            ],
            [
                '$set' => [
                    'leadProcess.onboarding.appointment.reminder.status' => 'processing',
                    'leadProcess.onboarding.appointment.reminder.claimedAt' => $now,
                    'updatedAt' => $now,
                ],
                '$inc' => ['leadProcess.onboarding.appointment.reminder.attempts' => 1],
            ],
            [
                'sort' => ['leadProcess.onboarding.appointment.reminder.dueAt' => 1],
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ],
        );
        return $ticket === null ? null : (array) $ticket;
    }

    public function ensureIndexes(): void
    {
        // Vor Einführung der exklusiven Lead-Kategorie wurden öffentliche
        // Erstanfragen als „sales“ gespeichert. Die Migration ist idempotent
        // und korrigiert beim Start auch Legacy-Dokumente ohne Kategorie.
        $this->collection->updateMany(
            ['type' => 'lead', 'category' => ['$ne' => 'lead'], 'deletedAt' => null],
            ['$set' => ['category' => 'lead', 'updatedAt' => $this->now()]],
        );
        $this->collection->createIndex(['number' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'lastMessageAt' => -1]);
        $this->collection->createIndex(['type' => 1, 'status' => 1, 'lastMessageAt' => -1]);
        $this->collection->createIndex(['category' => 1, 'status' => 1, 'lastMessageAt' => -1]);
        $this->collection->createIndex(['assignedToUserId' => 1, 'status' => 1, 'lastMessageAt' => -1]);
        $this->collection->createIndex(['inquiryId' => 1], ['unique' => true, 'sparse' => true]);
        $this->collection->createIndex(['leadProcess.offer.tokenHash' => 1], ['unique' => true, 'sparse' => true]);
        $this->collection->createIndex(['leadProcess.onboarding.invitation.tokenHash' => 1], ['unique' => true, 'sparse' => true]);
        $this->collection->createIndex([
            'leadProcess.onboarding.appointment.reminder.status' => 1,
            'leadProcess.onboarding.appointment.reminder.dueAt' => 1,
        ]);
    }

    private function nextNumber(): string
    {
        $counter = $this->counters->findOneAndUpdate(
            ['_id' => 'tickets'],
            ['$inc' => ['sequence' => 1]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        return sprintf('CM-%06d', (int) ($counter['sequence'] ?? 1));
    }
}
