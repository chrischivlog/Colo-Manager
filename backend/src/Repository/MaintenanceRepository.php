<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;

/** Persistiert Wartungsfenster inklusive öffentlicher und kundenspezifischer Sichtbarkeit. */
final class MaintenanceRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'maintenance');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $customerId = null, ?string $status = null): array
    {
        $filter = ['deletedAt' => null];
        if ($customerId !== null) {
            $filter['$or'] = $this->customerScope($customerId);
        }
        if ($status !== null && $status !== '') {
            $filter['status'] = $status;
        }
        return $this->paginate($filter, $page, $limit, ['plannedStart' => -1]);
    }

    /** @param array<string, mixed> $data @param list<string> $customerIds */
    public function create(array $data, string $createdBy, array $customerIds = []): array
    {
        $now = $this->now();
        $document = [
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => $data['status'] ?? 'geplant',
            'impact' => $data['impact'] ?? '',
            'isPublic' => (bool) ($data['isPublic'] ?? false),
            'affectsAllCustomers' => (bool) ($data['affectsAllCustomers'] ?? false),
            'plannedStart' => $this->dateTime($data['plannedStart'], 'plannedStart'),
            'plannedEnd' => !empty($data['plannedEnd']) ? $this->dateTime($data['plannedEnd'], 'plannedEnd') : null,
            'infrastructure' => $this->infrastructure($data),
            'customerIds' => array_map([$this, 'objectId'], $customerIds),
            'createdBy' => $createdBy,
            'updatedBy' => $createdBy,
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ];
        $result = $this->collection->insertOne($document);
        return $this->findById((string) $result->getInsertedId());
    }

    /** @return array<string, mixed> */
    public function findById(string $id): array
    {
        $document = $this->collection->findOne(['_id' => $this->objectId($id), 'deletedAt' => null]);
        if ($document === null) {
            throw new ApiException(404, 'Die Wartung wurde nicht gefunden.', 'maintenance_not_found');
        }
        return $document;
    }

    /** @return array<string, mixed> */
    public function findByIdForCustomer(string $id, string $customerId): array
    {
        $document = $this->collection->findOne([
            '_id' => $this->objectId($id),
            'deletedAt' => null,
            '$or' => $this->customerScope($customerId),
        ]);
        if ($document === null) {
            throw new ApiException(404, 'Die Wartung wurde nicht gefunden oder betrifft Ihr Kundenkonto nicht.', 'maintenance_not_found');
        }
        return $document;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $id, array $data, string $updatedBy): array
    {
        $set = ['updatedAt' => $this->now(), 'updatedBy' => $updatedBy];
        foreach (['title', 'description', 'status', 'impact'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        foreach (['isPublic', 'affectsAllCustomers'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = (bool) $data[$field];
            }
        }
        if (array_key_exists('plannedStart', $data)) {
            $set['plannedStart'] = $this->dateTime($data['plannedStart'], 'plannedStart');
        }
        if (array_key_exists('plannedEnd', $data)) {
            $set['plannedEnd'] = $data['plannedEnd'] === null || $data['plannedEnd'] === '' ? null : $this->dateTime($data['plannedEnd'], 'plannedEnd');
        }
        foreach (['locationId', 'rackId'] as $field) {
            if (array_key_exists($field, $data)) {
                $set['infrastructure.' . $field] = empty($data[$field]) ? null : $this->objectId((string) $data[$field]);
            }
        }
        if (array_key_exists('customerIds', $data)) {
            $set['customerIds'] = array_map([$this, 'objectId'], $data['customerIds']);
        }

        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $set],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Die Wartung wurde nicht gefunden.', 'maintenance_not_found');
        }
        return $this->findById($id);
    }

    public function delete(string $id): void
    {
        $this->softDelete($id);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function listForCustomer(string $customerId, int $page, int $limit): array
    {
        return $this->paginate([
            'deletedAt' => null,
            '$or' => $this->customerScope($customerId),
        ], $page, $limit, ['plannedStart' => -1]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function listUpcomingPublic(int $page, int $limit): array
    {
        $now = new UTCDateTime();
        $filter = [
            'deletedAt' => null,
            'isPublic' => true,
            '$or' => [
                ['status' => 'aktiv'],
                ['status' => 'geplant', '$or' => [
                    ['plannedEnd' => ['$gte' => $now]],
                    ['plannedEnd' => null, 'plannedStart' => ['$gte' => $now]],
                ]],
            ],
        ];
        return $this->paginate($filter, $page, $limit, ['plannedStart' => 1]);
    }

    /** @return list<array<string, mixed>> */
    public function findActivePublic(): array
    {
        return $this->collection->find([
            'deletedAt' => null,
            'isPublic' => true,
            'status' => 'aktiv',
        ], ['sort' => ['plannedStart' => 1]])->toArray();
    }

    /** Zählt ausschließlich noch nicht begonnene, veröffentlichte Wartungsfenster. */
    public function countPlannedPublic(): int
    {
        $now = new UTCDateTime();
        return $this->collection->countDocuments([
            'deletedAt' => null,
            'isPublic' => true,
            'status' => 'geplant',
            '$or' => [
                ['plannedEnd' => ['$gte' => $now]],
                ['plannedEnd' => null, 'plannedStart' => ['$gte' => $now]],
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function findRecentCompletedPublic(int $limit): array
    {
        return $this->collection->find([
            'deletedAt' => null,
            'isPublic' => true,
            'status' => 'abgeschlossen',
        ], ['sort' => ['plannedEnd' => -1, 'updatedAt' => -1], 'limit' => $limit])->toArray();
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerIds' => 1, 'plannedStart' => -1]);
        $this->collection->createIndex(['affectsAllCustomers' => 1, 'status' => 1]);
        $this->collection->createIndex(['isPublic' => 1, 'status' => 1, 'plannedStart' => 1]);
        $this->collection->createIndex(['infrastructure.locationId' => 1]);
        $this->collection->createIndex(['infrastructure.rackId' => 1]);
    }

    /** @return list<array<string, mixed>> */
    private function customerScope(string $customerId): array
    {
        return [
            ['affectsAllCustomers' => true],
            ['customerIds' => ['$in' => [$this->objectId($customerId)]]],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, ObjectId|null> */
    private function infrastructure(array $data): array
    {
        return [
            'locationId' => empty($data['locationId']) ? null : $this->objectId((string) $data['locationId']),
            'rackId' => empty($data['rackId']) ? null : $this->objectId((string) $data['rackId']),
        ];
    }

    /** @param array<string, mixed> $filter @param array<string, int> $sort */
    private function paginate(array $filter, int $page, int $limit, array $sort): array
    {
        $items = $this->collection->find($filter, [
            'sort' => $sort,
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();
        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }
}
