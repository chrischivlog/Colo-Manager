<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\GridFS\Bucket;

/** Legt Ticketdateien mit Besitzer-Metadaten im MongoDB GridFS ab. */
final class TicketAttachmentRepository
{
    private readonly Bucket $bucket;

    public function __construct(Database $database)
    {
        $this->bucket = $database->selectGridFSBucket(['bucketName' => 'ticket_attachments']);
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function store(string $ticketId, string $messageId, string $path, string $name, string $mimeType, int $size, string $uploadedBy): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new ApiException(422, 'Die Datei konnte nicht gelesen werden.', 'upload_failed');
        }
        try {
            $id = $this->bucket->uploadFromStream($name, $stream, ['metadata' => [
                'ticketId' => new ObjectId($ticketId),
                'messageId' => new ObjectId($messageId),
                'mimeType' => $mimeType,
                'size' => $size,
                'uploadedBy' => $uploadedBy,
            ]]);
        } finally {
            fclose($stream);
        }

        return ['id' => (string) $id, 'name' => $name, 'mimeType' => $mimeType, 'size' => $size];
    }

    /** @return array{content: string, name: string, mimeType: string, size: int, messageId: string} */
    public function download(string $attachmentId, string $ticketId): array
    {
        try {
            $id = new ObjectId($attachmentId);
            $file = $this->bucket->find([
                '_id' => $id,
                'metadata.ticketId' => new ObjectId($ticketId),
            ])->toArray()[0] ?? null;
        } catch (\Throwable) {
            $file = null;
        }
        if ($file === null) {
            throw new ApiException(404, 'Der Ticketanhang wurde nicht gefunden.', 'ticket_attachment_not_found');
        }

        $stream = $this->bucket->openDownloadStream($id);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) {
            throw new ApiException(500, 'Der Ticketanhang konnte nicht gelesen werden.', 'attachment_read_failed');
        }

        return [
            'content' => $content,
            'name' => (string) ($file['filename'] ?? 'ticket-datei'),
            'mimeType' => (string) ($file['metadata']['mimeType'] ?? 'application/octet-stream'),
            'size' => (int) ($file['length'] ?? strlen($content)),
            'messageId' => (string) ($file['metadata']['messageId'] ?? ''),
        ];
    }

    /** Entfernt die GridFS-Dateien eines administrativ gelöschten Tickets. */
    public function deleteForTicket(string $ticketId): void
    {
        $files = $this->bucket->find(['metadata.ticketId' => new ObjectId($ticketId)]);
        foreach ($files as $file) {
            $this->bucket->delete($file['_id']);
        }
    }

    /** Speichert serverseitig erzeugte Dokumente ohne unsichere temporäre Datei. */
    public function storeContent(string $ticketId, string $messageId, string $content, string $name, string $mimeType, string $uploadedBy): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || fwrite($stream, $content) === false) {
            throw new ApiException(500, 'Das erzeugte Dokument konnte nicht vorbereitet werden.', 'document_generation_failed');
        }
        rewind($stream);
        try {
            $id = $this->bucket->uploadFromStream($name, $stream, ['metadata' => [
                'ticketId' => new ObjectId($ticketId),
                'messageId' => new ObjectId($messageId),
                'mimeType' => $mimeType,
                'size' => strlen($content),
                'uploadedBy' => $uploadedBy,
            ]]);
        } finally {
            fclose($stream);
        }
        return ['id' => (string) $id, 'name' => $name, 'mimeType' => $mimeType, 'size' => strlen($content)];
    }
}
