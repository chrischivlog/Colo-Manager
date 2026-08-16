<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\GridFS\Bucket;

/** Speichert hochgeladene Plattformlogos binär und unabhängig von Einstellungen. */
final class BrandingAssetRepository
{
    private readonly Bucket $bucket;

    public function __construct(Database $database)
    {
        $this->bucket = $database->selectGridFSBucket(['bucketName' => 'branding_assets']);
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function store(string $path, string $name, string $mimeType, int $size, string $uploadedBy): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new ApiException(422, 'Das Logo konnte nicht gelesen werden.', 'branding_logo_upload_failed');
        }

        try {
            $id = $this->bucket->uploadFromStream($name, $stream, ['metadata' => [
                'mimeType' => $mimeType,
                'size' => $size,
                'uploadedBy' => $uploadedBy,
            ]]);
        } finally {
            fclose($stream);
        }

        return ['id' => (string) $id, 'name' => $name, 'mimeType' => $mimeType, 'size' => $size];
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function download(string $id): array
    {
        try {
            $objectId = new ObjectId($id);
            $file = $this->bucket->find(['_id' => $objectId])->toArray()[0] ?? null;
        } catch (\Throwable) {
            $file = null;
        }

        if ($file === null) {
            throw new ApiException(404, 'Das hinterlegte Logo wurde nicht gefunden.', 'branding_logo_not_found');
        }

        $stream = $this->bucket->openDownloadStream($objectId);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) {
            throw new ApiException(500, 'Das Logo konnte nicht gelesen werden.', 'branding_logo_read_failed');
        }

        return [
            'content' => $content,
            'name' => (string) ($file['filename'] ?? 'logo'),
            'mimeType' => (string) ($file['metadata']['mimeType'] ?? 'image/png'),
            'size' => (int) ($file['length'] ?? strlen($content)),
        ];
    }

    /** Entfernt ein altes Logo nach erfolgreichem Austausch bestmöglich. */
    public function delete(?string $id): void
    {
        if ($id === null || $id === '') {
            return;
        }

        try {
            $this->bucket->delete(new ObjectId($id));
        } catch (\Throwable) {
            // Eine fehlende Altdatei darf das Speichern des neuen Brandings nicht zurückrollen.
        }
    }
}
