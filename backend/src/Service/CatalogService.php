<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\BandwidthOptionRepository;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;

/** Pflegt das für alle Kunden sichtbare Tarif- und Bandbreitenangebot. */
final readonly class CatalogService
{
    private const STATUSES = ['active', 'draft', 'inactive'];
    private const BILLING_MODELS = ['flat', 'volume'];

    public function __construct(
        private PlanRepository $plans,
        private BandwidthOptionRepository $bandwidthOptions,
        private CustomerRepository $customers,
        private LocationRepository $locations,
    ) {
    }

    /** @return array<string, mixed> */
    public function listPlans(AuthContext $auth, int $page, int $limit, ?string $status): array
    {
        $status = $auth->isPlatformAdmin() ? $status : 'active';
        Validator::enum(['status' => $status], 'status', self::STATUSES);
        return DocumentSerializer::serialize($this->plans->list($page, $limit, $status));
    }

    /** @return array<string, mixed> */
    public function showPlan(AuthContext $auth, string $id): array
    {
        $plan = $this->plans->findById($id);
        if (!$auth->isPlatformAdmin() && ($plan['status'] ?? null) !== 'active') {
            throw new ApiException(404, 'Der Tarif wurde nicht gefunden.', 'plan_not_found');
        }
        return DocumentSerializer::serialize($plan);
    }

    /** @param array<string, mixed> $payload */
    public function createPlan(AuthContext $auth, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::required($payload, ['code', 'name', 'rackUnits', 'powerKw', 'monthlyPrice']);
        $data = $this->validatePlan($payload);
        return DocumentSerializer::serialize($this->plans->create($data));
    }

    /** @param array<string, mixed> $payload */
    public function updatePlan(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        return DocumentSerializer::serialize($this->plans->update($id, $this->validatePlan($payload)));
    }

    public function deletePlan(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        if ($this->customers->countByReference('servicePlanId', $id) > 0) {
            throw new ApiException(409, 'Der Tarif ist noch Kunden zugeordnet und kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->plans->softDelete($id);
    }

    /** @return array<string, mixed> */
    public function listBandwidthOptions(AuthContext $auth, int $page, int $limit, ?string $status): array
    {
        $status = $auth->isPlatformAdmin() ? $status : 'active';
        Validator::enum(['status' => $status], 'status', self::STATUSES);
        return DocumentSerializer::serialize($this->bandwidthOptions->list($page, $limit, $status));
    }

    /** @return array<string, mixed> */
    public function showBandwidthOption(AuthContext $auth, string $id): array
    {
        $option = $this->bandwidthOptions->findById($id);
        if (!$auth->isPlatformAdmin() && ($option['status'] ?? null) !== 'active') {
            throw new ApiException(404, 'Das Bandbreitenprofil wurde nicht gefunden.', 'bandwidth_option_not_found');
        }
        return DocumentSerializer::serialize($option);
    }

    /** @param array<string, mixed> $payload */
    public function createBandwidthOption(AuthContext $auth, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::required($payload, ['code', 'name', 'committedMbps', 'burstMbps', 'monthlyPrice']);
        return DocumentSerializer::serialize($this->bandwidthOptions->create($this->validateBandwidth($payload)));
    }

    /** @param array<string, mixed> $payload */
    public function updateBandwidthOption(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        return DocumentSerializer::serialize($this->bandwidthOptions->update($id, $this->validateBandwidth($payload)));
    }

    public function deleteBandwidthOption(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        if ($this->customers->countByReference('bandwidthOptionId', $id) > 0) {
            throw new ApiException(409, 'Das Bandbreitenprofil ist noch Kunden zugeordnet und kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->bandwidthOptions->softDelete($id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validatePlan(array $payload): array
    {
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::number($payload, 'rackUnits', 1, 60);
        Validator::number($payload, 'powerKw', 0.1, 100);
        Validator::number($payload, 'monthlyPrice', 0);
        Validator::number($payload, 'setupFee', 0);
        if (array_key_exists('locationIds', $payload) && !is_array($payload['locationIds'])) {
            throw new ApiException(422, 'locationIds muss eine Liste sein.', 'validation_failed', ['field' => 'locationIds']);
        }
        $data = Validator::only($payload, [
            'code', 'name', 'description', 'rackUnits', 'powerKw', 'monthlyPrice', 'setupFee', 'features', 'status', 'locationIds',
        ]);
        if (array_key_exists('locationIds', $data)) {
            $data['locationIds'] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): string => trim((string) $id),
                $data['locationIds'],
            ))));
            foreach ($data['locationIds'] as $locationId) {
                $this->locations->findById($locationId);
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validateBandwidth(array $payload): array
    {
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::enum($payload, 'billingModel', self::BILLING_MODELS);
        Validator::number($payload, 'committedMbps', 1, 1000000);
        Validator::number($payload, 'burstMbps', 1, 1000000);
        Validator::number($payload, 'monthlyPrice', 0);
        Validator::number($payload, 'includedTransferTb', 0.1, 1000000);
        Validator::number($payload, 'overagePricePerTb', 0);

        if (isset($payload['symmetric']) && !is_bool($payload['symmetric'])) {
            throw new ApiException(422, 'Ungültiger Wert für symmetric.', 'validation_failed', ['field' => 'symmetric']);
        }

        $data = Validator::only($payload, [
            'code', 'name', 'description', 'committedMbps', 'burstMbps', 'monthlyPrice', 'status',
            'billingModel', 'symmetric', 'includedTransferTb', 'overagePricePerTb',
        ]);

        // Bestehende API-Clients bleiben kompatibel; neue Profile werden ohne
        // explizite Angabe als symmetrische Flatrate behandelt.
        $data['billingModel'] ??= 'flat';
        $data['symmetric'] ??= true;

        if ($data['billingModel'] === 'volume' && empty($data['includedTransferTb'])) {
            throw new ApiException(422, 'Für einen Volumentarif ist das enthaltene Datenvolumen erforderlich.', 'validation_failed', [
                'field' => 'includedTransferTb',
            ]);
        }
        if ($data['billingModel'] === 'flat') {
            $data['includedTransferTb'] = null;
            $data['overagePricePerTb'] = null;
        }
        if (isset($data['committedMbps'], $data['burstMbps']) && (float) $data['burstMbps'] < (float) $data['committedMbps']) {
            throw new ApiException(422, 'Die Burst-Bandbreite darf nicht kleiner als die garantierte Bandbreite sein.', 'validation_failed');
        }
        return $data;
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Diese Aktion ist Plattform-Administratoren vorbehalten.', 'forbidden');
        }
    }
}
