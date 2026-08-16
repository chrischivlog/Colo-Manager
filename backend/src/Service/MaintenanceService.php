<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\MaintenanceRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;
use DateTimeImmutable;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Fachlogik für geplante Wartungen und deren Kunden-/Öffentlichkeitsscope. */
final class MaintenanceService
{
    private const STATUS_VALUES = ['geplant', 'aktiv', 'abgeschlossen', 'abgebrochen'];

    public function __construct(
        private readonly MaintenanceRepository $maintenance,
        private readonly CustomerRepository $customers,
        private readonly RackRepository $racks,
        private readonly LocationRepository $locations,
        private readonly DeviceRepository $devices,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $status = null): array
    {
        Validator::enum(['status' => $status], 'status', self::STATUS_VALUES);
        $customerId = $auth->isPlatformAdmin() ? null : $this->requireCustomer($auth);
        return DocumentSerializer::serialize($this->maintenance->list($page, $limit, $customerId, $status));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        $item = $auth->isPlatformAdmin()
            ? $this->maintenance->findById($id)
            : $this->maintenance->findByIdForCustomer($id, $this->requireCustomer($auth));
        return DocumentSerializer::serialize($item);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::required($payload, ['title', 'description', 'plannedStart']);
        Validator::enum($payload, 'status', self::STATUS_VALUES);

        $data = Validator::only($payload, [
            'title', 'description', 'status', 'plannedStart', 'plannedEnd', 'impact', 'isPublic',
            'affectsAllCustomers', 'locationId', 'rackId', 'customerIds',
        ]);
        $data += ['status' => 'geplant', 'impact' => '', 'isPublic' => false, 'affectsAllCustomers' => false];
        $data['isPublic'] = (bool) $data['isPublic'];
        $data['affectsAllCustomers'] = (bool) $data['affectsAllCustomers'];
        $this->validateDateRange($data['plannedStart'], $data['plannedEnd'] ?? null);
        $data['customerIds'] = $this->resolveScope($data);

        return DocumentSerializer::serialize($this->maintenance->create($data, $auth->userId, $data['customerIds']));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        $current = $this->maintenance->findById($id);
        Validator::enum($payload, 'status', self::STATUS_VALUES);

        $data = Validator::only($payload, [
            'title', 'description', 'status', 'plannedStart', 'plannedEnd', 'impact', 'isPublic',
            'affectsAllCustomers', 'locationId', 'rackId', 'customerIds',
        ]);
        foreach (['isPublic', 'affectsAllCustomers'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }
        $start = array_key_exists('plannedStart', $data) ? $data['plannedStart'] : ($current['plannedStart'] ?? null);
        $end = array_key_exists('plannedEnd', $data) ? $data['plannedEnd'] : ($current['plannedEnd'] ?? null);
        $this->validateDateRange($start, $end);

        if ($this->scopeChanged($payload)) {
            $scope = $this->mergeScope($payload, $current);
            $data = array_merge($data, $scope);
            $data['customerIds'] = $this->resolveScope($scope);
        }
        return DocumentSerializer::serialize($this->maintenance->update($id, $data, $auth->userId));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        $this->maintenance->delete($id);
    }

    /** @return array<string, mixed> */
    public function listForCustomer(AuthContext $auth, int $page, int $limit): array
    {
        return DocumentSerializer::serialize($this->maintenance->listForCustomer($this->requireCustomer($auth), $page, $limit));
    }

    /** @param array<string, mixed> $scope @return list<string> */
    private function resolveScope(array &$scope): array
    {
        if (($scope['affectsAllCustomers'] ?? false) === true) {
            return [];
        }
        $customerIds = [];
        $explicit = $scope['customerIds'] ?? [];
        if (!is_array($explicit)) {
            throw new ApiException(422, 'customerIds muss eine Liste sein.', 'validation_failed', ['field' => 'customerIds']);
        }
        foreach ($explicit as $customerId) {
            $id = (string) $customerId;
            $this->customers->findById($id);
            $customerIds[] = $id;
        }

        $location = null;
        if (!empty($scope['locationId'])) {
            $location = $this->locations->findById((string) $scope['locationId']);
            $customerIds[] = (string) $location['customerId'];
        }
        if (!empty($scope['rackId'])) {
            $rack = $this->racks->findById((string) $scope['rackId']);
            if ($location !== null && (string) $rack['locationId'] !== (string) $location['_id']) {
                throw new ApiException(422, 'Das gewählte Rack gehört nicht zum gewählten Standort.', 'validation_failed', ['field' => 'rackId']);
            }
            $scope['locationId'] = (string) $rack['locationId'];
            $customerIds[] = (string) $rack['customerId'];
        }
        $customerIds = array_values(array_unique($customerIds));
        if ($customerIds === []) {
            throw new ApiException(422, 'Bitte wählen Sie „Alle Kunden“, einen Kunden, Standort oder ein Rack.', 'validation_failed');
        }
        return $customerIds;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $current @return array<string, mixed> */
    private function mergeScope(array $payload, array $current): array
    {
        return [
            'affectsAllCustomers' => array_key_exists('affectsAllCustomers', $payload) ? (bool) $payload['affectsAllCustomers'] : (bool) ($current['affectsAllCustomers'] ?? false),
            'isPublic' => array_key_exists('isPublic', $payload) ? (bool) $payload['isPublic'] : (bool) ($current['isPublic'] ?? false),
            'locationId' => array_key_exists('locationId', $payload) ? $payload['locationId'] : $this->id($current['infrastructure']['locationId'] ?? null),
            'rackId' => array_key_exists('rackId', $payload) ? $payload['rackId'] : $this->id($current['infrastructure']['rackId'] ?? null),
            'customerIds' => array_key_exists('customerIds', $payload) ? $payload['customerIds'] : array_map(fn (mixed $id): string => $this->id($id) ?? '', (array) ($current['customerIds'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function scopeChanged(array $payload): bool
    {
        foreach (['affectsAllCustomers', 'locationId', 'rackId', 'customerIds'] as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }
        return false;
    }

    private function validateDateRange(mixed $start, mixed $end): void
    {
        $startDate = $this->date($start, 'plannedStart');
        if ($startDate === null) {
            throw new ApiException(422, 'Der geplante Beginn ist erforderlich.', 'validation_failed', ['field' => 'plannedStart']);
        }
        $endDate = $this->date($end, 'plannedEnd');
        if ($endDate !== null && $endDate < $startDate) {
            throw new ApiException(422, 'Das geplante Ende darf nicht vor dem Beginn liegen.', 'validation_failed');
        }
    }

    private function date(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if ($value instanceof UTCDateTime) {
                return DateTimeImmutable::createFromMutable($value->toDateTime());
            }
            if ($value instanceof DateTimeInterface) {
                return new DateTimeImmutable($value->format(DATE_ATOM));
            }
            return new DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            throw new ApiException(422, sprintf('Ungültiges Datumsformat für %s.', $field), 'validation_failed', ['field' => $field]);
        }
    }

    private function requireCustomer(AuthContext $auth): string
    {
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
        }
        return $auth->customerId;
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Nur Plattform-Administratoren dürfen Wartungen bearbeiten.', 'forbidden');
        }
    }

    private function id(mixed $value): ?string
    {
        return $value instanceof ObjectId ? (string) $value : (is_string($value) && $value !== '' ? $value : null);
    }
}
