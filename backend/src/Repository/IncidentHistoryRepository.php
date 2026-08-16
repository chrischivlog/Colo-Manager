<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistiert die Historie von Statusänderungen für Störungen. */
final class IncidentHistoryRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'incident_history');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, string $incidentId, string $createdBy): array
    {
        $now = $this->now();
        
        $document = [
            'incidentId' => $this->objectId($incidentId),
            'oldStatus' => $data['oldStatus'],
            'newStatus' => $data['newStatus'],
            'comment' => $data['comment'] ?? null,
            'createdBy' => $createdBy,
            'createdAt' => $now,
        ];

        $result = $this->collection->insertOne($document);
        return $this->findById((string) $result->getInsertedId());
    }

    /**
     * @return array<string, mixed>
     */
    public function findById(string $id): array
    {
        $document = $this->collection->findOne(['_id' => $this->objectId($id)]);
        if ($document === null) {
            throw new ApiException(404, 'Der Historie-Eintrag wurde nicht gefunden.', 'history_not_found');
        }
        return $document;
    }

    /**
     * Findet alle Historie-Einträge für eine bestimmte Störung
     * @return list<array<string, mixed>>
     */
    public function findByIncidentId(string $incidentId): array
    {
        return $this->collection->find([
            'incidentId' => $this->objectId($incidentId),
        ], [
            'sort' => ['createdAt' => 1], // Chronologische Reihenfolge
        ])->toArray();
    }

    /**
     * Löscht alle Historie-Einträge für eine Störung
     */
    public function deleteByIncidentId(string $incidentId): void
    {
        $this->collection->deleteMany(['incidentId' => $this->objectId($incidentId)]);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['incidentId' => 1, 'createdAt' => 1]);
        $this->collection->createIndex(['createdAt' => -1]);
    }
}
