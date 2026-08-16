<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\Database;

/** Persistiert LDAP-/AD-Verbindungen; entschlüsselte Kennwörter verlassen das Backend nie. */
final class DirectoryConfigurationRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'directoryConfigurations');
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->collection->find(['deletedAt' => null], ['sort' => ['name' => 1]])->toArray();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, string $userId): array
    {
        $now = $this->now();
        $result = $this->collection->insertOne($data + [
            'createdByUserId' => $this->objectId($userId),
            'updatedByUserId' => $this->objectId($userId),
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]);
        return $this->findById((string) $result->getInsertedId());
    }

    /** @param array<string, mixed> $data @param list<string> $unset @return array<string, mixed> */
    public function update(string $id, array $data, array $unset, string $userId): array
    {
        $update = ['$set' => $data + ['updatedByUserId' => $this->objectId($userId), 'updatedAt' => $this->now()]];
        if ($unset !== []) {
            $update['$unset'] = array_fill_keys($unset, '');
        }
        $result = $this->collection->updateOne(['_id' => $this->objectId($id), 'deletedAt' => null], $update);
        if ($result->getMatchedCount() !== 1) {
            throw new ApiException(404, 'Die Verzeichnisverbindung wurde nicht gefunden.', 'directory_not_found');
        }
        return $this->findById($id);
    }

    public function delete(string $id): void
    {
        $this->softDelete($id);
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['name' => 1, 'deletedAt' => 1]);
        $this->collection->createIndex(['active' => 1, 'type' => 1]);
    }
}
