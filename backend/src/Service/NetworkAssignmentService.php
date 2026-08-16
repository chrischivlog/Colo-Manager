<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\NetworkAssignmentRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Geschäftslogik für ISP-Netze und öffentliche IP-Zuweisungen. */
final readonly class NetworkAssignmentService
{
    private const FAMILIES = ['ipv4', 'ipv6'];
    private const STATUSES = ['active', 'reserved', 'retired'];
    private const USAGES = ['wan', 'management', 'public_services', 'backup', 'other'];

    public function __construct(
        private NetworkAssignmentRepository $assignments,
        private CustomerRepository $customers,
        private LocationRepository $locations,
        private UserRepository $users,
    ) {
    }

    /** @return array<string, mixed> */
    public function listInternal(AuthContext $auth, int $page, int $limit, ?string $customerId, ?string $locationId, ?string $status): array
    {
        $this->requireNetworkManager($auth);
        if ($customerId === null) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }
        $this->customers->findById($customerId);
        Validator::enum(['status' => $status], 'status', self::STATUSES);

        return $this->serializeList($this->assignments->list($page, $limit, $customerId, $locationId, $status));
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function searchInternal(AuthContext $auth, string $query): array
    {
        $this->requireNetworkManager($auth);
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ApiException(422, 'Der Suchbegriff muss zwischen 2 und 100 Zeichen lang sein.', 'validation_failed', ['field' => 'query']);
        }

        return ['items' => array_map($this->serializeAssignment(...), $this->assignments->search($query))];
    }

    /** @return array<string, mixed> */
    public function listCustomer(AuthContext $auth, int $page, int $limit, ?string $locationId, ?string $status): array
    {
        $customerId = $this->requireCustomer($auth);
        Validator::enum(['status' => $status], 'status', self::STATUSES);

        return $this->serializeList($this->assignments->list($page, $limit, $customerId, $locationId, $status));
    }

    /** @return array<string, mixed> */
    public function options(AuthContext $auth, ?string $customerId): array
    {
        $this->requireNetworkManager($auth);
        $result = $this->customers->list(1, 500);
        $customers = array_values(array_map(static fn (array $customer): array => [
            'id' => $customer['_id'],
            'name' => $customer['name'] ?? 'Unbenannter Kunde',
            'customerNumber' => $customer['customerNumber'] ?? null,
        ], array_filter($result['items'], static fn (array $customer): bool => ($customer['status'] ?? null) === 'active')));

        $locations = [];
        if ($customerId !== null) {
            $this->customers->findById($customerId);
            $assignedIds = $this->customers->locationIdsForCustomer($customerId);
            $locationResult = $this->locations->list(1, 100, $customerId, null, $assignedIds);
            $locations = array_map(static fn (array $location): array => [
                'id' => $location['_id'],
                'code' => $location['code'] ?? '',
                'name' => $location['name'] ?? 'Unbenannter Standort',
                'city' => $location['address']['city'] ?? null,
            ], $locationResult['items']);
        }

        return DocumentSerializer::serialize(['customers' => $customers, 'locations' => $locations]);
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        $this->requireNetworkManager($auth);
        return $this->serializeAssignment($this->assignments->findForCustomer($id, null));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireNetworkManager($auth);
        Validator::required($payload, ['customerId', 'locationId', 'label', 'ispName', 'addressFamily', 'cidr', 'usage']);
        $customerId = (string) $payload['customerId'];
        $this->validateReferences($customerId, (string) $payload['locationId']);
        $data = $this->validatedData($payload);

        return $this->serializeAssignment($this->assignments->create($data, $customerId, $auth->userId));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireNetworkManager($auth);
        $existing = $this->assignments->findForCustomer($id, null);
        $customerId = (string) ($payload['customerId'] ?? $existing['customerId']);
        $locationId = (string) ($payload['locationId'] ?? $existing['locationId']);
        $this->validateReferences($customerId, $locationId);
        $merged = array_replace($existing, $payload);
        $data = $this->validatedData($merged);
        $data['customerId'] = $customerId;

        return $this->serializeAssignment($this->assignments->update($id, $data, $auth->userId));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireNetworkManager($auth);
        $this->assignments->delete($id, $auth->userId);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validatedData(array $payload): array
    {
        Validator::enum($payload, 'addressFamily', self::FAMILIES);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::enum($payload, 'usage', self::USAGES);
        Validator::number($payload, 'vlanId', 1, 4094);
        $family = (string) $payload['addressFamily'];
        $cidr = $this->cidr((string) $payload['cidr'], $family);
        $gateway = trim((string) ($payload['gateway'] ?? ''));
        if ($gateway !== '') {
            $this->ip($gateway, $family, 'gateway');
        }
        $dnsServers = $payload['dnsServers'] ?? [];
        if (!is_array($dnsServers) || count($dnsServers) > 4) {
            throw new ApiException(422, 'Es sind maximal vier DNS-Server möglich.', 'validation_failed', ['field' => 'dnsServers']);
        }
        $dnsServers = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $dnsServers))));
        foreach ($dnsServers as $dns) {
            if (filter_var($dns, FILTER_VALIDATE_IP) === false) {
                throw new ApiException(422, 'Ein DNS-Server ist keine gültige IP-Adresse.', 'validation_failed', ['field' => 'dnsServers']);
            }
        }

        $data = Validator::only($payload, [
            'locationId', 'label', 'ispName', 'serviceReference', 'addressFamily', 'usage', 'status', 'vlanId', 'reverseDns', 'notes',
        ]);
        foreach (['label' => 120, 'ispName' => 120, 'serviceReference' => 160, 'reverseDns' => 255, 'notes' => 1200] as $field => $maximum) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $data[$field] = trim((string) $data[$field]);
            if (mb_strlen($data[$field]) > $maximum) {
                throw new ApiException(422, sprintf('%s ist zu lang.', $field), 'validation_failed', ['field' => $field, 'maximum' => $maximum]);
            }
        }
        if (($data['label'] ?? '') === '' || ($data['ispName'] ?? '') === '') {
            throw new ApiException(422, 'Bezeichnung und ISP dürfen nicht leer sein.', 'validation_failed');
        }
        $data['cidr'] = $cidr;
        $data['gateway'] = $gateway !== '' ? $gateway : null;
        $data['dnsServers'] = $dnsServers;
        $data['status'] = (string) ($payload['status'] ?? 'active');
        $data['vlanId'] = isset($payload['vlanId']) && $payload['vlanId'] !== '' ? (int) $payload['vlanId'] : null;

        return $data;
    }

    private function cidr(string $value, string $family): string
    {
        $parts = explode('/', trim($value), 2);
        if (count($parts) !== 2 || $parts[1] === '' || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            throw new ApiException(422, 'Bitte geben Sie die Adresse mit Präfix an, zum Beispiel 203.0.113.10/29.', 'validation_failed', ['field' => 'cidr']);
        }
        $this->ip($parts[0], $family, 'cidr');
        if (filter_var($parts[1], FILTER_VALIDATE_INT) === false) {
            throw new ApiException(422, 'Das Netzwerkpräfix ist ungültig.', 'validation_failed', ['field' => 'cidr']);
        }
        $prefix = (int) $parts[1];
        $maximum = $family === 'ipv4' ? 32 : 128;
        if ($prefix < 0 || $prefix > $maximum) {
            throw new ApiException(422, 'Das Netzwerkpräfix passt nicht zur Adressfamilie.', 'validation_failed', ['field' => 'cidr']);
        }
        return $parts[0] . '/' . $prefix;
    }

    private function ip(string $value, string $family, string $field): void
    {
        $flag = $family === 'ipv4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            throw new ApiException(422, sprintf('%s ist keine gültige %s-Adresse.', $field, strtoupper($family)), 'validation_failed', ['field' => $field]);
        }
    }

    private function validateReferences(string $customerId, string $locationId): void
    {
        $this->customers->findById($customerId);
        $this->locations->findAssignedToCustomer($locationId, $customerId, $this->customers->locationIdsForCustomer($customerId));
    }

    private function requireNetworkManager(AuthContext $auth): void
    {
        if ($auth->isPlatformAdmin()) {
            return;
        }
        if (!$auth->isDatacenterStaff()) {
            throw new ApiException(403, 'Nur Datacenter-Techniker dürfen Netzwerkdaten verwalten.', 'forbidden');
        }
        $user = $this->users->findById($auth->userId);
        if (mb_strtolower(trim((string) ($user['department'] ?? ''))) !== 'technik') {
            throw new ApiException(403, 'Nur Mitarbeiter der Technik dürfen Netzwerkdaten verwalten.', 'forbidden');
        }
    }

    private function requireCustomer(AuthContext $auth): string
    {
        if ($auth->role !== 'customer_admin' || $auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kundenkonto zugeordnet.', 'forbidden');
        }
        return $auth->customerId;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function serializeList(array $result): array
    {
        $result['items'] = array_map($this->serializeAssignment(...), $result['items']);
        return DocumentSerializer::serialize($result);
    }

    /** @param array<string, mixed> $assignment @return array<string, mixed> */
    private function serializeAssignment(array $assignment): array
    {
        $customer = $this->customers->findById((string) $assignment['customerId']);
        $location = $this->locations->findById((string) $assignment['locationId']);
        $assignment['customer'] = ['id' => $customer['_id'], 'name' => $customer['name'] ?? 'Unbenannter Kunde', 'customerNumber' => $customer['customerNumber'] ?? null];
        $assignment['location'] = ['id' => $location['_id'], 'code' => $location['code'] ?? '', 'name' => $location['name'] ?? 'Unbenannter Standort'];
        return DocumentSerializer::serialize($assignment);
    }
}
