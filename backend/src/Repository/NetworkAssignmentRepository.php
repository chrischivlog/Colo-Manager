<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\Regex;
use MongoDB\Database;

/**
 * Persistiert die vom ISP bereitgestellten IP-Netze eines Kunden. Kunden- und
 * Standortfilter werden direkt in MongoDB angewendet, damit niemals fremde
 * Adressdaten erst nachträglich in der Service-Schicht aussortiert werden.
 */
final class NetworkAssignmentRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'networkAssignments');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, string $customerId, ?string $locationId = null, ?string $status = null): array
    {
        $filter = ['customerId' => $this->objectId($customerId), 'deletedAt' => null];
        if ($locationId !== null && $locationId !== '') {
            $filter['locationId'] = $this->objectId($locationId);
        }
        if ($status !== null && $status !== '') {
            $filter['status'] = $status;
        }
        $items = $this->collection->find($filter, [
            'sort' => ['status' => 1, 'addressFamily' => 1, 'cidr' => 1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /**
     * Sucht mandantenübergreifend nach Netz, Gateway, DNS-/Reverse-DNS-Wert,
     * ISP oder Referenz. Der Endpunkt bleibt ausschließlich Technik und Admins
     * vorbehalten und liefert bewusst höchstens wenige Treffer für die Kopfsuche.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $pattern = new Regex(preg_quote(trim($query), '/'), 'i');
        return $this->collection->find([
            'deletedAt' => null,
            '$or' => array_map(static fn (string $field): array => [$field => $pattern], [
                'label', 'ispName', 'serviceReference', 'cidr', 'gateway', 'reverseDns', 'dnsServers',
            ]),
        ], [
            'sort' => ['updatedAt' => -1],
            'limit' => max(1, min($limit, 20)),
        ])->toArray();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, string $customerId, string $createdBy): array
    {
        $now = $this->now();
        $document = $data + [
            'customerId' => $this->objectId($customerId),
            'locationId' => $this->objectId((string) $data['locationId']),
            'createdBy' => $this->objectId($createdBy),
            'updatedBy' => $this->objectId($createdBy),
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ];
        $document['customerId'] = $this->objectId($customerId);
        $document['locationId'] = $this->objectId((string) $data['locationId']);
        $result = $this->collection->insertOne($document);

        return $this->findForCustomer((string) $result->getInsertedId(), $customerId);
    }

    /** @return array<string, mixed> */
    public function findForCustomer(string $id, ?string $customerId): array
    {
        $filter = ['_id' => $this->objectId($id), 'deletedAt' => null];
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        $document = $this->collection->findOne($filter);
        if ($document === null) {
            throw new ApiException(404, 'Die Netzwerkzuweisung wurde nicht gefunden.', 'network_assignment_not_found');
        }
        return $document;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $id, array $data, string $updatedBy): array
    {
        if (isset($data['customerId'])) {
            $data['customerId'] = $this->objectId((string) $data['customerId']);
        }
        if (isset($data['locationId'])) {
            $data['locationId'] = $this->objectId((string) $data['locationId']);
        }
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedBy' => $this->objectId($updatedBy), 'updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Die Netzwerkzuweisung wurde nicht gefunden.', 'network_assignment_not_found');
        }

        return $this->findForCustomer($id, null);
    }

    public function delete(string $id, string $updatedBy): void
    {
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => ['deletedAt' => $this->now(), 'updatedAt' => $this->now(), 'updatedBy' => $this->objectId($updatedBy)]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Die Netzwerkzuweisung wurde nicht gefunden.', 'network_assignment_not_found');
        }
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['customerId' => 1, 'cidr' => 1, 'deletedAt' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'locationId' => 1, 'status' => 1]);
    }
}
