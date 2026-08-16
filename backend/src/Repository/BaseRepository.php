<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use DateTimeImmutable;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use Throwable;

/** Gemeinsame MongoDB-Helfer für IDs, Zeitstempel und Soft Deletes. */
abstract class BaseRepository
{
    protected readonly Collection $collection;

    public function __construct(Database $database, string $collectionName)
    {
        $this->collection = $database->selectCollection($collectionName);
    }

    protected function objectId(string $id): ObjectId
    {
        try {
            return new ObjectId($id);
        } catch (Throwable) {
            throw new ApiException(400, 'Die angegebene ID ist ungültig.', 'invalid_id');
        }
    }

    protected function now(): UTCDateTime
    {
        return new UTCDateTime();
    }

    /**
     * Wandelt API-Zeitwerte zentral in einen MongoDB-Zeitstempel um.
     * Dadurch akzeptieren alle Repositories ISO-8601-Werte aus dem Frontend,
     * ohne Strings fälschlich als Millisekunden zu interpretieren.
     */
    protected function dateTime(mixed $value, string $field): UTCDateTime
    {
        try {
            if ($value instanceof UTCDateTime) {
                return $value;
            }
            if ($value instanceof DateTimeInterface) {
                return new UTCDateTime($value);
            }
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException();
            }

            return new UTCDateTime(new DateTimeImmutable($value));
        } catch (\Throwable) {
            throw new ApiException(422, sprintf('Ungültiges Datumsformat für %s.', $field), 'validation_failed', [
                'field' => $field,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function findById(string $id): array
    {
        $document = $this->collection->findOne(['_id' => $this->objectId($id), 'deletedAt' => null]);
        if ($document === null) {
            throw new ApiException(404, 'Der Datensatz wurde nicht gefunden.', 'resource_not_found');
        }

        return $document;
    }

    /** @param array<string, mixed> $filter */
    public function softDelete(string $id, array $filter = []): void
    {
        $result = $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'deletedAt' => null] + $filter,
            ['$set' => ['deletedAt' => $this->now(), 'updatedAt' => $this->now()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException(404, 'Der Datensatz wurde nicht gefunden.', 'resource_not_found');
        }
    }
}
