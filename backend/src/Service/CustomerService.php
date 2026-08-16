<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\BandwidthOptionRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\ContractRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\BillingAddress;
use ColoManager\Support\Validator;

/** Geschäftslogik für Kundenstammdaten und deren Zugriffsregeln. */
final readonly class CustomerService
{
    private const STATUSES = ['active', 'suspended', 'inactive'];

    public function __construct(
        private CustomerRepository $customers,
        private PlanRepository $plans,
        private BandwidthOptionRepository $bandwidthOptions,
        private LocationRepository $locations,
        private RackRepository $racks,
        private DeviceRepository $devices,
        private ContractRepository $contracts,
        private UserRepository $users,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(AuthContext $auth, int $page, int $limit, ?string $search): array
    {
        $this->requireCustomerManager($auth);
        return DocumentSerializer::serialize($this->customers->list($page, $limit, $search));
    }

    /** @return array<string, mixed> */
    public function current(AuthContext $auth): array
    {
        if ($auth->customerId === null) {
            throw new ApiException(404, 'Dem Benutzer ist kein Kunde zugeordnet.', 'customer_not_assigned');
        }
        $customer = $this->customers->findById($auth->customerId);
        return DocumentSerializer::serialize($this->withSubscription($customer));
    }

    /** @return array<string, mixed> */
    public function show(AuthContext $auth, string $id): array
    {
        $this->assertCustomerAccess($auth, $id);
        return DocumentSerializer::serialize($this->customers->findById($id));
    }

    /** @param array<string, mixed> $payload */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireCustomerManager($auth);
        Validator::required($payload, ['customerNumber', 'name', 'email', 'billingAddress']);
        Validator::email($payload, 'email');
        Validator::enum($payload, 'status', self::STATUSES);

        $data = Validator::only($payload, [
            'customerNumber', 'name', 'email', 'phone', 'status', 'billingAddress', 'contactPerson',
            'servicePlanId', 'bandwidthOptionId', 'contractStart', 'locationIds',
            'assignedTechnicianUserId', 'assignedSalesUserId',
        ]);
        $data['email'] = strtolower(trim((string) $data['email']));
        $data['billingAddress'] = BillingAddress::normalize($data['billingAddress']);
        // Bei Anlage durch den Vertrieb wird der Bearbeiter automatisch zum
        // kaufmännischen Ansprechpartner, solange niemand explizit gewählt ist.
        $creator = $this->users->findById($auth->userId);
        if (empty($data['assignedSalesUserId'])
            && mb_strtolower(trim((string) ($creator['department'] ?? ''))) === 'vertrieb') {
            $data['assignedSalesUserId'] = $auth->userId;
        }
        $this->validateContactReferences($data);
        $this->validateSubscriptionReferences($data);
        $this->validateLocationReferences($data);

        return DocumentSerializer::serialize($this->customers->create($data));
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->assertCustomerAccess($auth, $id);
        $managesAllCustomers = $this->canManageAllCustomers($auth);
        if (!$auth->canWriteCustomerData() && !$managesAllCustomers) {
            throw new ApiException(403, 'Kundenstammdaten dürfen nur Administratoren oder der Vertrieb ändern.', 'forbidden');
        }

        Validator::email($payload, 'email');
        Validator::enum($payload, 'status', self::STATUSES);
        $allowed = $managesAllCustomers
            ? ['customerNumber', 'name', 'email', 'phone', 'status', 'billingAddress', 'contactPerson', 'servicePlanId', 'bandwidthOptionId', 'contractStart', 'locationIds', 'assignedTechnicianUserId', 'assignedSalesUserId']
            : ['name', 'email', 'phone', 'billingAddress', 'contactPerson'];
        $data = Validator::only($payload, $allowed);
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }
        if (array_key_exists('billingAddress', $data)) {
            $data['billingAddress'] = BillingAddress::normalize($data['billingAddress']);
        }
        $this->validateContactReferences($data);
        $this->validateSubscriptionReferences($data);
        $this->validateLocationReferences($data, $id);

        return DocumentSerializer::serialize($this->customers->update($id, $data));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireCustomerManager($auth);
        if ($this->racks->countForCustomer($id) > 0 || $this->devices->countForCustomer($id) > 0 || $this->contracts->countForCustomer($id) > 0) {
            throw new ApiException(409, 'Der Kunde besitzt noch Infrastruktur oder laufende Vertragsdaten und kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->customers->softDelete($id);
    }

    private function assertCustomerAccess(AuthContext $auth, string $customerId): void
    {
        // Plattform-Administration und Vertrieb sehen alle Mandanten.
        // Kundenbenutzer bleiben strikt auf ihre signierte customerId begrenzt.
        if (!$this->canManageAllCustomers($auth) && $auth->customerId !== $customerId) {
            throw new ApiException(403, 'Kein Zugriff auf diesen Kunden.', 'forbidden');
        }
    }

    private function requireCustomerManager(AuthContext $auth): void
    {
        if (!$this->canManageAllCustomers($auth)) {
            throw new ApiException(403, 'Diese Aktion ist der Plattform-Administration und dem Vertrieb vorbehalten.', 'forbidden');
        }
    }

    /** Vertriebler verwalten Kunden vollständig, ohne weitere Plattformbereiche freizuschalten. */
    private function canManageAllCustomers(AuthContext $auth): bool
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

    /** @param array<string, mixed> $data */
    private function validateSubscriptionReferences(array $data): void
    {
        if (!empty($data['servicePlanId'])) {
            $this->plans->findById((string) $data['servicePlanId']);
        }
        if (!empty($data['bandwidthOptionId'])) {
            $this->bandwidthOptions->findById((string) $data['bandwidthOptionId']);
        }
    }

    /**
     * Prüft die festen Kundenkontakte nach Rolle. Kunden dürfen diese internen
     * Zuweisungen nicht selbst über ihren Stammdaten-Endpunkt verändern.
     *
     * @param array<string, mixed> $data
     */
    private function validateContactReferences(array &$data): void
    {
        $requirements = [
            'assignedTechnicianUserId' => ['technik', 'Bitte wählen Sie einen aktiven Mitarbeiter aus der Technik.'],
            'assignedSalesUserId' => ['vertrieb', 'Bitte wählen Sie einen aktiven Mitarbeiter aus dem Vertrieb.'],
        ];
        foreach ($requirements as $field => [$department, $message]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] === null || trim((string) $data[$field]) === '') {
                $data[$field] = null;
                continue;
            }
            $user = $this->users->findTicketAssignee((string) $data[$field]);
            if (mb_strtolower(trim((string) ($user['department'] ?? ''))) !== $department) {
                throw new ApiException(422, $message, 'invalid_customer_contact', ['field' => $field]);
            }
            $data[$field] = (string) $user['_id'];
        }
    }

    /** @param array<string, mixed> $data */
    private function validateLocationReferences(array &$data, ?string $customerId = null): void
    {
        if (!array_key_exists('locationIds', $data)) {
            return;
        }
        if (!is_array($data['locationIds'])) {
            throw new ApiException(422, 'locationIds muss eine Liste sein.', 'validation_failed', ['field' => 'locationIds']);
        }

        $locationIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $data['locationIds'],
        ))));
        foreach ($locationIds as $locationId) {
            $this->locations->findByIdForCustomer($locationId, null);
        }

        // Ein Standort mit vorhandener Infrastruktur darf nicht versehentlich
        // aus der Kundenverfügbarkeit entfernt werden.
        if ($customerId !== null) {
            $removedIds = array_diff($this->customers->locationIdsForCustomer($customerId), $locationIds);
            foreach ($removedIds as $removedId) {
                if ($this->racks->countForCustomerAndLocation($customerId, $removedId) > 0
                    || $this->devices->countForCustomerAndLocation($customerId, $removedId) > 0
                ) {
                    throw new ApiException(409, 'Ein Standort mit zugeordneten Racks oder Geräten kann nicht vom Kunden entfernt werden.', 'location_in_use', [
                        'locationId' => $removedId,
                    ]);
                }
            }
        }

        $data['locationIds'] = $locationIds;
    }

    /** Ergänzt den eigenen Kundendatensatz um lesefertige Tarifdetails für das Portal. */
    private function withSubscription(array $customer): array
    {
        $customer['subscription'] = [
            'plan' => isset($customer['servicePlanId']) && $customer['servicePlanId'] !== null
                ? $this->plans->findById((string) $customer['servicePlanId'])
                : null,
            'bandwidth' => isset($customer['bandwidthOptionId']) && $customer['bandwidthOptionId'] !== null
                ? $this->bandwidthOptions->findById((string) $customer['bandwidthOptionId'])
                : null,
        ];
        // Das Portal erhält nur die für eine Kontaktaufnahme nötigen Angaben;
        // Rollen-, Hash- und sonstige interne Benutzerdaten bleiben verborgen.
        $customer['contacts'] = [
            'technician' => $this->contactSnapshot($customer['assignedTechnicianUserId'] ?? null),
            'sales' => $this->contactSnapshot($customer['assignedSalesUserId'] ?? null),
        ];
        return $customer;
    }

    /** @return array{id: string, name: string, email: string, department: string}|null */
    private function contactSnapshot(mixed $userId): ?array
    {
        if ($userId === null || (string) $userId === '') {
            return null;
        }
        try {
            $user = $this->users->findById((string) $userId);
        } catch (ApiException) {
            return null;
        }
        if (($user['active'] ?? false) !== true || !in_array($user['role'] ?? null, ['platform_admin', 'datacenter_staff'], true)) {
            return null;
        }
        return [
            'id' => (string) $user['_id'],
            'name' => (string) ($user['name'] ?? $user['email'] ?? 'Ansprechpartner'),
            'email' => (string) ($user['email'] ?? ''),
            'department' => (string) ($user['department'] ?? ''),
        ];
    }
}
