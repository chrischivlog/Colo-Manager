<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\Operation\FindOneAndUpdate;

/** Persistiert langlebige Vertrags-Snapshots getrennt vom Ticketverlauf. */
final class ContractRepository extends BaseRepository
{
    private readonly \MongoDB\Collection $counters;

    public function __construct(Database $database)
    {
        parent::__construct($database, 'contracts');
        $this->counters = $database->selectCollection('counters');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit, ?string $status, ?string $customerId, ?string $search): array
    {
        $filter = ['deletedAt' => null];
        if ($status !== null) {
            $filter['status'] = $status;
        }
        if ($customerId !== null) {
            $filter['customerId'] = $this->objectId($customerId);
        }
        if ($search !== null && $search !== '') {
            $term = preg_quote($search, '/');
            $filter['$or'] = [
                ['number' => ['$regex' => $term, '$options' => 'i']],
                ['title' => ['$regex' => $term, '$options' => 'i']],
                ['counterparty.company' => ['$regex' => $term, '$options' => 'i']],
                ['counterparty.email' => ['$regex' => $term, '$options' => 'i']],
            ];
        }

        $items = $this->collection->find($filter, [
            'sort' => ['updatedAt' => -1, 'createdAt' => -1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();
        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = $this->now();
        $document = $this->normalize($data) + [
            'number' => $this->nextNumber(),
            'status' => 'draft',
            'createdAt' => $now,
            'updatedAt' => $now,
            'activatedAt' => null,
            'deletedAt' => null,
        ];
        $result = $this->collection->insertOne($document);
        return $this->findById((string) $result->getInsertedId());
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): array
    {
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null],
            ['$set' => $this->normalize($data) + ['updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Vertrag wurde nicht gefunden.', 'contract_not_found');
        }
        return $this->findById($id);
    }

    public function findByLeadOffer(string $ticketId, int $round): ?array
    {
        return $this->collection->findOne([
            'sourceLead.ticketId' => $this->objectId($ticketId),
            'sourceLead.offerRound' => $round,
            'deletedAt' => null,
        ]);
    }

    public function countForCustomer(string $customerId): int
    {
        return $this->collection->countDocuments([
            'customerId' => $this->objectId($customerId),
            'status' => ['$in' => ['draft', 'pending_assignment', 'review', 'awaiting_signature', 'signed', 'onboarding', 'scheduled', 'active']],
            'deletedAt' => null,
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function listForCustomer(string $customerId, int $page, int $limit): array
    {
        $filter = [
            'customerId' => $this->objectId($customerId),
            'status' => ['$in' => ['signed', 'onboarding', 'scheduled', 'active', 'terminated', 'expired']],
            'deletedAt' => null,
        ];
        $items = $this->collection->find($filter, [
            'sort' => ['startDate' => -1, 'updatedAt' => -1],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ])->toArray();
        return ['items' => $items, 'total' => $this->collection->countDocuments($filter), 'page' => $page, 'limit' => $limit];
    }

    /** Findet einen Signaturvorgang ausschließlich über den Hash des öffentlichen Tokens. */
    public function findBySignatureTokenHash(string $tokenHash): array
    {
        $contract = $this->collection->findOne([
            'signature.tokenHash' => $tokenHash,
            'deletedAt' => null,
        ]);
        if ($contract === null) {
            throw new ApiException(404, 'Der Vertragslink wurde nicht gefunden.', 'contract_signature_not_found');
        }
        return $contract;
    }

    /** Aktiviert fällige, bereits vollständig onboardete Verträge beim nächsten Zugriff. */
    public function activateDueContracts(): void
    {
        $this->collection->updateMany(
            ['status' => 'scheduled', 'startDate' => ['$lte' => $this->now()], 'deletedAt' => null],
            ['$set' => ['status' => 'active', 'activatedAt' => $this->now(), 'updatedAt' => $this->now()]],
        );
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['number' => 1], ['unique' => true]);
        $this->collection->createIndex(['status' => 1, 'updatedAt' => -1]);
        $this->collection->createIndex(['customerId' => 1, 'status' => 1]);
        $this->collection->createIndex(['parentContractId' => 1, 'status' => 1]);
        $this->collection->createIndex(['signature.tokenHash' => 1], ['unique' => true, 'sparse' => true]);
        $this->collection->createIndex(
            ['sourceLead.ticketId' => 1, 'sourceLead.offerRound' => 1],
            ['unique' => true, 'sparse' => true],
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        foreach (['customerId', 'locationId', 'parentContractId'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = empty($data[$field]) ? null : $this->objectId((string) $data[$field]);
            }
        }
        foreach (['plannedStartDate', 'startDate', 'endDate'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = empty($data[$field]) ? null : $this->dateTime($data[$field], $field);
            }
        }
        if (isset($data['sourceLead']) && is_array($data['sourceLead'])) {
            foreach (['ticketId', 'inquiryId'] as $field) {
                if (!empty($data['sourceLead'][$field])) {
                    $data['sourceLead'][$field] = $this->objectId((string) $data['sourceLead'][$field]);
                }
            }
        }
        return $data;
    }

    private function nextNumber(): string
    {
        $counter = $this->counters->findOneAndUpdate(
            ['_id' => 'contracts'],
            ['$inc' => ['sequence' => 1]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        return sprintf('V-%s-%05d', gmdate('Y'), (int) ($counter['sequence'] ?? 1));
    }
}
