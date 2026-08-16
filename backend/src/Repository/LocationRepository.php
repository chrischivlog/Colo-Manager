<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;

/** MongoDB-Zugriff auf Standorte; Kundenfilter werden direkt in Queries verankert. */
final class LocationRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'locations');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $customerId, ?string $status = null, array $assignedLocationIds = []): array
    {
        $filter = ['deletedAt' => null];
        if ($customerId !== null) {
            // Standorte sind Datacenter-Ressourcen und werden Kunden explizit
            // über customers.locationIds zugewiesen (Viele-zu-viele).
            $filter['_id'] = ['$in' => array_map($this->objectId(...), $assignedLocationIds)];
        }
        if ($status !== null && $status !== '') {
            $filter['status'] = $status;
        }

        $items = $this->collection->find($filter, [
            'sort' => ['name' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $customerId): array
    {
        $now = $this->now();
        $document = $data + [
            'customerId' => $this->objectId($customerId),
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ];
        $result = $this->collection->insertOne($document);

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
            throw new ApiException(404, 'Der Standort wurde nicht gefunden.', 'location_not_found');
        }
        return $document;
    }

    /** @param list<string> $assignedLocationIds */
    public function findAssignedToCustomer(string $id, string $customerId, array $assignedLocationIds): array
    {
        if (!in_array($id, $assignedLocationIds, true)) {
            throw new ApiException(404, 'Der Standort ist diesem Kunden nicht zugewiesen.', 'location_not_assigned');
        }
        return $this->findByIdForCustomer($id, null);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data, ?string $customerId): array
    {
        $filter = ['_id' => $this->objectId($id), 'deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        $result = $this->collection->updateOne($filter, ['$set' => $data + ['updatedAt' => $this->now()]]);
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Standort wurde nicht gefunden.', 'location_not_found');
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

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerId' => 1, 'code' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'status' => 1]);
    }
}
