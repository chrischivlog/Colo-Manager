<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\GridFS\Bucket;

/**
 * Speichert Vertragsfassungen unabhängig von Tickets in einem eigenen GridFS.
 * Damit funktionieren manuelle Verträge und Nachträge ohne verstecktes Ticket.
 */
final class ContractDocumentRepository
{
    private readonly Bucket $bucket;

    public function __construct(Database $database)
    {
        $this->bucket = $database->selectGridFSBucket(['bucketName' => 'contract_documents']);
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function storeContent(string $contractId, string $kind, string $content, string $name, string $mimeType, string $uploadedBy): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || fwrite($stream, $content) === false) {
            throw new ApiException(500, 'Das Vertragsdokument konnte nicht vorbereitet werden.', 'contract_document_generation_failed');
        }
        rewind($stream);
        try {
            $id = $this->bucket->uploadFromStream($name, $stream, ['metadata' => [
                'contractId' => new ObjectId($contractId),
                'kind' => $kind,
                'mimeType' => $mimeType,
                'size' => strlen($content),
                'uploadedBy' => $uploadedBy,
            ]]);
        } finally {
            fclose($stream);
        }
        return ['id' => (string) $id, 'name' => $name, 'mimeType' => $mimeType, 'size' => strlen($content)];
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function storeFile(string $contractId, string $kind, string $path, string $name, string $mimeType, int $size, string $uploadedBy): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new ApiException(422, 'Die Vertragsdatei konnte nicht gelesen werden.', 'upload_failed');
        }
        try {
            $id = $this->bucket->uploadFromStream($name, $stream, ['metadata' => [
                'contractId' => new ObjectId($contractId),
                'kind' => $kind,
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
    public function download(string $documentId, string $contractId): array
    {
        try {
            $id = new ObjectId($documentId);
            $file = $this->bucket->find([
                '_id' => $id,
                'metadata.contractId' => new ObjectId($contractId),
            ])->toArray()[0] ?? null;
        } catch (\Throwable) {
            $file = null;
        }
        if ($file === null) {
            throw new ApiException(404, 'Das Vertragsdokument wurde nicht gefunden.', 'contract_document_not_found');
        }

        $stream = $this->bucket->openDownloadStream($id);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) {
            throw new ApiException(500, 'Das Vertragsdokument konnte nicht gelesen werden.', 'contract_document_read_failed');
        }
        return [
            'content' => $content,
            'name' => (string) ($file['filename'] ?? 'vertrag.pdf'),
            'mimeType' => (string) ($file['metadata']['mimeType'] ?? 'application/pdf'),
            'size' => (int) ($file['length'] ?? strlen($content)),
        ];
    }
}
