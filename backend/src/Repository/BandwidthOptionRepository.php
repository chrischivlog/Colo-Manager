<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistenz für separat buchbare Bandbreitenprofile. */
final class BandwidthOptionRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'bandwidthOptions');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $status): array
    {
        $filter = ['deletedAt' => null];
        if ($status !== null) {
            $filter['status'] = $status;
        }
        $items = $this->collection->find($filter, [
            'sort' => ['committedMbps' => 1, 'name' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
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
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Das Bandbreitenprofil wurde nicht gefunden.', 'bandwidth_option_not_found');
        }

        return $this->findById($id);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['code' => 1], ['unique' => true]);
        $this->collection->createIndex(['status' => 1, 'committedMbps' => 1]);
    }
}
