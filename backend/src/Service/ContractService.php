<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\ContractRepository;
use ColoManager\Repository\ContractDocumentRepository;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\TicketAttachmentRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\Validator;
use MongoDB\BSON\UTCDateTime;

/** Geschäftslogik für Entwurf, Prüfung und Aktivierung von Verträgen. */
final readonly class ContractService
{
    private const STATUSES = ['draft', 'pending_assignment', 'review', 'awaiting_signature', 'signed', 'onboarding', 'scheduled', 'active', 'terminated', 'expired', 'cancelled'];
    private const EDITABLE_STATUSES = ['draft', 'pending_assignment', 'review'];
    private const DEFAULT_LEGAL_TERMS = <<<'TEXT'
1. Vertragsgegenstand
Der Anbieter stellt dem Kunden die im Leistungsverzeichnis beschriebenen Colocation-, Strom-, Netzwerk- und Serviceleistungen am vereinbarten Datacenter-Standort bereit. Der konkrete Leistungsumfang ergibt sich ausschließlich aus den Vertragspositionen dieses Dokuments.

2. Bereitstellung und Mitwirkung
Bereitstellungstermine setzen die technische Freigabe, verfügbare Kapazitäten und die rechtzeitige Mitwirkung des Kunden voraus. Der Kunde benennt erreichbare technische und kaufmännische Ansprechpartner und hält die geltenden Zutritts-, Sicherheits- und Betriebsregeln ein.

3. Vergütung und Zahlungsbedingungen
Alle Preise verstehen sich netto zuzüglich der gesetzlichen Umsatzsteuer. Wiederkehrende Entgelte werden entsprechend dem vereinbarten Abrechnungsintervall im Voraus berechnet. Einmalige Leistungen werden nach Bereitstellung fällig. Rechnungen sind innerhalb von 14 Kalendertagen ohne Abzug zahlbar.

4. Laufzeit und Kündigung
Der Vertrag beginnt zum ausgewiesenen Startdatum und läuft zunächst für die vereinbarte Mindestlaufzeit. Er verlängert sich um den ausgewiesenen Zeitraum, sofern er nicht mit der vereinbarten Frist zum Laufzeitende in Textform gekündigt wird. Das Recht zur außerordentlichen Kündigung bleibt unberührt.

5. Betrieb, Verfügbarkeit und Wartung
Der Anbieter betreibt die vereinbarte Infrastruktur nach dem Stand der Technik. Angekündigte Wartungsfenster, höhere Gewalt sowie Störungen außerhalb des Verantwortungsbereichs des Anbieters bleiben bei Verfügbarkeitsberechnungen unberücksichtigt, soweit gesetzlich zulässig.

6. Haftung
Die Parteien haften unbeschränkt bei Vorsatz, grober Fahrlässigkeit sowie bei Verletzung von Leben, Körper oder Gesundheit. Im Übrigen ist die Haftung bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten auf den vertragstypischen, vorhersehbaren Schaden begrenzt. Zwingende gesetzliche Haftung bleibt unberührt.

7. Datenschutz und Vertraulichkeit
Beide Parteien behandeln vertrauliche Informationen geheim und verarbeiten personenbezogene Daten ausschließlich im Rahmen der geltenden Datenschutzgesetze. Erforderliche Vereinbarungen zur Auftragsverarbeitung werden gesondert geschlossen.

8. Schlussbestimmungen
Änderungen und Ergänzungen bedürfen mindestens der Textform, soweit keine strengere gesetzliche Form vorgeschrieben ist. Es gilt deutsches Recht. Gerichtsstand ist, soweit zulässig, der Sitz des Anbieters. Sollten einzelne Bestimmungen unwirksam sein, bleibt die Wirksamkeit der übrigen Regelungen unberührt.
TEXT;

    public function __construct(
        private ContractRepository $contracts,
        private CustomerRepository $customers,
        private UserRepository $users,
        private ContractDocumentRepository $contractDocuments,
        private TicketAttachmentRepository $attachments,
        private TicketRepository $tickets,
        private TicketMessageRepository $messages,
    ) {
    }

    public function list(AuthContext $auth, int $page, int $limit, ?string $status, ?string $customerId, ?string $search): array
    {
        $this->requireManager($auth);
        $this->contracts->activateDueContracts();
        Validator::enum(['status' => $status], 'status', self::STATUSES);
        if ($customerId !== null) {
            $this->customers->findById($customerId);
        }
        $result = $this->contracts->list($page, $limit, $status, $customerId, $search);
        $result['items'] = array_map($this->safeContract(...), $result['items']);
        return DocumentSerializer::serialize($result);
    }

    public function show(AuthContext $auth, string $id): array
    {
        $this->requireManager($auth);
        $this->contracts->activateDueContracts();
        return DocumentSerializer::serialize($this->safeContract($this->contracts->findById($id)));
    }

    /** @param array<string, mixed> $payload */
    public function create(AuthContext $auth, array $payload): array
    {
        $this->requireManager($auth);
        Validator::required($payload, ['customerId', 'title', 'contractMonths', 'lineItems']);
        $customerId = (string) $payload['customerId'];
        $this->customers->findById($customerId);
        $draft = $this->normalizeCommercialData($payload);
        $draft['legalTerms'] ??= self::DEFAULT_LEGAL_TERMS;
        $agreement = $this->agreementData($payload, $customerId);
        $user = $this->users->findById($auth->userId);
        $contract = $this->contracts->create($draft + $agreement + [
            'customerId' => $customerId,
            'status' => 'draft',
            'counterparty' => $this->counterpartyFromCustomer($customerId),
            'source' => ($agreement['agreementType'] ?? 'base') === 'addendum' ? 'manual_addendum' : 'manual',
            'createdBy' => $this->actor($auth, $user),
            'updatedBy' => $this->actor($auth, $user),
        ]);
        return DocumentSerializer::serialize($this->safeContract($contract));
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireManager($auth);
        $contract = $this->contracts->findById($id);
        if (!in_array((string) ($contract['status'] ?? ''), self::EDITABLE_STATUSES, true)) {
            throw new ApiException(409, 'Aktive oder beendete Verträge können nicht direkt überschrieben werden.', 'contract_not_editable');
        }
        $data = $this->normalizeCommercialData($payload, false);
        if (array_key_exists('customerId', $payload)) {
            if (empty($payload['customerId'])) {
                $data['customerId'] = null;
            } else {
                $customerId = (string) $payload['customerId'];
                $this->customers->findById($customerId);
                $data['customerId'] = $customerId;
                $data['counterparty'] = $this->counterpartyFromCustomer($customerId);
                if (($contract['status'] ?? null) === 'pending_assignment') {
                    $data['status'] = 'review';
                }
            }
        }
        $user = $this->users->findById($auth->userId);
        $data['updatedBy'] = $this->actor($auth, $user);
        $updated = $this->contracts->update($id, $data);
        if (!empty($updated['sourceLead']['ticketId'])) {
            $this->tickets->update((string) $updated['sourceLead']['ticketId'], ['leadProcess.contractStatus' => (string) $updated['status']]);
        }
        return DocumentSerializer::serialize($this->safeContract($updated));
    }

    /** Aktiviert einen vollständig geprüften Entwurf und friert dessen Snapshot ein. */
    public function activate(AuthContext $auth, string $id): array
    {
        $this->requireManager($auth);
        $contract = $this->contracts->findById($id);
        if (($contract['status'] ?? null) === 'active') {
            return DocumentSerializer::serialize($this->safeContract($contract));
        }
        if (($contract['source'] ?? null) === 'accepted_lead_offer') {
            if (!in_array((string) ($contract['status'] ?? ''), ['scheduled'], true)
                || ($contract['onboarding']['status'] ?? null) !== 'completed') {
                throw new ApiException(409, 'Ein Lead-Vertrag wird erst durch das abgeschlossene technische Onboarding aktiviert.', 'contract_activation_not_allowed');
            }
        } elseif (($contract['status'] ?? null) !== 'signed' || ($contract['signature']['status'] ?? null) !== 'signed_received') {
            throw new ApiException(409, 'Der Vertrag kann erst nach Eingang der unterschriebenen PDF-Fassung aktiviert werden.', 'signed_contract_required');
        } else {
            $contract = $this->activateSignedContract($contract, $auth);
        }
        $this->contracts->activateDueContracts();
        return DocumentSerializer::serialize($this->safeContract($this->contracts->findById($id)));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requireManager($auth);
        $contract = $this->contracts->findById($id);
        // Plattform-Admins dürfen auf ausdrücklichen Wunsch auch laufende oder
        // bereits beendete Verträge aus der operativen Ansicht entfernen. Die
        // Löschung bleibt als Soft-Delete in MongoDB nachvollziehbar. Vertrieb
        // darf weiterhin ausschließlich noch nicht wirksame Entwürfe löschen.
        if (!$auth->isPlatformAdmin() && !in_array((string) ($contract['status'] ?? ''), self::EDITABLE_STATUSES, true)) {
            throw new ApiException(409, 'Aktive oder beendete Verträge werden archiviert und können nicht gelöscht werden.', 'contract_delete_not_allowed');
        }
        $this->contracts->softDelete($id);
    }

    /**
     * Merkt die ordentliche Kündigung eines Kunden zum aktuellen Laufzeitende
     * vor. Der Vertrag bleibt bis dahin aktiv und seine Leistungen bleiben im
     * Portal sichtbar. Wiederholte Aufrufe sind bewusst idempotent.
     *
     * @param array<string, mixed> $payload
     */
    public function requestTermination(AuthContext $auth, string $id, array $payload): array
    {
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'customer_required');
        }
        $contract = $this->contracts->findById($id);
        if ((string) ($contract['customerId'] ?? '') !== $auth->customerId) {
            throw new ApiException(404, 'Der Vertrag wurde nicht gefunden.', 'contract_not_found');
        }
        if (($contract['termination']['status'] ?? null) === 'requested') {
            return DocumentSerializer::serialize($this->customerContract($contract));
        }
        if (!in_array((string) ($contract['status'] ?? ''), ['scheduled', 'active'], true)) {
            throw new ApiException(409, 'Nur vorgemerkte oder aktive Verträge können ordentlich gekündigt werden.', 'contract_termination_not_allowed');
        }
        if (empty($contract['endDate'])) {
            throw new ApiException(409, 'Für diesen Vertrag ist noch kein Laufzeitende hinterlegt.', 'contract_end_date_missing');
        }

        $reason = $this->terminationReason($payload['reason'] ?? null);
        $updated = $this->contracts->update($id, [
            'termination' => [
                'status' => 'requested',
                'type' => 'ordinary',
                'effectiveAt' => $contract['endDate'],
                'requestedAt' => new UTCDateTime(),
                'requestedBy' => [
                    'type' => 'customer',
                    'userId' => $auth->userId,
                    'email' => $auth->email,
                ],
                'reason' => $reason,
            ],
        ]);
        return DocumentSerializer::serialize($this->customerContract($updated));
    }

    /**
     * Beendet einen laufenden Vertrag durch einen Plattform-Admin vorzeitig.
     * Diese Aktion steht absichtlich weder Kunden noch Vertriebsmitarbeitern zu.
     *
     * @param array<string, mixed> $payload
     */
    public function terminate(AuthContext $auth, string $id, array $payload): array
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Nur Plattform-Admins dürfen Verträge vorzeitig beenden.', 'forbidden');
        }
        $contract = $this->contracts->findById($id);
        if (($contract['status'] ?? null) === 'terminated') {
            return DocumentSerializer::serialize($this->safeContract($contract));
        }
        if (!in_array((string) ($contract['status'] ?? ''), ['scheduled', 'active'], true)) {
            throw new ApiException(409, 'Nur vorgemerkte oder aktive Verträge können vorzeitig beendet werden.', 'contract_termination_not_allowed');
        }

        $now = new UTCDateTime();
        $user = $this->users->findById($auth->userId);
        $updated = $this->contracts->update($id, [
            'status' => 'terminated',
            'endDate' => $now,
            'terminatedAt' => $now,
            'termination' => [
                'status' => 'completed',
                'type' => 'early_by_admin',
                'effectiveAt' => $now,
                'requestedAt' => $now,
                'requestedBy' => $this->actor($auth, $user) + ['type' => 'platform_admin'],
                'reason' => $this->terminationReason($payload['reason'] ?? null),
            ],
            'updatedBy' => $this->actor($auth, $user),
        ]);
        if (!empty($updated['sourceLead']['ticketId'])) {
            $this->tickets->update((string) $updated['sourceLead']['ticketId'], ['leadProcess.contractStatus' => 'terminated']);
        }
        return DocumentSerializer::serialize($this->safeContract($updated));
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function document(AuthContext $auth, string $id): array
    {
        $this->requireManager($auth);
        $contract = $this->contracts->findById($id);
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        $attachmentId = (string) ($contract['sourceLead']['offerDocumentAttachmentId'] ?? '');
        if ($ticketId === '' || $attachmentId === '') {
            throw new ApiException(404, 'Für diesen Vertrag ist kein Angebotsdokument hinterlegt.', 'contract_document_not_found');
        }
        $document = $this->attachments->download($attachmentId, $ticketId);
        unset($document['messageId']);
        return $document;
    }

    /** Liefert die abgeschlossenen Vertragsunterlagen des angemeldeten Kunden. */
    public function listForCustomer(AuthContext $auth, int $page, int $limit): array
    {
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'customer_required');
        }
        $this->contracts->activateDueContracts();
        $result = $this->contracts->listForCustomer($auth->customerId, $page, $limit);
        $result['items'] = array_map($this->customerContract(...), $result['items']);
        return DocumentSerializer::serialize($result);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function signedDocumentForCustomer(AuthContext $auth, string $id): array
    {
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'customer_required');
        }
        $contract = $this->contracts->findById($id);
        if ((string) ($contract['customerId'] ?? '') !== $auth->customerId) {
            throw new ApiException(404, 'Der Vertrag wurde nicht gefunden.', 'contract_not_found');
        }
        $attachmentId = (string) ($contract['signature']['signedAttachmentId'] ?? '');
        if ($attachmentId === '') {
            throw new ApiException(404, 'Für diesen Vertrag liegt noch keine unterschriebene Fassung vor.', 'signed_contract_not_found');
        }
        if (($contract['signature']['signedDocumentStorage'] ?? null) === 'contract_documents') {
            return $this->contractDocuments->download($attachmentId, $id);
        }
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        if ($ticketId === '') {
            throw new ApiException(404, 'Für diesen Vertrag liegt noch keine unterschriebene Fassung vor.', 'signed_contract_not_found');
        }
        $legacy = $this->attachments->download($attachmentId, $ticketId);
        unset($legacy['messageId']);
        return $legacy;
    }

    /** Finalisiert die Laufzeit nach erfolgreicher Portalaktivierung im Onboarding. */
    public function activateFromOnboarding(string $id): array
    {
        $contract = $this->contracts->findById($id);
        if (($contract['signature']['status'] ?? null) !== 'signed_received') {
            throw new ApiException(409, 'Die unterschriebene Vertragsfassung fehlt.', 'signed_contract_required');
        }
        if (empty($contract['customerId']) || empty($contract['plannedStartDate']) || empty($contract['lineItems'])) {
            throw new ApiException(422, 'Der Vertrag ist für die Aktivierung noch unvollständig.', 'contract_incomplete');
        }
        $months = max(1, (int) ($contract['contractMonths'] ?? 12));
        $start = $contract['plannedStartDate'] instanceof UTCDateTime
            ? $contract['plannedStartDate']->toDateTimeImmutable()
            : new \DateTimeImmutable((string) $contract['plannedStartDate']);
        $end = $start->modify('+' . $months . ' months')->modify('-1 day');
        $today = new \DateTimeImmutable('today');
        $status = $start <= $today ? 'active' : 'scheduled';
        return $this->contracts->update($id, [
            'status' => $status,
            'startDate' => $start,
            'endDate' => $end,
            'activatedAt' => $status === 'active' ? new UTCDateTime() : null,
            'onboarding.status' => 'completed',
            'onboarding.completedAt' => new UTCDateTime(),
            'updatedBy' => ['type' => 'system', 'name' => 'Onboarding-Prozess'],
        ]);
    }

    /** Liefert einen vorbefüllten, noch nicht gespeicherten Entwurf für den Lead-Editor. */
    public function offerDraftFromLead(array $ticket): array
    {
        $existing = $ticket['leadProcess']['offer']['draft'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }
        $configuration = $ticket['leadConfiguration'] ?? [];
        $items = [];
        $planName = (string) ($configuration['planName'] ?? 'Colocation-Leistung');
        $items[] = [
            'type' => 'plan',
            'sourceId' => $configuration['planId'] ?? null,
            'name' => $planName,
            'description' => implode(' · ', array_filter([
                !empty($configuration['rackUnits']) ? (string) $configuration['rackUnits'] . ' HE Rackspace' : null,
                !empty($configuration['powerKw']) ? (string) $configuration['powerKw'] . ' kW Leistung' : null,
            ])),
            'quantity' => 1,
            'unit' => 'Monat',
            'monthlyUnitPrice' => (float) ($configuration['planMonthlyPrice'] ?? 0),
            'oneTimeUnitPrice' => (float) ($configuration['planSetupFee'] ?? 0),
        ];
        if (!empty($configuration['bandwidthName'])) {
            $items[] = [
                'type' => 'bandwidth',
                'sourceId' => $configuration['bandwidthOptionId'] ?? null,
                'name' => (string) $configuration['bandwidthName'],
                'description' => implode(' · ', array_filter([
                    isset($configuration['bandwidthMbps']) ? (string) $configuration['bandwidthMbps'] . ' Mbit/s' : null,
                    ($configuration['symmetric'] ?? false) ? 'symmetrisch' : null,
                    ($configuration['networkBillingModel'] ?? null) === 'volume' ? 'Volumentarif' : 'Flatrate',
                ])),
                'quantity' => 1,
                'unit' => 'Monat',
                'monthlyUnitPrice' => (float) ($configuration['bandwidthMonthlyPrice'] ?? 0),
                'oneTimeUnitPrice' => 0,
            ];
        }
        return $this->normalizeCommercialData([
            'title' => 'Colocation · ' . $planName,
            'validUntil' => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'),
            'plannedStartDate' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'contractMonths' => (int) ($configuration['contractMonths'] ?? 12),
            'noticeMonths' => 3,
            'renewalMonths' => 12,
            'billingInterval' => 'monthly',
            'notes' => 'Bereitstellung nach technischer Prüfung und gemeinsamer Terminabstimmung.',
            'lineItems' => $items,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function prepareOfferDraft(array $payload): array
    {
        Validator::required($payload, ['title', 'validUntil', 'plannedStartDate', 'contractMonths', 'lineItems']);
        return $this->normalizeCommercialData($payload);
    }

    /** Erzeugt genau einen Vertragsentwurf aus der angenommenen Angebotsrunde. */
    public function createFromAcceptedLead(array $ticket, array $offer): array
    {
        $ticketId = (string) $ticket['_id'];
        $round = max(1, (int) ($offer['round'] ?? 1));
        $existing = $this->contracts->findByLeadOffer($ticketId, $round);
        if ($existing !== null) {
            return $existing;
        }
        $draft = is_array($offer['draft'] ?? null) ? $offer['draft'] : $this->offerDraftFromLead($ticket);
        $offerCounterparty = is_array($offer['counterparty'] ?? null) ? $offer['counterparty'] : [];
        return $this->contracts->create($draft + [
            'legalTerms' => self::DEFAULT_LEGAL_TERMS,
            'agreementType' => 'base',
            'parentContractId' => null,
            'status' => 'pending_assignment',
            'customerId' => null,
            'source' => 'accepted_lead_offer',
            'counterparty' => [
                'company' => (string) ($offerCounterparty['company'] ?? $ticket['requester']['company'] ?? ''),
                'contactName' => (string) ($offerCounterparty['contactName'] ?? $ticket['requester']['name'] ?? ''),
                'email' => (string) ($offerCounterparty['email'] ?? $ticket['requester']['email'] ?? ''),
                'phone' => (string) ($offerCounterparty['phone'] ?? $ticket['requester']['phone'] ?? ''),
                // Der Vertrag übernimmt die Anschrift der angenommenen
                // Angebotsrunde und nicht möglicherweise geänderte Lead-Daten.
                'billingAddress' => $offerCounterparty['billingAddress'] ?? $ticket['requester']['billingAddress'] ?? null,
            ],
            'sourceLead' => [
                'ticketId' => $ticketId,
                'ticketNumber' => (string) ($ticket['number'] ?? ''),
                'inquiryId' => !empty($ticket['inquiryId']) ? (string) $ticket['inquiryId'] : null,
                'offerRound' => $round,
                'offerNumber' => (string) ($offer['offerNumber'] ?? ''),
                'offerDocumentAttachmentId' => (string) ($offer['attachmentId'] ?? ''),
                'offerDocumentName' => (string) ($offer['documentName'] ?? ''),
                'acceptedAt' => $offer['decisionAt'] ?? new UTCDateTime(),
            ],
            'createdBy' => ['type' => 'system', 'name' => 'Lead-Prozess'],
            'updatedBy' => ['type' => 'system', 'name' => 'Lead-Prozess'],
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeCommercialData(array $payload, bool $requireItems = true): array
    {
        Validator::number($payload, 'contractMonths', 1, 120);
        Validator::number($payload, 'noticeMonths', 0, 36);
        Validator::number($payload, 'renewalMonths', 0, 60);
        Validator::enum($payload, 'billingInterval', ['monthly', 'quarterly', 'yearly']);
        $data = Validator::only($payload, [
            'title', 'validUntil', 'plannedStartDate', 'contractMonths', 'noticeMonths', 'renewalMonths',
            'billingInterval', 'notes', 'legalTerms', 'lineItems', 'customerId', 'locationId',
        ]);
        foreach (['title', 'notes', 'legalTerms'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim(strip_tags((string) $data[$field]));
            }
        }
        if (isset($data['title']) && (mb_strlen((string) $data['title']) < 3 || mb_strlen((string) $data['title']) > 160)) {
            throw new ApiException(422, 'Der Vertragstitel muss zwischen 3 und 160 Zeichen lang sein.', 'validation_failed', ['field' => 'title']);
        }
        if (isset($data['legalTerms']) && (mb_strlen((string) $data['legalTerms']) < 100 || mb_strlen((string) $data['legalTerms']) > 20000)) {
            throw new ApiException(422, 'Die Vertragsbedingungen müssen zwischen 100 und 20.000 Zeichen lang sein.', 'validation_failed', ['field' => 'legalTerms']);
        }
        if (isset($data['validUntil'])) {
            $data['validUntil'] = $this->dateString($data['validUntil'], 'validUntil');
        }
        if (isset($data['plannedStartDate'])) {
            $data['plannedStartDate'] = $this->dateString($data['plannedStartDate'], 'plannedStartDate');
        }
        foreach (['contractMonths', 'noticeMonths', 'renewalMonths'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        if (array_key_exists('lineItems', $data)) {
            $data['lineItems'] = $this->lineItems($data['lineItems']);
            $data['totals'] = [
                'monthly' => round(array_sum(array_column($data['lineItems'], 'monthlyTotal')), 2),
                'oneTime' => round(array_sum(array_column($data['lineItems'], 'oneTimeTotal')), 2),
                'currency' => 'EUR',
                'taxMode' => 'net',
            ];
        } elseif ($requireItems) {
            throw new ApiException(422, 'Mindestens eine Vertragsposition ist erforderlich.', 'validation_failed', ['field' => 'lineItems']);
        }
        if ($requireItems) {
            $data['billingInterval'] ??= 'monthly';
            $data['noticeMonths'] ??= 3;
            $data['renewalMonths'] ??= 12;
        }
        return $data;
    }

    /**
     * Prüft die unveränderliche Vertragsbeziehung. Zusatzleistungen werden als
     * Nachtrag mit eigenem Leistungs- und Preissnapshot gespeichert.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function agreementData(array $payload, string $customerId): array
    {
        $agreementType = (string) ($payload['agreementType'] ?? 'base');
        if (!in_array($agreementType, ['base', 'addendum'], true)) {
            throw new ApiException(422, 'Die Vertragsart ist ungültig.', 'validation_failed', ['field' => 'agreementType']);
        }
        if ($agreementType === 'base') {
            return ['agreementType' => 'base', 'parentContractId' => null, 'parentContractNumber' => null];
        }

        $parentId = trim((string) ($payload['parentContractId'] ?? ''));
        if ($parentId === '') {
            throw new ApiException(422, 'Ein Nachtrag benötigt einen Basisvertrag.', 'validation_failed', ['field' => 'parentContractId']);
        }
        $parent = $this->contracts->findById($parentId);
        if ((string) ($parent['customerId'] ?? '') !== $customerId) {
            throw new ApiException(422, 'Basisvertrag und Nachtrag müssen demselben Kunden zugeordnet sein.', 'contract_customer_mismatch');
        }
        if (!in_array((string) ($parent['status'] ?? ''), ['scheduled', 'active'], true)) {
            throw new ApiException(409, 'Nachträge können nur zu vorgemerkten oder aktiven Verträgen angelegt werden.', 'parent_contract_not_active');
        }
        return [
            'agreementType' => 'addendum',
            'parentContractId' => $parentId,
            'parentContractNumber' => (string) ($parent['number'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $contract @return array<string, mixed> */
    private function activateSignedContract(array $contract, AuthContext $auth): array
    {
        if (empty($contract['customerId']) || empty($contract['plannedStartDate']) || empty($contract['lineItems'])) {
            throw new ApiException(422, 'Der Vertrag ist für die Aktivierung noch unvollständig.', 'contract_incomplete');
        }
        $months = max(1, (int) ($contract['contractMonths'] ?? 12));
        $start = $contract['plannedStartDate'] instanceof UTCDateTime
            ? $contract['plannedStartDate']->toDateTimeImmutable()
            : new \DateTimeImmutable((string) $contract['plannedStartDate']);
        $end = $start->modify('+' . $months . ' months')->modify('-1 day');
        $status = $start <= new \DateTimeImmutable('today') ? 'active' : 'scheduled';
        $user = $this->users->findById($auth->userId);
        return $this->contracts->update((string) $contract['_id'], [
            'status' => $status,
            'startDate' => $start,
            'endDate' => $end,
            'activatedAt' => $status === 'active' ? new UTCDateTime() : null,
            'updatedBy' => $this->actor($auth, $user),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function lineItems(mixed $items): array
    {
        if (!is_array($items) || $items === [] || count($items) > 30) {
            throw new ApiException(422, 'Bitte hinterlegen Sie zwischen einer und 30 Vertragspositionen.', 'validation_failed', ['field' => 'lineItems']);
        }
        $result = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new ApiException(422, 'Eine Vertragsposition ist ungültig.', 'validation_failed', ['field' => 'lineItems.' . $index]);
            }
            $name = trim(strip_tags((string) ($item['name'] ?? '')));
            $quantity = $item['quantity'] ?? 1;
            $monthly = $item['monthlyUnitPrice'] ?? 0;
            $oneTime = $item['oneTimeUnitPrice'] ?? 0;
            if ($name === '' || mb_strlen($name) > 160 || !is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($monthly) || (float) $monthly < 0 || !is_numeric($oneTime) || (float) $oneTime < 0) {
                throw new ApiException(422, 'Name, Menge oder Preis einer Vertragsposition ist ungültig.', 'validation_failed', ['field' => 'lineItems.' . $index]);
            }
            $quantity = round((float) $quantity, 2);
            $monthly = round((float) $monthly, 2);
            $oneTime = round((float) $oneTime, 2);
            $result[] = [
                'type' => in_array(($item['type'] ?? 'other'), ['plan', 'bandwidth', 'power', 'service', 'other'], true) ? $item['type'] : 'other',
                'sourceId' => !empty($item['sourceId']) ? (string) $item['sourceId'] : null,
                'name' => $name,
                'description' => mb_substr(trim(strip_tags((string) ($item['description'] ?? ''))), 0, 600),
                'quantity' => $quantity,
                'unit' => mb_substr(trim(strip_tags((string) ($item['unit'] ?? 'Stk.'))), 0, 30),
                'monthlyUnitPrice' => $monthly,
                'oneTimeUnitPrice' => $oneTime,
                'monthlyTotal' => round($monthly * $quantity, 2),
                'oneTimeTotal' => round($oneTime * $quantity, 2),
            ];
        }
        return $result;
    }

    private function dateString(mixed $value, string $field): string
    {
        try {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException();
            }
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            throw new ApiException(422, 'Ungültiges Datum.', 'validation_failed', ['field' => $field]);
        }
    }

    /** @return array<string, mixed> */
    private function counterpartyFromCustomer(string $customerId): array
    {
        $customer = $this->customers->findById($customerId);
        return [
            'company' => (string) ($customer['name'] ?? ''),
            'contactName' => (string) ($customer['contactPerson']['name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            // Dieser Wert wird beim Zuordnen eingefroren. Spätere Änderungen
            // am Kundenstamm verändern bereits versendete Verträge nicht.
            'billingAddress' => $customer['billingAddress'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function actor(AuthContext $auth, array $user): array
    {
        return ['userId' => $auth->userId, 'name' => (string) ($user['name'] ?? $auth->email), 'email' => $auth->email];
    }

    private function requireManager(AuthContext $auth): void
    {
        if ($auth->isPlatformAdmin()) {
            return;
        }
        if (!$auth->isDatacenterStaff()) {
            throw new ApiException(403, 'Kein Zugriff auf die Vertragsverwaltung.', 'forbidden');
        }
        $user = $this->users->findById($auth->userId);
        if (mb_strtolower(trim((string) ($user['department'] ?? ''))) !== 'vertrieb') {
            throw new ApiException(403, 'Die Vertragsverwaltung ist der Plattform-Administration und dem Vertrieb vorbehalten.', 'forbidden');
        }
    }

    /** Liefert nur den für das Kundenportal bestimmten Vertragssnapshot. */
    private function customerContract(array $contract): array
    {
        unset($contract['signature']['tokenHash'], $contract['onboarding']['invitation']['tokenHash'], $contract['updatedBy'], $contract['createdBy']);
        $contract['hasSignedDocument'] = !empty($contract['signature']['signedAttachmentId']);
        return $contract;
    }

    private function terminationReason(mixed $value): string
    {
        $reason = trim(strip_tags(is_string($value) ? $value : ''));
        if (mb_strlen($reason) > 1000) {
            throw new ApiException(422, 'Der Kündigungshinweis darf höchstens 1.000 Zeichen lang sein.', 'validation_failed', ['field' => 'reason']);
        }
        return $reason;
    }

    /** Entfernt öffentliche Sicherheitstoken aus allen internen API-Antworten. */
    private function safeContract(array $contract): array
    {
        unset($contract['signature']['tokenHash']);
        unset($contract['onboarding']['invitation']['tokenHash']);
        return $contract;
    }
}
