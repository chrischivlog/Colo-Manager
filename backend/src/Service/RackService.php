<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Geschäftslogik für physische Rack-Kapazitäten und deren Kunden-/Standortbezug. */
final readonly class RackService
{
    private const STATUSES = ['active', 'reserved', 'maintenance', 'inactive'];
    private const DEVICE_TYPES = ['server', 'switch', 'router', 'firewall', 'storage', 'pdu', 'other'];
    private const DEVICE_STATUSES = ['online', 'offline', 'maintenance', 'decommissioned'];

    public function __construct(
        private RackRepository $racks,
        private LocationRepository $locations,
        private CustomerRepository $customers,
        private DeviceRepository $devices,
        private UserRepository $users,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $customerId, ?string $locationId): array
    {
        $isInternal = $auth->isPlatformAdmin() || $this->isTechnician($auth);
        $scope = $isInternal ? $customerId : $auth->customerId;
        if (!$isInternal && $scope === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
        }
        if (!$isInternal && $customerId !== null && $customerId !== $scope) {
            throw new ApiException(403, 'Kein Zugriff auf diesen Kunden.', 'forbidden');
        }

        return DocumentSerializer::serialize($this->racks->list($page, $limit, $scope, $locationId));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        return DocumentSerializer::serialize($this->rackForRead($auth, $id));
    }

    /**
     * Liefert Rack und Geräte bewusst gemeinsam. So basiert die grafische
     * Höheneinheiten-Ansicht immer auf einem konsistenten API-Snapshot.
     *
     * @return array<string, mixed>
     */
    public function layout(AuthContext $auth, string $id): array
    {
        $rack = $this->rackForRead($auth, $id);
        $devices = $this->devices->listForRack($id);
        $location = $this->locations->findById((string) $rack['locationId']);
        $remoteHandsEnabled = (bool) ($rack['remoteHandsAccess']['enabled'] ?? false);

        return DocumentSerializer::serialize([
            'rack' => $rack,
            'location' => [
                'id' => $location['_id'],
                'code' => $location['code'] ?? '',
                'name' => $location['name'] ?? '',
            ],
            'devices' => $devices,
            'permissions' => [
                'canEditLayout' => $auth->isPlatformAdmin()
                    || ($auth->role === 'customer_admin' && (string) $rack['customerId'] === (string) $auth->customerId)
                    || ($this->isTechnician($auth) && $remoteHandsEnabled),
                'canManageRemoteHandsAccess' => $auth->role === 'customer_admin'
                    && (string) $rack['customerId'] === (string) $auth->customerId,
                'remoteHandsAccessEnabled' => $remoteHandsEnabled,
            ],
            'layoutSupported' => $this->isHalfOrFullRack((int) ($rack['totalUnits'] ?? 0)),
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateRemoteHandsAccess(AuthContext $auth, string $id, array $payload): array
    {
        if ($auth->role !== 'customer_admin' || $auth->customerId === null) {
            throw new ApiException(403, 'Nur der Rack-Kunde darf die Remote-Hands-Freigabe ändern.', 'forbidden');
        }
        if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
            throw new ApiException(422, 'enabled muss als boolescher Wert übergeben werden.', 'validation_failed', ['field' => 'enabled']);
        }

        $this->racks->findByIdForCustomer($id, $auth->customerId);
        $this->racks->setRemoteHandsAccess($id, $auth->customerId, $payload['enabled'], $auth->userId);

        return $this->layout($auth, $id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createLayoutDevice(AuthContext $auth, string $rackId, array $payload): array
    {
        $rack = $this->rackForRead($auth, $rackId);
        $this->assertLayoutWriteAccess($auth, $rack);
        $this->assertLayoutSupported($rack);
        Validator::required($payload, ['name', 'type', 'rackUnit', 'heightUnits']);
        $this->validateDevicePayload($payload);

        $rackUnit = (int) $payload['rackUnit'];
        $heightUnits = (int) $payload['heightUnits'];
        $this->assertPlacementAvailable($rack, $this->devices->listForRack($rackId), $rackUnit, $heightUnits);

        $data = Validator::only($payload, [
            'assetTag', 'name', 'type', 'status', 'rackUnit', 'heightUnits', 'manufacturer', 'model', 'serialNumber',
        ]);
        $data['assetTag'] = trim((string) ($data['assetTag'] ?? '')) ?: sprintf(
            'VIS-%s-HE%d-%s',
            strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', (string) ($rack['code'] ?? 'RACK')) ?? 'RACK'),
            $rackUnit,
            strtoupper(bin2hex(random_bytes(2))),
        );
        $data['status'] ??= 'offline';
        $data['rackUnit'] = $rackUnit;
        $data['heightUnits'] = $heightUnits;
        $data['locationId'] = (string) $rack['locationId'];
        $data['rackId'] = $rackId;

        $device = $this->devices->create($data, (string) $rack['customerId']);
        $this->syncUsedUnits($rackId, (string) $rack['customerId']);

        return DocumentSerializer::serialize($device);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateLayoutDevice(AuthContext $auth, string $rackId, string $deviceId, array $payload): array
    {
        $rack = $this->rackForRead($auth, $rackId);
        $this->assertLayoutWriteAccess($auth, $rack);
        $this->assertLayoutSupported($rack);
        $this->validateDevicePayload($payload);
        $device = $this->devices->findByIdForCustomer($deviceId, (string) $rack['customerId']);
        if ((string) ($device['rackId'] ?? '') !== $rackId) {
            throw new ApiException(404, 'Das Gerät gehört nicht zu diesem Rack.', 'device_not_found');
        }

        $rackUnit = (int) ($payload['rackUnit'] ?? $device['rackUnit'] ?? 0);
        $heightUnits = (int) ($payload['heightUnits'] ?? $device['heightUnits'] ?? 1);
        $this->assertPlacementAvailable($rack, $this->devices->listForRack($rackId), $rackUnit, $heightUnits, $deviceId);
        $data = Validator::only($payload, [
            'assetTag', 'name', 'type', 'status', 'rackUnit', 'heightUnits', 'manufacturer', 'model', 'serialNumber',
        ]);
        if (array_key_exists('assetTag', $data) && trim((string) $data['assetTag']) === '') {
            unset($data['assetTag']);
        }
        $data['rackUnit'] = $rackUnit;
        $data['heightUnits'] = $heightUnits;

        $updated = $this->devices->update($deviceId, $data, (string) $rack['customerId']);
        $this->syncUsedUnits($rackId, (string) $rack['customerId']);

        return DocumentSerializer::serialize($updated);
    }

    public function deleteLayoutDevice(AuthContext $auth, string $rackId, string $deviceId): void
    {
        $rack = $this->rackForRead($auth, $rackId);
        $this->assertLayoutWriteAccess($auth, $rack);
        $device = $this->devices->findByIdForCustomer($deviceId, (string) $rack['customerId']);
        if ((string) ($device['rackId'] ?? '') !== $rackId) {
            throw new ApiException(404, 'Das Gerät gehört nicht zu diesem Rack.', 'device_not_found');
        }

        $this->devices->deleteForCustomer($deviceId, (string) $rack['customerId']);
        $this->syncUsedUnits($rackId, (string) $rack['customerId']);
    }

    /** @param array<string, mixed> $payload */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::required($payload, ['customerId', 'locationId', 'code', 'name', 'totalUnits', 'powerLimitKw']);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::number($payload, 'totalUnits', 1, 60);
        Validator::number($payload, 'usedUnits', 0, 60);
        Validator::number($payload, 'powerLimitKw', 0.1, 100);

        $customerId = (string) $payload['customerId'];
        $this->customers->findById($customerId);
        $this->locations->findAssignedToCustomer(
            (string) $payload['locationId'],
            $customerId,
            $this->customers->locationIdsForCustomer($customerId),
        );
        $data = Validator::only($payload, [
            'locationId', 'code', 'name', 'room', 'row', 'totalUnits', 'usedUnits', 'powerLimitKw', 'status', 'notes',
        ]);
        $data['usedUnits'] ??= 0;
        if ((float) $data['usedUnits'] > (float) $data['totalUnits']) {
            throw new ApiException(422, 'Die belegten HE dürfen die Gesamtgröße nicht überschreiten.', 'validation_failed');
        }

        return DocumentSerializer::serialize($this->racks->create($data, $customerId));
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::number($payload, 'totalUnits', 1, 60);
        Validator::number($payload, 'usedUnits', 0, 60);
        Validator::number($payload, 'powerLimitKw', 0.1, 100);
        $existing = $this->racks->findByIdForCustomer($id, null);
        if (isset($payload['locationId'])) {
            $customerId = (string) $existing['customerId'];
            $this->locations->findAssignedToCustomer(
                (string) $payload['locationId'],
                $customerId,
                $this->customers->locationIdsForCustomer($customerId),
            );
        }
        $data = Validator::only($payload, [
            'locationId', 'code', 'name', 'room', 'row', 'totalUnits', 'usedUnits', 'powerLimitKw', 'status', 'notes',
        ]);
        $totalUnits = (float) ($data['totalUnits'] ?? $existing['totalUnits']);
        $usedUnits = (float) ($data['usedUnits'] ?? $existing['usedUnits']);
        if ($usedUnits > $totalUnits) {
            throw new ApiException(422, 'Die belegten HE dürfen die Gesamtgröße nicht überschreiten.', 'validation_failed');
        }

        return DocumentSerializer::serialize($this->racks->update($id, $data, null));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        if ($this->devices->countForRack($id) > 0) {
            throw new ApiException(409, 'Das Rack enthält noch Geräte und kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->racks->deleteForCustomer($id, null);
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Diese Aktion ist Plattform-Administratoren vorbehalten.', 'forbidden');
        }
    }

    /** @return array<string, mixed> */
    private function rackForRead(AuthContext $auth, string $id): array
    {
        if ($auth->isPlatformAdmin() || $this->isTechnician($auth)) {
            return $this->racks->findByIdForCustomer($id, null);
        }
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
        }

        return $this->racks->findByIdForCustomer($id, $auth->customerId);
    }

    /** @param array<string, mixed> $rack */
    private function assertLayoutWriteAccess(AuthContext $auth, array $rack): void
    {
        if ($auth->isPlatformAdmin()) {
            return;
        }
        if ($auth->role === 'customer_admin' && $auth->customerId !== null && (string) $rack['customerId'] === $auth->customerId) {
            return;
        }
        if ($this->isTechnician($auth) && (bool) ($rack['remoteHandsAccess']['enabled'] ?? false)) {
            return;
        }

        throw new ApiException(403, 'Dieses Rack ist nicht für die Bearbeitung durch Remote Hands freigegeben.', 'rack_layout_not_shared');
    }

    /** @param array<string, mixed> $payload */
    private function validateDevicePayload(array $payload): void
    {
        Validator::enum($payload, 'type', self::DEVICE_TYPES);
        Validator::enum($payload, 'status', self::DEVICE_STATUSES);
        Validator::number($payload, 'rackUnit', 1, 60);
        Validator::number($payload, 'heightUnits', 1, 60);
    }

    /** @param array<string, mixed> $rack @param list<array<string, mixed>> $devices */
    private function assertPlacementAvailable(array $rack, array $devices, int $rackUnit, int $heightUnits, ?string $ignoreDeviceId = null): void
    {
        $totalUnits = (int) ($rack['totalUnits'] ?? 0);
        $endUnit = $rackUnit + $heightUnits - 1;
        if ($rackUnit < 1 || $heightUnits < 1 || $endUnit > $totalUnits) {
            throw new ApiException(422, 'Das Gerät passt nicht in die gewählten Höheneinheiten.', 'rack_unit_out_of_bounds', [
                'rackUnit' => $rackUnit,
                'heightUnits' => $heightUnits,
                'totalUnits' => $totalUnits,
            ]);
        }

        foreach ($devices as $device) {
            if ($ignoreDeviceId !== null && (string) ($device['_id'] ?? '') === $ignoreDeviceId) {
                continue;
            }
            $deviceStart = (int) ($device['rackUnit'] ?? 0);
            if ($deviceStart < 1) {
                continue;
            }
            $deviceEnd = $deviceStart + max(1, (int) ($device['heightUnits'] ?? 1)) - 1;
            if ($rackUnit <= $deviceEnd && $endUnit >= $deviceStart) {
                throw new ApiException(409, sprintf(
                    'HE %d bis HE %d sind bereits durch „%s“ belegt.',
                    $deviceStart,
                    $deviceEnd,
                    (string) ($device['name'] ?? 'ein Gerät'),
                ), 'rack_units_occupied', ['deviceId' => (string) ($device['_id'] ?? '')]);
            }
        }
    }

    /** @param array<string, mixed> $rack */
    private function assertLayoutSupported(array $rack): void
    {
        if (!$this->isHalfOrFullRack((int) ($rack['totalUnits'] ?? 0))) {
            throw new ApiException(422, 'Die visuelle Belegung ist für halbe und volle Racks verfügbar.', 'rack_layout_not_supported');
        }
    }

    private function isHalfOrFullRack(int $totalUnits): bool
    {
        return ($totalUnits >= 20 && $totalUnits <= 24) || ($totalUnits >= 40 && $totalUnits <= 48);
    }

    private function isTechnician(AuthContext $auth): bool
    {
        if (!$auth->isDatacenterStaff()) {
            return false;
        }
        $user = $this->users->findById($auth->userId);

        return mb_strtolower(trim((string) ($user['department'] ?? ''))) === 'technik';
    }

    private function syncUsedUnits(string $rackId, string $customerId): void
    {
        $usedUnits = array_reduce(
            $this->devices->listForRack($rackId),
            static fn (int $sum, array $device): int => $sum + ((int) ($device['rackUnit'] ?? 0) > 0 ? max(1, (int) ($device['heightUnits'] ?? 1)) : 0),
            0,
        );
        $this->racks->update($rackId, ['usedUnits' => $usedUnits], $customerId);
    }
}
