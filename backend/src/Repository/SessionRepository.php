<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use ColoManager\Http\ApiException;
use DateInterval;
use DateTimeImmutable;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;

/**
 * Serverseitige Sitzungen ergänzen die signierten JWTs um Inaktivitätsablauf
 * und Widerruf. So genügt das Kopieren eines alten Tokens nicht, um eine
 * bereits abgelaufene oder abgemeldete Sitzung wiederzubeleben.
 */
final class SessionRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'auth_sessions');
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['sessionId' => 1], ['unique' => true]);
        $this->collection->createIndex(['userId' => 1, 'revokedAt' => 1]);
        // MongoDB entfernt endgültig abgelaufene Sitzungen automatisch.
        $this->collection->createIndex(['expiresAt' => 1], ['expireAfterSeconds' => 0]);
    }

    public function create(string $userId, int $absoluteTtlSeconds): string
    {
        $now = new DateTimeImmutable();
        $sessionId = bin2hex(random_bytes(32));
        $this->collection->insertOne([
            'sessionId' => $sessionId,
            'userId' => new ObjectId($userId),
            'lastActivityAt' => new UTCDateTime($now),
            'expiresAt' => new UTCDateTime($now->add(new DateInterval('PT' . $absoluteTtlSeconds . 'S'))),
            'revokedAt' => null,
            'createdAt' => new UTCDateTime($now),
        ]);

        return $sessionId;
    }

    /** Prüft den Fünf-Minuten-Ablauf atomar und aktualisiert die Aktivität. */
    public function validateAndTouch(string $sessionId, string $userId, int $idleTtlSeconds): void
    {
        if ($sessionId === '') {
            throw new ApiException(401, 'Die Sitzung ist ungültig. Bitte melden Sie sich erneut an.', 'invalid_session');
        }

        $now = new DateTimeImmutable();
        $idleCutoff = $now->sub(new DateInterval('PT' . $idleTtlSeconds . 'S'));
        $result = $this->collection->updateOne([
            'sessionId' => $sessionId,
            'userId' => new ObjectId($userId),
            'revokedAt' => null,
            'expiresAt' => ['$gt' => new UTCDateTime($now)],
            'lastActivityAt' => ['$gt' => new UTCDateTime($idleCutoff)],
        ], ['$set' => ['lastActivityAt' => new UTCDateTime($now)]]);

        if ($result->getMatchedCount() !== 1) {
            throw new ApiException(401, 'Ihre Sitzung ist wegen Inaktivität abgelaufen. Bitte melden Sie sich erneut an.', 'session_expired');
        }
    }

    public function revoke(string $sessionId, string $userId): void
    {
        $this->collection->updateOne([
            'sessionId' => $sessionId,
            'userId' => new ObjectId($userId),
            'revokedAt' => null,
        ], ['$set' => ['revokedAt' => $this->now()]]);
    }

    /** Nach einer Passwortänderung bleiben nur die aktuell bestätigte Sitzung aktiv. */
    public function revokeOtherSessions(string $userId, string $currentSessionId): void
    {
        $this->collection->updateMany([
            'userId' => new ObjectId($userId),
            'sessionId' => ['$ne' => $currentSessionId],
            'revokedAt' => null,
        ], ['$set' => ['revokedAt' => $this->now()]]);
    }

    /** Widerruft bei Deaktivierung eines Mitarbeiters alle noch offenen Sitzungen. */
    public function revokeAllSessions(string $userId): void
    {
        $this->collection->updateMany([
            'userId' => new ObjectId($userId),
            'revokedAt' => null,
        ], ['$set' => ['revokedAt' => $this->now()]]);
    }
}
