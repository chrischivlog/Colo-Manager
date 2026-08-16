<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Event\IncidentMarkedCritical;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\IncidentHistoryRepository;
use ColoManager\Repository\IncidentRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Fachlogik für Störungen, Mandantenscope und nachvollziehbare Statuswechsel. */
final class IncidentService
{
    private const STATUS_VALUES = ['offen', 'in_untersuchung', 'in_bearbeitung', 'behoben', 'geschlossen'];
    private const PRIORITY_VALUES = ['low', 'medium', 'high', 'critical'];
    private const CLOSED_STATUSES = ['behoben', 'geschlossen'];

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentHistoryRepository $history,
        private readonly CustomerRepository $customers,
        private readonly RackRepository $racks,
        private readonly LocationRepository $locations,
        private readonly DeviceRepository $devices,
        private readonly EventDispatcherService $eventDispatcher,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $status = null, ?string $priority = null, ?bool $critical = null): array
    {
        Validator::enum(['status' => $status], 'status', self::STATUS_VALUES);
        Validator::enum(['priority' => $priority], 'priority', self::PRIORITY_VALUES);
        $customerId = $auth->isPlatformAdmin() ? null : $this->requireCustomer($auth);

        return DocumentSerializer::serialize($this->incidents->list($page, $limit, $customerId, $status, $priority, $critical));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        $incident = $auth->isPlatformAdmin()
            ? $this->incidents->findById($id)
            : $this->incidents->findByIdForCustomer($id, $this->requireCustomer($auth));

        return DocumentSerializer::serialize($incident);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::required($payload, ['title', 'description']);
        Validator::enum($payload, 'status', self::STATUS_VALUES);
        Validator::enum($payload, 'priority', self::PRIORITY_VALUES);

        $data = Validator::only($payload, [
            'title', 'description', 'status', 'priority', 'critical', 'isPublic', 'affectsAllCustomers',
            'startAt', 'endAt', 'locationId', 'rackId', 'customerIds',
        ]);
        $data += [
            'status' => 'offen',
            'priority' => 'medium',
            'critical' => false,
            'isPublic' => false,
            'affectsAllCustomers' => false,
        ];
        $data['critical'] = (bool) $data['critical'];
        $data['isPublic'] = (bool) $data['isPublic'];
        $data['affectsAllCustomers'] = (bool) $data['affectsAllCustomers'];
        $this->validateDateRange($data['startAt'] ?? null, $data['endAt'] ?? null, 'Start', 'Ende');
        $data['customerIds'] = $this->resolveScope($data);

        $incident = $this->incidents->create($data, $auth->userId, $data['customerIds']);
        $this->history->create([
            'oldStatus' => null,
            'newStatus' => $incident['status'],
            'comment' => trim((string) ($payload['comment'] ?? '')) ?: 'Störung erstellt',
        ], (string) $incident['_id'], $auth->userId);

        if (($incident['critical'] ?? false) === true) {
            $this->triggerCriticalEvent($incident, $auth->userId);
        }
        return DocumentSerializer::serialize($incident);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        $incident = $this->incidents->findById($id);
        Validator::enum($payload, 'status', self::STATUS_VALUES);
        Validator::enum($payload, 'priority', self::PRIORITY_VALUES);

        $data = Validator::only($payload, [
            'title', 'description', 'status', 'priority', 'critical', 'isPublic', 'affectsAllCustomers',
            'startAt', 'endAt', 'locationId', 'rackId', 'customerIds',
        ]);
        foreach (['critical', 'isPublic', 'affectsAllCustomers'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }

        $newStatus = $data['status'] ?? $incident['status'];
        if (in_array($newStatus, self::CLOSED_STATUSES, true) && !array_key_exists('endAt', $data) && empty($incident['endAt'])) {
            $data['endAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
        }
        if (!in_array($newStatus, self::CLOSED_STATUSES, true) && in_array((string) $incident['status'], self::CLOSED_STATUSES, true) && !array_key_exists('endAt', $data)) {
            $data['endAt'] = null;
        }

        $start = array_key_exists('startAt', $data) ? $data['startAt'] : ($incident['startAt'] ?? null);
        $end = array_key_exists('endAt', $data) ? $data['endAt'] : ($incident['endAt'] ?? null);
        $this->validateDateRange($start, $end, 'Start', 'Ende');

        if ($this->scopeChanged($payload)) {
            $scope = $this->mergeScope($payload, $incident);
            $data = array_merge($data, $scope);
            $data['customerIds'] = $this->resolveScope($scope);
        }

        $updated = $this->incidents->update($id, $data, $auth->userId);
        if ($incident['status'] !== $newStatus) {
            $this->history->create([
                'oldStatus' => $incident['status'],
                'newStatus' => $newStatus,
                'comment' => trim((string) ($payload['comment'] ?? '')) ?: 'Status aktualisiert',
            ], $id, $auth->userId);
        }
        if (($updated['critical'] ?? false) === true && ($incident['critical'] ?? false) !== true) {
            $this->triggerCriticalEvent($updated, $auth->userId);
        }

        return DocumentSerializer::serialize($updated);
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        $incident = $this->incidents->findById($id);
        $this->incidents->delete($id);
        $this->history->create([
            'oldStatus' => $incident['status'],
            'newStatus' => 'gelöscht',
            'comment' => 'Störung gelöscht',
        ], $id, $auth->userId);
    }

    /** @return array<string, mixed> */
    public function history(AuthContext $auth, string $id): array
    {
        if ($auth->isPlatformAdmin()) {
            $this->incidents->findById($id);
        } else {
            $this->incidents->findByIdForCustomer($id, $this->requireCustomer($auth));
        }
        return DocumentSerializer::serialize(['items' => $this->history->findByIncidentId($id)]);
    }

    /** @return array<string, mixed> */
    public function listForCustomer(AuthContext $auth, int $page, int $limit): array
    {
        return DocumentSerializer::serialize($this->incidents->listForCustomer($this->requireCustomer($auth), $page, $limit));
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

    /** @param array<string, mixed> $payload @param array<string, mixed> $incident @return array<string, mixed> */
    private function mergeScope(array $payload, array $incident): array
    {
        return [
            'affectsAllCustomers' => array_key_exists('affectsAllCustomers', $payload) ? (bool) $payload['affectsAllCustomers'] : (bool) ($incident['affectsAllCustomers'] ?? false),
            'isPublic' => array_key_exists('isPublic', $payload) ? (bool) $payload['isPublic'] : (bool) ($incident['isPublic'] ?? false),
            'locationId' => array_key_exists('locationId', $payload) ? $payload['locationId'] : $this->id($incident['infrastructure']['locationId'] ?? null),
            'rackId' => array_key_exists('rackId', $payload) ? $payload['rackId'] : $this->id($incident['infrastructure']['rackId'] ?? null),
            'customerIds' => array_key_exists('customerIds', $payload) ? $payload['customerIds'] : array_map(fn (mixed $id): string => $this->id($id) ?? '', (array) ($incident['customerIds'] ?? [])),
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

    private function validateDateRange(mixed $start, mixed $end, string $startLabel, string $endLabel): void
    {
        $startDate = $this->date($start, 'startAt');
        $endDate = $this->date($end, 'endAt');
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new ApiException(422, sprintf('%s darf nicht vor %s liegen.', $endLabel, $startLabel), 'validation_failed');
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
            throw new ApiException(403, 'Nur Plattform-Administratoren dürfen Statusmeldungen bearbeiten.', 'forbidden');
        }
    }

    private function id(mixed $value): ?string
    {
        return $value instanceof ObjectId ? (string) $value : (is_string($value) && $value !== '' ? $value : null);
    }

    /** @param array<string, mixed> $incident */
    private function triggerCriticalEvent(array $incident, string $triggeredBy): void
    {
        $this->eventDispatcher->dispatch(new IncidentMarkedCritical(
            (string) $incident['_id'],
            (string) $incident['title'],
            true,
            $triggeredBy,
            new DateTime(),
        ));
    }
}
