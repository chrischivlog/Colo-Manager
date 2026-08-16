<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Geschäftslogik für Server, Switches, Firewalls und sonstige Rack-Geräte. */
final readonly class DeviceService
{
    private const TYPES = ['server', 'switch', 'router', 'firewall', 'storage', 'pdu', 'other'];
    private const STATUSES = ['online', 'offline', 'maintenance', 'decommissioned'];

    public function __construct(
        private DeviceRepository $devices,
        private LocationRepository $locations,
        private CustomerRepository $customers,
        private RackRepository $racks,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $customerId, ?string $locationId, ?string $type, ?string $status): array
    {
        $scope = $this->scopeCustomerId($auth, $customerId);
        Validator::enum(['type' => $type], 'type', self::TYPES);
        Validator::enum(['status' => $status], 'status', self::STATUSES);

        return DocumentSerializer::serialize($this->devices->list($page, $limit, $scope, $locationId, $type, $status));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        return DocumentSerializer::serialize(
            $this->devices->findByIdForCustomer($id, $auth->isPlatformAdmin() ? null : $auth->customerId),
        );
    }

    /** @param array<string, mixed> $payload */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireWriteAccess($auth);
        $customerId = $this->scopeCustomerId($auth, isset($payload['customerId']) ? (string) $payload['customerId'] : null);
        if ($customerId === null) {
            throw new ApiException(422, 'customerId ist für Plattform-Administratoren erforderlich.', 'validation_failed');
        }
        $this->customers->findById($customerId);

        Validator::required($payload, ['assetTag', 'name', 'type', 'locationId']);
        Validator::enum($payload, 'type', self::TYPES);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::number($payload, 'rackUnit', 1, 60);
        Validator::number($payload, 'heightUnits', 1, 60);
        Validator::number($payload, 'powerWatts', 0, 100000);
        $this->locations->findAssignedToCustomer(
            (string) $payload['locationId'],
            $customerId,
            $this->customers->locationIdsForCustomer($customerId),
        );

        if (!empty($payload['rackId'])) {
            $rack = $this->racks->findByIdForCustomer((string) $payload['rackId'], $customerId);
            if ((string) $rack['locationId'] !== (string) $payload['locationId']) {
                throw new ApiException(422, 'Das Rack gehört nicht zum gewählten Standort.', 'validation_failed');
            }
        }

        $data = Validator::only($payload, [
            'assetTag', 'name', 'type', 'status', 'locationId', 'rackId', 'rack', 'rackUnit', 'heightUnits',
            'manufacturer', 'model', 'serialNumber', 'managementIp', 'powerWatts', 'metadata',
        ]);
        $data['status'] ??= 'offline';

        return DocumentSerializer::serialize($this->devices->create($data, $customerId));
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireWriteAccess($auth);
        Validator::enum($payload, 'type', self::TYPES);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::number($payload, 'rackUnit', 1, 60);
        Validator::number($payload, 'heightUnits', 1, 60);
        Validator::number($payload, 'powerWatts', 0, 100000);

        $customerScope = $auth->isPlatformAdmin() ? null : $auth->customerId;
        $existing = $this->devices->findByIdForCustomer($id, $customerScope);
        if (isset($payload['locationId'])) {
            // Der Zielstandort muss dem Kunden des Geräts zugewiesen sein.
            $deviceCustomerId = (string) $existing['customerId'];
            $this->locations->findAssignedToCustomer(
                (string) $payload['locationId'],
                $deviceCustomerId,
                $this->customers->locationIdsForCustomer($deviceCustomerId),
            );
        }

        if (!empty($payload['rackId'])) {
            $deviceCustomerId = (string) $existing['customerId'];
            $rack = $this->racks->findByIdForCustomer((string) $payload['rackId'], $deviceCustomerId);
            $targetLocationId = (string) ($payload['locationId'] ?? $existing['locationId']);
            if ((string) $rack['locationId'] !== $targetLocationId) {
                throw new ApiException(422, 'Das Rack gehört nicht zum gewählten Standort.', 'validation_failed');
            }
        }

        $data = Validator::only($payload, [
            'assetTag', 'name', 'type', 'status', 'locationId', 'rackId', 'rack', 'rackUnit', 'heightUnits',
            'manufacturer', 'model', 'serialNumber', 'managementIp', 'powerWatts', 'metadata',
        ]);

        return DocumentSerializer::serialize($this->devices->update($id, $data, $customerScope));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireWriteAccess($auth);
        $this->devices->deleteForCustomer($id, $auth->isPlatformAdmin() ? null : $auth->customerId);
    }

    private function scopeCustomerId(AuthContext $auth, ?string $requestedCustomerId): ?string
    {
        if ($auth->isPlatformAdmin()) {
            return $requestedCustomerId;
        }
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
        }
        if ($requestedCustomerId !== null && $requestedCustomerId !== $auth->customerId) {
            throw new ApiException(403, 'Kein Zugriff auf diesen Kunden.', 'forbidden');
        }
        return $auth->customerId;
    }

    private function requireWriteAccess(AuthContext $auth): void
    {
        if (!$auth->canWriteCustomerData()) {
            throw new ApiException(403, 'Nur Administratoren dürfen Geräte ändern.', 'forbidden');
        }
    }
}
