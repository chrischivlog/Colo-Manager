<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistiert öffentliche Produktanfragen und den internen Bearbeitungsstatus. */
final class InquiryRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'inquiries');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $status): array
    {
        $filter = ['deletedAt' => null];
        if ($status !== null) {
            $filter['status'] = $status;
        }
        $items = $this->collection->find($filter, [
            'sort' => ['createdAt' => -1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();

        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = $this->now();
        $data['planId'] = $this->objectId((string) $data['planId']);
        if (!empty($data['bandwidthOptionId'])) {
            $data['bandwidthOptionId'] = $this->objectId((string) $data['bandwidthOptionId']);
        } else {
            $data['bandwidthOptionId'] = null;
        }
        $result = $this->collection->insertOne($data + [
            'status' => 'new',
            'source' => 'public_offers_page',
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]);

        return $this->findById((string) $result->getInsertedId());
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): array
    {
        if (array_key_exists('ticketId', $data) && $data['ticketId'] !== null) {
            $data['ticketId'] = $this->objectId((string) $data['ticketId']);
        }
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $data + ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Die Anfrage wurde nicht gefunden.', 'inquiry_not_found');
        }

        return $this->findById($id);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['status' => 1, 'createdAt' => -1]);
        $this->collection->createIndex(['email' => 1, 'createdAt' => -1]);
    }
}
