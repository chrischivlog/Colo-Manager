<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\BandwidthOptionRepository;
use ColoManager\Repository\InquiryRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\BillingAddress;
use ColoManager\Support\Validator;

/** Öffentliche Angebotsdaten und Interessentenanfragen mit getrenntem Adminzugriff. */
final readonly class PublicOfferService
{
    private const INQUIRY_STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];
    private const RACK_TYPES = ['units', 'half', 'full'];
    private const BILLING_MODELS = ['flat', 'volume'];
    private const CONTRACT_TERMS = [12, 24, 36, 60];

    public function __construct(
        private PlanRepository $plans,
        private BandwidthOptionRepository $bandwidthOptions,
        private LocationRepository $locations,
        private InquiryRepository $inquiries,
        private TicketService $tickets,
        private NotificationMailService $notifications,
        private string $frontendUrl,
    ) {
    }

    /** Liefert ausschließlich aktive, bewusst veröffentlichte Katalogdaten. */
    public function offers(): array
    {
        $plans = $this->plans->list(1, 100, 'active')['items'];
        $locations = array_map(
            static fn (array $location): array => [
                // Öffentlich werden bewusst nur Präsentationsdaten geliefert.
                // Kundenbezüge, interne Notizen und die genaue Straße bleiben
                // außerhalb der Angebotsseite.
                'id' => (string) $location['_id'],
                'code' => (string) ($location['code'] ?? ''),
                'name' => (string) ($location['name'] ?? 'Datacenter-Standort'),
                'city' => (string) ($location['address']['city'] ?? ''),
                'country' => (string) ($location['address']['country'] ?? 'DE'),
                'latitude' => isset($location['coordinates']['latitude']) ? (float) $location['coordinates']['latitude'] : null,
                'longitude' => isset($location['coordinates']['longitude']) ? (float) $location['coordinates']['longitude'] : null,
                'status' => 'active',
            ],
            $this->locations->list(1, 100, null, 'active')['items'],
        );

        // Die Standardauswahl wird um jede im Adminbereich an einem aktiven
        // Tarif gepflegte Leistung ergänzt. Neue Tarifwerte erscheinen dadurch
        // automatisch im Konfigurator, ohne eine Frontendänderung zu benötigen.
        $powerOptions = [];
        foreach ([1.0, 2.0, 3.5, 5.6, 8.0, 10.0] as $powerKw) {
            $powerOptions[(string) $powerKw] = $powerKw;
        }
        foreach ($plans as $plan) {
            if (isset($plan['powerKw']) && is_numeric($plan['powerKw'])) {
                $powerOptions[(string) (float) $plan['powerKw']] = (float) $plan['powerKw'];
            }
        }
        ksort($powerOptions, SORT_NUMERIC);

        return DocumentSerializer::serialize([
            'plans' => $plans,
            'bandwidthOptions' => $this->bandwidthOptions->list(1, 100, 'active')['items'],
            'locations' => $locations,
            // Der Konfigurator bezieht seine fachlichen Grenzen vollständig aus
            // der API. Weitere Frontends können dadurch dieselbe Strecke bauen.
            'configurator' => [
                'rackSpace' => [
                    'minimumUnits' => 1,
                    'maximumUnits' => 60,
                    'presets' => [
                        ['type' => 'units', 'units' => 10, 'name' => '10 HE', 'description' => 'Für einzelne Server und Appliances'],
                        ['type' => 'half', 'units' => 22, 'name' => 'Halbes Rack', 'description' => '22 HE für wachsende Plattformen'],
                        ['type' => 'full', 'units' => 44, 'name' => 'Ganzes Rack', 'description' => '44 HE exklusiv für Ihre Infrastruktur'],
                    ],
                ],
                'powerOptionsKw' => array_values($powerOptions),
                'contractTermsMonths' => self::CONTRACT_TERMS,
                'networkBillingModels' => [
                    ['id' => 'flat', 'name' => 'Flatrate', 'description' => 'Planbar ohne volumenabhängige Abrechnung'],
                    ['id' => 'volume', 'name' => 'Volumentarif', 'description' => 'Inklusivvolumen mit transparenter Mehrnutzung'],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function createInquiry(array $payload): array
    {
        // Das unsichtbare Feld wird von Menschen nicht ausgefüllt und blockiert
        // einfache Formularbots, bevor ein Datensatz gespeichert wird.
        if (!empty($payload['website'])) {
            throw new ApiException(422, 'Die Anfrage konnte nicht verarbeitet werden.', 'validation_failed');
        }
        Validator::required($payload, [
            'company', 'contactName', 'email', 'locationId', 'planId', 'bandwidthOptionId', 'rackUnits',
            'rackType', 'powerKw', 'networkBillingModel', 'contractMonths', 'billingAddress', 'consent',
        ]);
        Validator::email($payload, 'email');
        Validator::number($payload, 'rackUnits', 1, 60);
        Validator::number($payload, 'powerKw', 0.1, 100);
        Validator::enum($payload, 'rackType', self::RACK_TYPES);
        Validator::enum($payload, 'networkBillingModel', self::BILLING_MODELS);
        if ($payload['consent'] !== true) {
            throw new ApiException(422, 'Bitte stimmen Sie der Verarbeitung Ihrer Anfrage zu.', 'validation_failed');
        }
        if (!in_array((int) $payload['contractMonths'], self::CONTRACT_TERMS, true)) {
            throw new ApiException(422, 'Die gewählte Vertragslaufzeit ist nicht verfügbar.', 'validation_failed', [
                'field' => 'contractMonths',
                'allowed' => self::CONTRACT_TERMS,
            ]);
        }

        $rackUnits = (int) $payload['rackUnits'];
        if (($payload['rackType'] === 'half' && $rackUnits !== 22) || ($payload['rackType'] === 'full' && $rackUnits !== 44)) {
            throw new ApiException(422, 'Racktyp und Höheneinheiten passen nicht zusammen.', 'validation_failed');
        }

        $plan = $this->plans->findById((string) $payload['planId']);
        if (($plan['status'] ?? null) !== 'active') {
            throw new ApiException(422, 'Der gewählte Tarif ist nicht verfügbar.', 'validation_failed');
        }
        $location = $this->locations->findById((string) $payload['locationId']);
        if (($location['status'] ?? null) !== 'active') {
            throw new ApiException(422, 'Der gewählte Datacenter-Standort ist nicht verfügbar.', 'validation_failed');
        }
        $planLocationIds = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            is_array($plan['locationIds'] ?? null) ? $plan['locationIds'] : [],
        ));
        // Keine Einschränkung bedeutet bewusst „an allen aktiven Standorten“.
        // Sobald IDs gepflegt sind, ist ausschließlich diese Whitelist gültig.
        if ($planLocationIds !== [] && !in_array((string) $location['_id'], $planLocationIds, true)) {
            throw new ApiException(422, 'Der gewählte Tarif ist an diesem Datacenter-Standort nicht verfügbar.', 'validation_failed', [
                'field' => 'locationId',
                'planId' => (string) $plan['_id'],
            ]);
        }
        $bandwidth = $this->bandwidthOptions->findById((string) $payload['bandwidthOptionId']);
        if (($bandwidth['status'] ?? null) !== 'active') {
            throw new ApiException(422, 'Die gewählte Bandbreite ist nicht verfügbar.', 'validation_failed');
        }
        $bandwidthBillingModel = (string) ($bandwidth['billingModel'] ?? 'flat');
        if ($bandwidthBillingModel !== $payload['networkBillingModel']) {
            throw new ApiException(422, 'Die Bandbreite gehört nicht zum gewählten Tarifmodell.', 'validation_failed');
        }

        $data = Validator::only($payload, [
            'company', 'contactName', 'email', 'phone', 'locationId', 'planId', 'bandwidthOptionId', 'message', 'consent',
            'rackUnits', 'rackType', 'powerKw', 'networkBillingModel', 'contractMonths', 'billingAddress',
        ]);
        $data['email'] = strtolower(trim((string) $data['email']));
        $data['rackUnits'] = $rackUnits;
        $data['powerKw'] = (float) $data['powerKw'];
        $data['contractMonths'] = (int) $data['contractMonths'];
        $data['billingAddress'] = BillingAddress::normalize($data['billingAddress']);
        // Namen und Kerndaten werden zusätzlich als Snapshot gespeichert. So
        // bleibt der Lead auch nach späteren Katalogänderungen nachvollziehbar.
        $data['configurationSnapshot'] = [
            'locationId' => (string) $location['_id'],
            'locationCode' => (string) ($location['code'] ?? ''),
            'locationName' => (string) ($location['name'] ?? 'Datacenter-Standort'),
            'locationCity' => (string) ($location['address']['city'] ?? ''),
            'planId' => (string) $plan['_id'],
            'planCode' => (string) ($plan['code'] ?? ''),
            'planName' => (string) $plan['name'],
            'planMonthlyPrice' => (float) ($plan['monthlyPrice'] ?? 0),
            'planSetupFee' => (float) ($plan['setupFee'] ?? 0),
            'bandwidthOptionId' => (string) $bandwidth['_id'],
            'bandwidthCode' => (string) ($bandwidth['code'] ?? ''),
            'bandwidthName' => (string) $bandwidth['name'],
            'bandwidthMonthlyPrice' => (float) ($bandwidth['monthlyPrice'] ?? 0),
            'bandwidthMbps' => (int) $bandwidth['committedMbps'],
            'symmetric' => (bool) ($bandwidth['symmetric'] ?? true),
            'includedTransferTb' => $bandwidth['includedTransferTb'] ?? null,
        ];

        $inquiry = $this->inquiries->create($data);
        $configurationSummary = $this->configurationSummary($data, $bandwidth);
        $leadTicket = $this->tickets->createLeadTicket($data, (string) $inquiry['_id'], $configurationSummary);
        $inquiry = $this->inquiries->update((string) $inquiry['_id'], [
            'ticketId' => $leadTicket['id'],
            'ticketNumber' => $leadTicket['number'],
        ]);
        try {
            $this->notifications->sendInquiryReceived(
                email: (string) $data['email'],
                name: (string) $data['contactName'],
                company: (string) $data['company'],
                planName: (string) $plan['name'],
                ticketNumber: (string) $leadTicket['number'],
                configurationSummary: $configurationSummary,
                offersUrl: $this->frontendUrl . '/angebote',
            );
            $inquiry = $this->inquiries->update((string) $inquiry['_id'], [
                'confirmationMailStatus' => 'sent',
                'confirmationMailSentAt' => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (MailDeliveryException) {
            // Die Anfrage bleibt sicher gespeichert. Der Status ermöglicht
            // später einen kontrollierten Retry, ohne den Lead zu verlieren.
            $inquiry = $this->inquiries->update((string) $inquiry['_id'], [
                'confirmationMailStatus' => 'failed',
            ]);
        }

        return DocumentSerializer::serialize($inquiry);
    }

    public function listInquiries(AuthContext $auth, int $page, int $limit, ?string $status): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::enum(['status' => $status], 'status', self::INQUIRY_STATUSES);
        return DocumentSerializer::serialize($this->inquiries->list($page, $limit, $status));
    }

    /** @param array<string, mixed> $payload */
    public function updateInquiry(AuthContext $auth, string $id, array $payload): array
    {
        $this->requirePlatformAdmin($auth);
        Validator::enum($payload, 'status', self::INQUIRY_STATUSES);
        $data = Validator::only($payload, ['status', 'internalNotes']);
        $inquiry = $this->inquiries->update($id, $data);
        if (isset($data['status'])) {
            $this->tickets->syncLeadStatus($id, (string) $data['status']);
        }
        return DocumentSerializer::serialize($inquiry);
    }

    public function deleteInquiry(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        $this->tickets->deleteLeadByInquiry($id);
        $this->inquiries->softDelete($id);
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Diese Aktion ist Plattform-Administratoren vorbehalten.', 'forbidden');
        }
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $bandwidth */
    private function configurationSummary(array $data, array $bandwidth): string
    {
        $billingModel = $data['networkBillingModel'] === 'volume' ? 'Volumentarif' : 'Flatrate';
        $network = sprintf('%s, %s Mbit/s%s', $billingModel, $bandwidth['committedMbps'], ($bandwidth['symmetric'] ?? true) ? ' symmetrisch' : '');
        if ($data['networkBillingModel'] === 'volume' && !empty($bandwidth['includedTransferTb'])) {
            $network .= sprintf(', %s TB inklusive', $bandwidth['includedTransferTb']);
        }

        return implode("\n", [
            sprintf(
                'Standort: %s%s%s',
                $data['configurationSnapshot']['locationCode'],
                $data['configurationSnapshot']['locationName'] !== '' ? ' · ' . $data['configurationSnapshot']['locationName'] : '',
                $data['configurationSnapshot']['locationCity'] !== '' ? ' (' . $data['configurationSnapshot']['locationCity'] . ')' : '',
            ),
            sprintf('Rackspace: %d HE', $data['rackUnits']),
            sprintf('Leistungsaufnahme: %s kW', str_replace('.', ',', (string) $data['powerKw'])),
            'Netzwerk: ' . $network,
            sprintf('Vertragslaufzeit: %d Monate', $data['contractMonths']),
        ]);
    }
}
