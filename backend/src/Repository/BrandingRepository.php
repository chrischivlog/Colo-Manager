<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * Persistiert die mandantenweite Darstellung der Plattform in genau einem
 * MongoDB-Dokument. So gelten Name, Farbe, Logo und die optionale externe
 * Startseiten-Videoquelle für alle Benutzerkonten.
 */
final class BrandingRepository
{
    private const DOCUMENT_ID = 'platform-branding';

    private readonly Collection $collection;

    public function __construct(Database $database)
    {
        $this->collection = $database->selectCollection('platform_settings');
    }

    /** @return array<string, mixed>|null */
    public function find(): ?array
    {
        $document = $this->collection->findOne(['_id' => self::DOCUMENT_ID]);
        return $document === null ? null : (array) $document;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function saveSettings(array $settings, string $updatedBy): array
    {
        $now = new UTCDateTime();
        $this->collection->updateOne(
            ['_id' => self::DOCUMENT_ID],
            [
                '$set' => $settings + ['updatedAt' => $now, 'updatedBy' => $updatedBy],
                '$setOnInsert' => ['createdAt' => $now],
            ],
            ['upsert' => true],
        );

        return $this->find() ?? [];
    }

    /** @param array{id: string, name: string, mimeType: string, size: int} $logo @return array<string, mixed> */
    public function setLogo(array $logo, string $updatedBy): array
    {
        return $this->saveSettings(['logo' => $logo], $updatedBy);
    }

    /** @return array<string, mixed> */
    public function clearLogo(string $updatedBy): array
    {
        $now = new UTCDateTime();
        $this->collection->updateOne(
            ['_id' => self::DOCUMENT_ID],
            [
                '$unset' => ['logo' => ''],
                '$set' => ['updatedAt' => $now, 'updatedBy' => $updatedBy],
                '$setOnInsert' => ['createdAt' => $now],
            ],
            ['upsert' => true],
        );

        return $this->find() ?? [];
    }

}
