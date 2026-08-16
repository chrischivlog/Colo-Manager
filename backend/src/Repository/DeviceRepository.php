<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** MongoDB-Zugriff auf Server und sonstige physische oder logische Geräte. */
final class DeviceRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'devices');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $customerId, ?string $locationId, ?string $type, ?string $status): array
    {
        $filter = ['deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        if ($locationId !== null && $locationId !== '') {
            $filter['locationId'] = $this->objectId($locationId);
        }
        if ($type !== null && $type !== '') {
            $filter['type'] = $type;
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
            'locationId' => $this->objectId((string) $data['locationId']),
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ];
        $document['customerId'] = $this->objectId($customerId);
        $document['locationId'] = $this->objectId((string) $data['locationId']);
        if (isset($data['rackId']) && $data['rackId'] !== null && $data['rackId'] !== '') {
            $document['rackId'] = $this->objectId((string) $data['rackId']);
        }
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
            throw new ApiException(404, 'Das Gerät wurde nicht gefunden.', 'device_not_found');
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
        if (isset($data['rackId']) && $data['rackId'] !== null && $data['rackId'] !== '') {
            $data['rackId'] = $this->objectId((string) $data['rackId']);
        }
        $result = $this->collection->updateOne($filter, ['$set' => $data + ['updatedAt' => $this->now()]]);
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Das Gerät wurde nicht gefunden.', 'device_not_found');
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

    public function countForRack(string $rackId): int
    {
        return $this->collection->countDocuments(['rackId' => $this->objectId($rackId), 'deletedAt' => null]);
    }

    /** @return list<array<string, mixed>> */
    public function listForRack(string $rackId): array
    {
        return $this->collection->find(
            ['rackId' => $this->objectId($rackId), 'deletedAt' => null],
            ['sort' => ['rackUnit' => -1, 'name' => 1]],
        )->toArray();
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerId' => 1, 'assetTag' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'locationId' => 1, 'status' => 1]);
        $this->collection->createIndex(['rackId' => 1, 'rackUnit' => 1]);
    }
}
