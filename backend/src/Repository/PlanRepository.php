<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** MongoDB-Zugriff auf zentral gepflegte Colocation-Tarife. */
final class PlanRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'plans');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $status): array
    {
        $filter = ['deletedAt' => null];
        if ($status !== null) {
            $filter['status'] = $status;
        }
        $items = $this->collection->find($filter, [
            'sort' => ['monthlyPrice' => 1, 'name' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $data = $this->normalizeLocationReferences($data);
        $now = $this->now();
        $result = $this->collection->insertOne($data + [
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]);

        return $this->findById((string) $result->getInsertedId());
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): array
    {
        $data = $this->normalizeLocationReferences($data);
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Tarif wurde nicht gefunden.', 'plan_not_found');
        }

        return $this->findById($id);
    }

    /** Entfernt einen gelöschten Standort aus allen Tarifverfügbarkeiten. */
    public function removeLocationReferenceFromAll(string $locationId): void
    {
        $this->collection->updateMany(
            ['locationIds' => $this->objectId($locationId), 'deletedAt' => null],
            [
                '$pull' => ['locationIds' => $this->objectId($locationId)],
                '$set' => ['updatedAt' => $this->now()],
            ],
        );
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['code' => 1], ['unique' => true]);
        $this->collection->createIndex(['status' => 1, 'monthlyPrice' => 1]);
        $this->collection->createIndex(['locationIds' => 1, 'status' => 1]);
    }

    /** @param array<string, mixed> $data */
    private function normalizeLocationReferences(array $data): array
    {
        if (array_key_exists('locationIds', $data)) {
            $data['locationIds'] = array_values(array_map(
                fn (mixed $id): \MongoDB\BSON\ObjectId => $this->objectId((string) $id),
                is_array($data['locationIds']) ? $data['locationIds'] : [],
            ));
        }
        return $data;
    }
}
