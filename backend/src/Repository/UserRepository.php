<?php

declare(strict_types=1);

namespace ColoManager\Repository;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;

/** Persistenzzugriff auf Benutzerkonten; Passwort-Hashes bleiben intern. */
final class UserRepository extends BaseRepository
{
    public function __construct(Database $database)
    {
        parent::__construct($database, 'users');
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->collection->findOne([
            'email' => strtolower(trim($email)),
            'active' => true,
            'deletedAt' => null,
        ]);
    }

    /** Findet auch deaktivierte Konten, damit die eindeutige E-Mail nicht doppelt angelegt wird. */
    public function findAnyByEmail(string $email): ?array
    {
        return $this->collection->findOne([
            'email' => strtolower(trim($email)),
            'deletedAt' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = $this->now();
        if (array_key_exists('customerId', $data) && !empty($data['customerId']) && !($data['customerId'] instanceof ObjectId)) {
            $data['customerId'] = new ObjectId((string) $data['customerId']);
        }
        if (array_key_exists('directoryId', $data) && !empty($data['directoryId']) && !($data['directoryId'] instanceof ObjectId)) {
            $data['directoryId'] = new ObjectId((string) $data['directoryId']);
        }
        $document = $data + ['createdAt' => $now, 'updatedAt' => $now, 'deletedAt' => null];
        $result = $this->collection->insertOne($document);

        return $this->findById((string) $result->getInsertedId());
    }

    /** @return list<array<string, mixed>> Liefert aktive und deaktivierte interne Konten für die Administration. */
    public function listInternalUsers(): array
    {
        return $this->collection->find([
            'role' => ['$in' => ['platform_admin', 'datacenter_staff']],
            'deletedAt' => null,
        ], ['sort' => ['active' => -1, 'name' => 1]])->toArray();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function updateInternalUser(string $id, array $data, bool $removeLocalPassword): array
    {
        if (isset($data['directoryId']) && is_string($data['directoryId']) && $data['directoryId'] !== '') {
            $data['directoryId'] = $this->objectId($data['directoryId']);
        }
        $update = ['$set' => $data + ['updatedAt' => $this->now()]];
        if ($removeLocalPassword) {
            $update['$unset'] = ['passwordHash' => '', 'passwordReset' => ''];
        }
        $result = $this->collection->updateOne(['_id' => $this->objectId($id), 'deletedAt' => null], $update);
        if ($result->getMatchedCount() !== 1) {
            throw new \ColoManager\Http\ApiException(404, 'Der Mitarbeiter wurde nicht gefunden.', 'staff_user_not_found');
        }
        return $this->findById($id);
    }

    /** Speichert nur erfolgreiche externe Anmelde-Metadaten, niemals das Verzeichniskennwort. */
    public function recordDirectoryLogin(string $id, ?string $directoryDn, ?string $displayName): array
    {
        $set = ['updatedAt' => $this->now()];
        if ($directoryDn !== null && $directoryDn !== '') $set['directoryDn'] = $directoryDn;
        if ($displayName !== null && $displayName !== '') $set['name'] = $displayName;
        $this->collection->updateOne(['_id' => $this->objectId($id), 'active' => true, 'deletedAt' => null], ['$set' => $set]);
        return $this->findById($id);
    }

    /** Vereinheitlicht die sichtbare Login-Historie auch für lokale Konten. */
    public function recordLogin(string $id, bool $directory = false): array
    {
        $now = $this->now();
        $set = ['lastLoginAt' => $now, 'updatedAt' => $now];
        if ($directory) $set['lastDirectoryLoginAt'] = $now;
        $this->collection->updateOne(
            ['_id' => $this->objectId($id), 'active' => true, 'deletedAt' => null],
            ['$set' => $set],
        );
        return $this->findById($id);
    }

    /** @return list<array<string, mixed>> Liefert aktive interne Bearbeiter für Ticketzuweisungen. */
    public function listTicketAssignees(): array
    {
        return $this->collection->find([
            'role' => ['$in' => ['platform_admin', 'datacenter_staff']],
            'active' => true,
            'deletedAt' => null,
        ], ['sort' => ['name' => 1]])->toArray();
    }

    /** Liefert aktive Vertriebsmitarbeiter für interne Lead-Benachrichtigungen. */
    public function listSalesStaff(): array
    {
        return $this->collection->find([
            'role' => ['$in' => ['platform_admin', 'datacenter_staff']],
            'department' => ['$regex' => '^Vertrieb$', '$options' => 'i'],
            'active' => true,
            'deletedAt' => null,
        ], ['sort' => ['name' => 1]])->toArray();
    }

    /** @return list<array<string, mixed>> Liefert aktive Techniker für die Onboarding-Übergabe. */
    public function listTechnicians(): array
    {
        return $this->collection->find([
            'role' => 'datacenter_staff',
            'department' => ['$regex' => '^Technik$', '$options' => 'i'],
            'active' => true,
            'deletedAt' => null,
        ], ['sort' => ['name' => 1]])->toArray();
    }

    /** Prüft eine explizite Übergabe an einen aktiven Techniker. */
    public function findTechnician(string $id): array
    {
        $user = $this->findTicketAssignee($id);
        if (($user['role'] ?? null) !== 'datacenter_staff'
            || mb_strtolower(trim((string) ($user['department'] ?? ''))) !== 'technik') {
            throw new \ColoManager\Http\ApiException(422, 'Bitte wählen Sie einen aktiven Techniker aus.', 'invalid_onboarding_assignee');
        }
        return $user;
    }

    /** Prüft, dass eine Zuweisung auf einen aktiven internen Benutzer zeigt. */
    public function findTicketAssignee(string $id): array
    {
        $user = $this->findById($id);
        if (($user['active'] ?? false) !== true || !in_array($user['role'] ?? null, ['platform_admin', 'datacenter_staff'], true)) {
            throw new \ColoManager\Http\ApiException(422, 'Der gewählte Bearbeiter ist nicht verfügbar.', 'invalid_ticket_assignee');
        }
        return $user;
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['email' => 1], ['unique' => true]);
        $this->collection->createIndex(['customerId' => 1, 'active' => 1]);
        $this->collection->createIndex(['directoryId' => 1, 'directoryUsername' => 1, 'active' => 1]);
        // Nur der Hash des zufälligen Reset-Tokens wird gespeichert. Der
        // Sparse-Index erlaubt Benutzer ohne laufenden Reset-Vorgang.
        $this->collection->createIndex(['passwordReset.tokenHash' => 1], ['unique' => true, 'sparse' => true]);
    }

    public function updateEmail(string $userId, string $email): array
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            ['$set' => ['email' => strtolower(trim($email)), 'emailChangedAt' => $this->now(), 'updatedAt' => $this->now()]],
        );

        return $this->findById($userId);
    }

    public function updatePassword(string $userId, string $passwordHash): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            ['$set' => ['passwordHash' => $passwordHash, 'passwordChangedAt' => $this->now(), 'updatedAt' => $this->now()]],
        );
    }

    /** Speichert ein nur kurz gültiges, noch nicht aktives Authenticator-Secret. */
    public function setTwoFactorSetup(string $userId, string $encryptedSecret, UTCDateTime $expiresAt): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            ['$set' => [
                'twoFactorSetup' => ['encryptedSecret' => $encryptedSecret, 'expiresAt' => $expiresAt, 'createdAt' => $this->now()],
                'updatedAt' => $this->now(),
            ]],
        );
    }

    public function enableTwoFactor(string $userId, string $encryptedSecret): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            [
                '$set' => ['twoFactor' => ['encryptedSecret' => $encryptedSecret, 'enabledAt' => $this->now()], 'updatedAt' => $this->now()],
                '$unset' => ['twoFactorSetup' => ''],
            ],
        );
    }

    public function disableTwoFactor(string $userId): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            ['$unset' => ['twoFactor' => '', 'twoFactorSetup' => ''], '$set' => ['updatedAt' => $this->now()]],
        );
    }

    /** Speichert beziehungsweise ersetzt genau einen laufenden Reset-Vorgang. */
    public function setPasswordReset(string $userId, array $passwordReset): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'active' => true, 'deletedAt' => null],
            ['$set' => ['passwordReset' => $passwordReset, 'updatedAt' => $this->now()]],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByPasswordResetTokenHash(string $tokenHash): ?array
    {
        return $this->collection->findOne([
            'passwordReset.tokenHash' => $tokenHash,
            'active' => true,
            'deletedAt' => null,
        ]);
    }

    /** Aktualisiert ausschließlich den nicht sicherheitskritischen Mailstatus. */
    public function setPasswordResetMailStatus(string $userId, string $status): void
    {
        $this->collection->updateOne(
            ['_id' => $this->objectId($userId), 'deletedAt' => null],
            ['$set' => ['passwordReset.emailStatus' => $status, 'updatedAt' => $this->now()]],
        );
    }

    /**
     * Verbraucht den Token und setzt das Passwort in einem atomaren Update.
     * Zwei parallele Requests können denselben Einmal-Link daher nie nutzen.
     */
    public function consumePasswordReset(string $tokenHash, string $passwordHash): bool
    {
        $now = $this->now();
        $result = $this->collection->updateOne([
            'passwordReset.tokenHash' => $tokenHash,
            'passwordReset.status' => 'pending',
            'passwordReset.expiresAt' => ['$gt' => $now],
            'active' => true,
            'deletedAt' => null,
        ], ['$set' => [
            'passwordHash' => $passwordHash,
            'passwordChangedAt' => $now,
            'passwordReset.status' => 'used',
            'passwordReset.usedAt' => $now,
            'updatedAt' => $now,
        ]]);

        return $result->getModifiedCount() === 1;
    }
}
