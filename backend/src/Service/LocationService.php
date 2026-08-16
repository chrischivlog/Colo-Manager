<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Verwaltet Kundenstandorte und erzwingt die Mandantentrennung. */
final readonly class LocationService
{
    private const STATUSES = ['active', 'maintenance', 'inactive'];

    public function __construct(
        private LocationRepository $locations,
        private CustomerRepository $customers,
        private RackRepository $racks,
        private DeviceRepository $devices,
        private UserRepository $users,
        private PlanRepository $plans,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $customerId, ?string $status): array
    {
        // Der Vertrieb benötigt die Standortliste ausschließlich lesend, um
        // Kundenstammdaten und deren Datacenter-Verfügbarkeit zuzuordnen.
        $scope = $this->scopeCustomerIdForRead($auth, $customerId);
        Validator::enum(['status' => $status], 'status', self::STATUSES);
        $assignedLocationIds = $scope === null ? [] : $this->customers->locationIdsForCustomer($scope);
        return DocumentSerializer::serialize($this->locations->list($page, $limit, $scope, $status, $assignedLocationIds));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        if ($this->canReadAllCustomers($auth)) {
            return DocumentSerializer::serialize($this->locations->findByIdForCustomer($id, null));
        }
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
        }
        return DocumentSerializer::serialize($this->locations->findAssignedToCustomer(
            $id,
            $auth->customerId,
            $this->customers->locationIdsForCustomer($auth->customerId),
        ));
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

        Validator::required($payload, ['code', 'name', 'address']);
        Validator::enum($payload, 'status', self::STATUSES);
        $this->validateCoordinates($payload);
        $data = Validator::only($payload, ['code', 'name', 'address', 'status', 'timezone', 'notes', 'coordinates']);
        $this->normalizeCoordinates($data);

        $location = $this->locations->create($data, $customerId);
        $this->customers->addLocationReference($customerId, (string) $location['_id']);
        return DocumentSerializer::serialize($location);
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireWriteAccess($auth);
        Validator::enum($payload, 'status', self::STATUSES);
        $this->validateCoordinates($payload);
        $data = Validator::only($payload, ['code', 'name', 'address', 'status', 'timezone', 'notes', 'coordinates']);
        $this->normalizeCoordinates($data);

        return DocumentSerializer::serialize($this->locations->update(
            $id,
            $data,
            $auth->isPlatformAdmin() ? null : $auth->customerId,
        ));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireWriteAccess($auth);
        if ($this->racks->countForLocation($id) > 0 || $this->devices->countForLocation($id) > 0) {
            throw new ApiException(409, 'Der Standort enthält noch Racks oder Geräte und kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->customers->removeLocationReferenceFromAll($id);
        $this->plans->removeLocationReferenceFromAll($id);
        $this->locations->deleteForCustomer($id, $auth->isPlatformAdmin() ? null : $auth->customerId);
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

    private function scopeCustomerIdForRead(AuthContext $auth, ?string $requestedCustomerId): ?string
    {
        if ($this->canReadAllCustomers($auth)) {
            return $requestedCustomerId;
        }
        return $this->scopeCustomerId($auth, $requestedCustomerId);
    }

    private function canReadAllCustomers(AuthContext $auth): bool
    {
        if ($auth->isPlatformAdmin()) {
            return true;
        }
        if (!$auth->isDatacenterStaff()) {
            return false;
        }
        $user = $this->users->findById($auth->userId);
        return mb_strtolower(trim((string) ($user['department'] ?? ''))) === 'vertrieb';
    }

    private function requireWriteAccess(AuthContext $auth): void
    {
        if (!$auth->canWriteCustomerData()) {
            throw new ApiException(403, 'Nur Administratoren dürfen Standorte ändern.', 'forbidden');
        }
    }

    /** Prüft optionale Kartenkoordinaten, ohne sie für bestehende Standorte zu erzwingen. */
    private function validateCoordinates(array $payload): void
    {
        if (!array_key_exists('coordinates', $payload) || $payload['coordinates'] === null) {
            return;
        }
        if (!is_array($payload['coordinates'])) {
            throw new ApiException(422, 'coordinates muss ein Objekt sein.', 'validation_failed', ['field' => 'coordinates']);
        }
        Validator::required($payload['coordinates'], ['latitude', 'longitude']);
        Validator::number($payload['coordinates'], 'latitude', -90, 90);
        Validator::number($payload['coordinates'], 'longitude', -180, 180);
    }

    /** @param array<string, mixed> $data */
    private function normalizeCoordinates(array &$data): void
    {
        if (!isset($data['coordinates']) || !is_array($data['coordinates'])) {
            return;
        }
        $data['coordinates'] = [
            'latitude' => (float) $data['coordinates']['latitude'],
            'longitude' => (float) $data['coordinates']['longitude'],
        ];
    }
}
