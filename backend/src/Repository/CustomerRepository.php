<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistenzzugriff auf Kundenstammdaten mit Suche und Pagination. */
final class CustomerRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'customers');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $search = null): array
    {
        $filter = ['deletedAt' => null];
        if ($search !== null && $search !== '') {
            $escaped = preg_quote($search, '/');
            $filter['$or'] = [
                ['name' => ['$regex' => $escaped, '$options' => 'i']],
                ['customerNumber' => ['$regex' => $escaped, '$options' => 'i']],
            ];
        }

        $items = $this->collection->find($filter, [
            'sort' => ['name' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = $this->now();
        $data = $this->normalizeReferences($data);
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
        $data = $this->normalizeReferences($data);
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Kunde wurde nicht gefunden.', 'customer_not_found');
        }

        return $this->findById($id);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerNumber' => 1], ['unique' => true]);
        $this->collection->createIndex(['status' => 1, 'name' => 1]);
    }

    /** Zählt aktive Kunden, die einen Tarif oder ein Bandbreitenprofil verwenden. */
    public function countByReference(string $field, string $id): int
    {
        if (!in_array($field, ['servicePlanId', 'bandwidthOptionId'], true)) {
            throw new \InvalidArgumentException('Unbekanntes Referenzfeld.');
        }
        return $this->collection->countDocuments([$field => $this->objectId($id), 'deletedAt' => null]);
    }

    /** @return list<string> Liefert die explizit zugewiesenen Datacenter-Standorte. */
    public function locationIdsForCustomer(string $customerId): array
    {
        $customer = $this->findById($customerId);
        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            is_array($customer['locationIds'] ?? null) ? $customer['locationIds'] : [],
        ));
    }

    /** Ergänzt eine Standortzuweisung idempotent, zum Beispiel bei Inline-Anlage. */
    public function addLocationReference(string $customerId, string $locationId): void
    {
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($customerId), 'deletedAt' => null],
            [
                '$addToSet' => ['locationIds' => $this->objectId($locationId)],
                '$set' => ['updatedAt' => $this->now()],
            ],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Kunde wurde nicht gefunden.', 'customer_not_found');
        }
    }

    /** Entfernt einen gelöschten Standort aus allen Kundenzuordnungen. */
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

    /** Wandelt die aus JSON kommenden Referenz-IDs vor dem Speichern in BSON ObjectIds um. */
    private function normalizeReferences(array $data): array
    {
        foreach (['servicePlanId', 'bandwidthOptionId', 'assignedTechnicianUserId', 'assignedSalesUserId'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] === null || $data[$field] === ''
                    ? null
                    : $this->objectId((string) $data[$field]);
            }
        }
        if (array_key_exists('locationIds', $data)) {
            $data['locationIds'] = array_values(array_map(
                fn (mixed $id): \MongoDB\BSON\ObjectId => $this->objectId((string) $id),
                is_array($data['locationIds']) ? $data['locationIds'] : [],
            ));
        }
        return $data;
    }
}
