<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistiert physische Colocation-Racks und verankert die Mandantentrennung in jeder Abfrage. */
final class RackRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'racks');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $customerId, ?string $locationId): array
    {
        $filter = ['deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        if ($locationId !== null) {
            $filter['locationId'] = $this->objectId($locationId);
        }

        $items = $this->collection->find($filter, [
            'sort' => ['code' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $customerId): array
    {
        $now = $this->now();
        $data['customerId'] = $this->objectId($customerId);
        $data['locationId'] = $this->objectId((string) $data['locationId']);
        $result = $this->collection->insertOne($data + [
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]);

        return $this->findByIdForCustomer((string) $result->getInsertedId(), $customerId);
    }

    /** @return array<string, mixed> */
    public function findByIdForCustomer(string $id, ?string $customerId): array
    {
        $filter = ['_id' => $this->objectId($id), 'deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        $document = $this->collection->findOne($filter);
        if ($document === null) {
            throw new ApiException(404, 'Das Rack wurde nicht gefunden.', 'rack_not_found');
        }

        return $document;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data, ?string $customerId): array
    {
        $filter = ['_id' => $this->objectId($id), 'deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        if (isset($data['locationId'])) {
            $data['locationId'] = $this->objectId((string) $data['locationId']);
        }
        $result = $this->collection->updateOne($filter, ['$set' => $data + ['updatedAt' => $this->now()]]);
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Das Rack wurde nicht gefunden.', 'rack_not_found');
        }

        return $this->findByIdForCustomer($id, $customerId);
    }

    /**
     * Speichert die vom Kunden erteilte Remote-Hands-Freigabe als eigenes
     * Audit-Snapshot am Rack. Ein Entzug bleibt dadurch ebenso nachvollziehbar
     * wie die erstmalige Freigabe.
     *
     * @return array<string, mixed>
     */
    public function setRemoteHandsAccess(string $id, string $customerId, bool $enabled, string $userId): array
    {
        $now = $this->now();
        $result = $this->collection->updateOne(
            [
                '_id' => $this->objectId($id),
                'customerId' => $this->objectId($customerId),
                'deletedAt' => null,
            ],
            ['$set' => [
                'remoteHandsAccess' => [
                    'enabled' => $enabled,
                    'changedAt' => $now,
                    'changedByUserId' => $this->objectId($userId),
                ],
                'updatedAt' => $now,
            ]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Das Rack wurde nicht gefunden.', 'rack_not_found');
        }

        return $this->findByIdForCustomer($id, $customerId);
    }

    public function deleteForCustomer(string $id, ?string $customerId): void
    {
        $filter = $customerId === null ? [] : ['customerId' => $this->objectId($customerId)];
        $this->softDelete($id, $filter);
    }

    public function countForCustomer(string $customerId): int
    {
        return $this->collection->countDocuments(['customerId' => $this->objectId($customerId), 'deletedAt' => null]);
    }

    public function countForLocation(string $locationId): int
    {
        return $this->collection->countDocuments(['locationId' => $this->objectId($locationId), 'deletedAt' => null]);
    }

    public function countForCustomerAndLocation(string $customerId, string $locationId): int
    {
        return $this->collection->countDocuments([
            'customerId' => $this->objectId($customerId),
            'locationId' => $this->objectId($locationId),
            'deletedAt' => null,
        ]);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerId' => 1, 'code' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'locationId' => 1, 'status' => 1]);
    }
}
