<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\ContractRepository;
use ColoManager\Repository\ContractDocumentRepository;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\TicketAttachmentRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\ContractPdfGenerator;
use ColoManager\Support\BillingAddress;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\IcalendarGenerator;
use MongoDB\BSON\UTCDateTime;

/**
 * Führt angenommene Leads vom Vertragsversand über die Unterschrift bis zur
 * technischen Übergabe und einmaligen Aktivierung des Kundenportals.
 */
final readonly class LeadFulfillmentService
{
    private const SIGNATURE_TOKEN_DAYS = 30;
    private const INVITATION_TOKEN_HOURS = 72;
    private const MAX_SIGNED_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ContractRepository $contracts,
        private ContractDocumentRepository $contractDocuments,
        private TicketRepository $tickets,
        private TicketMessageRepository $messages,
        private TicketAttachmentRepository $attachments,
        private UserRepository $users,
        private CustomerRepository $customers,
        private ContractService $contractService,
        private ContractPdfGenerator $pdfGenerator,
        private IcalendarGenerator $calendarGenerator,
        private NotificationMailService $notifications,
        private string $frontendUrl,
    ) {
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function previewContract(AuthContext $auth, string $contractId): array
    {
        $this->requireSales($auth);
        $contract = $this->contracts->findById($contractId);
        $content = $this->pdfGenerator->generate($contract);
        return [
            'content' => $content,
            'name' => (string) $contract['number'] . '-Vertragsentwurf.pdf',
            'mimeType' => 'application/pdf',
            'size' => strlen($content),
        ];
    }

    /** Erzeugt die Vertragsfassung, speichert sie im Lead und versendet den persönlichen Link. */
    public function sendContract(AuthContext $auth, string $contractId): array
    {
        $this->requireSales($auth);
        $contract = $this->contracts->findById($contractId);
        $status = (string) ($contract['status'] ?? '');
        $emailStatus = (string) ($contract['signature']['emailStatus'] ?? '');
        if (!in_array($status, ['draft', 'review', 'awaiting_signature'], true)
            || ($status === 'awaiting_signature' && $emailStatus !== 'failed')) {
            throw new ApiException(409, 'Dieser Vertrag wurde bereits zur Unterschrift versendet oder ist nicht mehr bearbeitbar.', 'contract_signature_not_ready');
        }
        if (empty($contract['customerId'])) {
            throw new ApiException(422, 'Bitte ordnen Sie den Vertrag vor dem Versand einem Kunden zu.', 'validation_failed', ['field' => 'customerId']);
        }
        if (!BillingAddress::isComplete($contract['counterparty']['billingAddress'] ?? null)) {
            throw new ApiException(422, 'Bitte hinterlegen Sie vor dem Versand eine vollständige Rechnungsanschrift beim Kunden.', 'contract_billing_address_missing', ['field' => 'billingAddress']);
        }
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        $ticket = $ticketId !== '' ? $this->leadTicket($ticketId) : null;
        $recipientEmail = strtolower(trim((string) ($contract['counterparty']['email'] ?? $ticket['requester']['email'] ?? '')));
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Für den Vertrag ist keine gültige Empfängeradresse hinterlegt.', 'contract_recipient_missing');
        }
        $user = $this->users->findById($auth->userId);
        $content = $this->pdfGenerator->generate($contract);
        $documentName = (string) $contract['number'] . '-Vertrag.pdf';
        $attachment = $this->contractDocuments->storeContent(
            $contractId,
            'signature_copy',
            $content,
            $documentName,
            'application/pdf',
            $auth->userId,
        );
        // Bei Lead-Verträgen bleibt zusätzlich eine Kopie im internen
        // Ticketverlauf. Manuelle Verträge benötigen hingegen kein Ticket.
        if ($ticket !== null) {
            $message = $this->messages->create($ticketId, [
                'bodyHtml' => sprintf('<p><strong>Vertrag %s zur Unterschrift versendet.</strong> Der Anfragende kann die Vertragsfassung über den persönlichen Link herunterladen und unterschrieben zurückgeben.</p>', htmlspecialchars((string) $contract['number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'bodyText' => sprintf('Vertrag %s zur Unterschrift versendet. Die Rückgabe erfolgt über den persönlichen Vertragslink.', (string) $contract['number']),
                'author' => $this->actor($auth, $user),
                'internal' => true,
                'eventType' => 'contract_sent_for_signature',
            ]);
            $this->tickets->registerMessage($ticketId, true);
            $ticketAttachment = $this->attachments->storeContent($ticketId, (string) $message['_id'], $content, $documentName, 'application/pdf', $auth->userId);
            $this->messages->addAttachment((string) $message['_id'], $ticketAttachment);
            $this->tickets->registerAttachment($ticketId, true);
        }

        $token = bin2hex(random_bytes(32));
        $now = new UTCDateTime();
        $expiresAt = new UTCDateTime((time() + self::SIGNATURE_TOKEN_DAYS * 86400) * 1000);
        $signature = [
            'status' => 'sent',
            'tokenHash' => hash('sha256', $token),
            'expiresAt' => $expiresAt,
            'documentAttachmentId' => $attachment['id'],
            'documentStorage' => 'contract_documents',
            'documentName' => $attachment['name'],
            'documentSize' => $attachment['size'],
            'recipientEmail' => $recipientEmail,
            'sentAt' => $now,
            'sentBy' => $this->actor($auth, $user),
            'emailStatus' => 'pending',
        ];
        $this->contracts->update($contractId, ['status' => 'awaiting_signature', 'signature' => $signature]);
        if ($ticket !== null) {
            $this->tickets->update($ticketId, [
                'leadProcess.contractStatus' => 'awaiting_signature',
                'leadProcess.contractSignature' => [
                    'status' => 'sent',
                    'sentAt' => $now,
                    'documentName' => $attachment['name'],
                    'emailStatus' => 'pending',
                ],
                'status' => 'in_progress',
                'updatedBy' => $auth->userId,
            ]);
        }

        try {
            $this->notifications->sendContractForSignature(
                $recipientEmail,
                (string) ($contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $recipientEmail),
                (string) $contract['number'],
                $ticket !== null ? (string) $ticket['number'] : null,
                $this->frontendUrl . '/vertrag.html?token=' . $token,
                self::SIGNATURE_TOKEN_DAYS,
            );
            $this->contracts->update($contractId, ['signature.emailStatus' => 'sent']);
            if ($ticket !== null) {
                $this->tickets->update($ticketId, ['leadProcess.contractSignature.emailStatus' => 'sent']);
            }
        } catch (MailDeliveryException) {
            $this->contracts->update($contractId, ['signature.emailStatus' => 'failed']);
            if ($ticket !== null) {
                $this->tickets->update($ticketId, ['leadProcess.contractSignature.emailStatus' => 'failed']);
            }
            throw new ApiException(502, 'Der Vertrag wurde gespeichert, die E-Mail konnte jedoch nicht versendet werden.', 'contract_mail_failed');
        }
        return $this->safeContract($this->contracts->findById($contractId));
    }

    /** Liefert nur die Daten für die öffentliche Download- und Uploadseite. */
    public function publicContract(string $token): array
    {
        $contract = $this->contractByToken($token);
        return DocumentSerializer::serialize([
            'contractNumber' => $contract['number'],
            'title' => $contract['title'],
            'company' => $contract['counterparty']['company'] ?? null,
            'recipientName' => $contract['counterparty']['contactName'] ?? null,
            'status' => $contract['signature']['status'] ?? 'sent',
            'sentAt' => $contract['signature']['sentAt'] ?? null,
            'uploadedAt' => $contract['signature']['uploadedAt'] ?? null,
            'expiresAt' => $contract['signature']['expiresAt'] ?? null,
            'plannedStartDate' => $contract['plannedStartDate'] ?? null,
            'document' => [
                'name' => $contract['signature']['documentName'] ?? ((string) $contract['number'] . '-Vertrag.pdf'),
                'size' => $contract['signature']['documentSize'] ?? null,
                'downloadUrl' => '/api/v1/public/contracts/' . $token . '/document',
            ],
        ]);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function publicContractDocument(string $token): array
    {
        $contract = $this->contractByToken($token);
        $attachmentId = (string) ($contract['signature']['documentAttachmentId'] ?? '');
        if ($attachmentId === '') {
            throw new ApiException(404, 'Das Vertragsdokument wurde nicht gefunden.', 'contract_document_not_found');
        }
        if (($contract['signature']['documentStorage'] ?? null) === 'contract_documents') {
            return $this->contractDocuments->download($attachmentId, (string) $contract['_id']);
        }
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        if ($ticketId === '') {
            throw new ApiException(404, 'Das Vertragsdokument wurde nicht gefunden.', 'contract_document_not_found');
        }
        $legacy = $this->attachments->download($attachmentId, $ticketId);
        unset($legacy['messageId']);
        return $legacy;
    }

    /** @param list<array<string, mixed>> $files */
    public function uploadSignedContract(string $token, array $files): array
    {
        $contract = $this->contractByToken($token);
        if (($contract['signature']['status'] ?? null) === 'signed_received') {
            return $this->publicContract($token);
        }
        if (($contract['signature']['status'] ?? null) !== 'sent') {
            throw new ApiException(409, 'Für diesen Vertragslink ist kein Upload geöffnet.', 'contract_upload_not_open');
        }
        $file = $this->validateSignedDocument($files);
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        $ticket = $ticketId !== '' ? $this->leadTicket($ticketId) : null;
        $attachment = $this->contractDocuments->storeFile(
            (string) $contract['_id'],
            'signed_copy',
            (string) $file['tmp_name'],
            (string) $file['safe_name'],
            'application/pdf',
            (int) $file['size'],
            'public-contract-upload',
        );
        if ($ticket !== null) {
            $message = $this->messages->create($ticketId, [
                'bodyHtml' => sprintf('<p><strong>Unterschriebener Vertrag eingegangen.</strong> Die PDF-Fassung zu %s wurde über den persönlichen Vertragslink hochgeladen.</p>', htmlspecialchars((string) $contract['number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'bodyText' => sprintf('Unterschriebener Vertrag eingegangen. Die PDF-Fassung zu %s wurde hochgeladen.', (string) $contract['number']),
                'author' => ['type' => 'requester', 'name' => (string) ($ticket['requester']['name'] ?? $ticket['requester']['email'] ?? 'Anfragender'), 'email' => (string) ($ticket['requester']['email'] ?? '')],
                'internal' => true,
                'eventType' => 'signed_contract_received',
            ]);
            $this->tickets->registerMessage($ticketId, true);
            $ticketAttachment = $this->attachments->store($ticketId, (string) $message['_id'], (string) $file['tmp_name'], (string) $file['safe_name'], 'application/pdf', (int) $file['size'], 'public-contract-upload');
            $this->messages->addAttachment((string) $message['_id'], $ticketAttachment);
            $this->tickets->registerAttachment($ticketId, true);
        }
        $now = new UTCDateTime();
        $this->contracts->update((string) $contract['_id'], [
            'status' => 'signed',
            'signature.status' => 'signed_received',
            'signature.signedAttachmentId' => $attachment['id'],
            'signature.signedDocumentStorage' => 'contract_documents',
            'signature.signedDocumentName' => $attachment['name'],
            'signature.signedDocumentSize' => $attachment['size'],
            'signature.uploadedAt' => $now,
        ]);
        if ($ticket !== null) {
            $this->tickets->update($ticketId, [
                'leadProcess.contractStatus' => 'signed',
                'leadProcess.contractSignature.status' => 'signed_received',
                'leadProcess.contractSignature.uploadedAt' => $now,
                'leadProcess.contractSignature.signedDocumentName' => $attachment['name'],
                'updatedBy' => 'public-contract-upload',
            ]);
            $this->notifySalesOfSignedContract($ticket, $contract);
        }
        return $this->publicContract($token);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function signedDocument(AuthContext $auth, string $contractId): array
    {
        $this->requireTicketManager($auth);
        $contract = $this->contracts->findById($contractId);
        $attachmentId = (string) ($contract['signature']['signedAttachmentId'] ?? '');
        if ($attachmentId === '') {
            throw new ApiException(404, 'Die unterschriebene Vertragsfassung liegt noch nicht vor.', 'signed_contract_not_found');
        }
        if (($contract['signature']['signedDocumentStorage'] ?? null) === 'contract_documents') {
            return $this->contractDocuments->download($attachmentId, $contractId);
        }
        $ticketId = (string) ($contract['sourceLead']['ticketId'] ?? '');
        if ($ticketId === '') {
            throw new ApiException(404, 'Die unterschriebene Vertragsfassung liegt noch nicht vor.', 'signed_contract_not_found');
        }
        $legacy = $this->attachments->download($attachmentId, $ticketId);
        unset($legacy['messageId']);
        return $legacy;
    }

    /** Übergibt den unterschriebenen Lead verbindlich an einen Techniker. */
    public function handoffOnboarding(AuthContext $auth, string $ticketId, string $technicianId): array
    {
        $this->requireSales($auth);
        $ticket = $this->leadTicket($ticketId);
        $contract = $this->contractForTicket($ticket);
        if (($contract['signature']['status'] ?? null) !== 'signed_received') {
            throw new ApiException(409, 'Die technische Übergabe ist erst nach Eingang des unterschriebenen Vertrags möglich.', 'signed_contract_required');
        }
        if (empty($contract['customerId'])) {
            throw new ApiException(409, 'Der Vertrag muss vor der Übergabe einem Kunden zugeordnet sein.', 'contract_customer_required');
        }
        $technician = $this->users->findTechnician($technicianId);
        $salesContact = $this->users->findById($auth->userId);
        $now = new UTCDateTime();
        $onboarding = [
            'status' => 'in_progress',
            'assignedToUserId' => $technicianId,
            'assignedToName' => (string) ($technician['name'] ?? $technician['email']),
            'handedOffAt' => $now,
            'handedOffBy' => $auth->userId,
            'invitation' => ['status' => 'pending'],
        ];
        $this->contracts->update((string) $contract['_id'], [
            'status' => 'onboarding',
            'onboarding' => $onboarding,
        ]);
        $this->tickets->update($ticketId, [
            'assignedToUserId' => $technicianId,
            'leadProcess.contractStatus' => 'onboarding',
            'leadProcess.onboarding' => $onboarding,
            'status' => 'in_progress',
            'updatedBy' => $auth->userId,
        ]);
        // Die technische Übergabe pflegt zugleich die dauerhaften Kontakte am
        // Kunden. Bei Plattform-Admins bleibt eine vorhandene Vertriebszuweisung
        // bestehen, da ein Admin nicht automatisch als Vertriebler gilt.
        $customerContacts = ['assignedTechnicianUserId' => $technicianId];
        if (mb_strtolower(trim((string) ($salesContact['department'] ?? ''))) === 'vertrieb') {
            $customerContacts['assignedSalesUserId'] = $auth->userId;
        }
        $this->customers->update((string) $contract['customerId'], $customerContacts);
        $this->addSystemMessage(
            $ticketId,
            'Übergabe zum Onboarding',
            sprintf('Der Lead wurde an %s aus der Technik übergeben. Planung, Kundenkontakt und Bereitstellung werden ab jetzt in diesem Ticket dokumentiert.', (string) ($technician['name'] ?? $technician['email'])),
            'onboarding_handed_off',
        );
        try {
            $this->notifications->sendSystemUpdate(
                (string) $technician['email'],
                (string) ($technician['name'] ?? $technician['email']),
                'Neues technisches Onboarding',
                sprintf('Das Lead-Ticket %s wurde Ihnen für Rack-Planung, Kundenabstimmung und Portalbereitstellung zugewiesen.', (string) $ticket['number']),
                $this->frontendUrl . '/admin.html?ticket=' . $ticketId,
            );
        } catch (MailDeliveryException) {
            // Die Zuweisung bleibt auch bei einem temporären Mailfehler wirksam.
        }
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /**
     * Plant oder verschiebt den technischen Onboarding-Termin. Der zugewiesene
     * Techniker ist Organisator; der Kunde erhält jede Version als neue
     * iCalendar-Datei mit stabiler UID und erhöhter Sequenznummer.
     *
     * @param array<string, mixed> $payload
     */
    public function scheduleOnboardingAppointment(AuthContext $auth, string $ticketId, array $payload): array
    {
        $this->requireTicketManager($auth);
        $ticket = $this->leadTicket($ticketId);
        $onboarding = is_array($ticket['leadProcess']['onboarding'] ?? null) ? $ticket['leadProcess']['onboarding'] : [];
        if (($onboarding['status'] ?? null) !== 'in_progress') {
            throw new ApiException(409, 'Ein Termin kann erst nach der technischen Übergabe geplant werden.', 'onboarding_not_ready');
        }
        if (!$auth->isPlatformAdmin() && (string) ($onboarding['assignedToUserId'] ?? '') !== $auth->userId) {
            throw new ApiException(403, 'Nur der zugewiesene Techniker darf den Onboarding-Termin planen.', 'forbidden');
        }

        $timezoneName = trim((string) ($payload['timezone'] ?? 'Europe/Berlin'));
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new ApiException(422, 'Bitte wählen Sie eine gültige Zeitzone.', 'validation_failed', ['field' => 'timezone']);
        }
        $startsAtLocal = trim((string) ($payload['startsAtLocal'] ?? ''));
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsAtLocal, $timezone);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$start instanceof \DateTimeImmutable
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $start->format('Y-m-d\TH:i') !== $startsAtLocal) {
            throw new ApiException(422, 'Bitte geben Sie Datum und Uhrzeit des Onboarding-Termins vollständig an.', 'validation_failed', ['field' => 'startsAtLocal']);
        }
        if ($start <= new \DateTimeImmutable('+5 minutes', $timezone)) {
            throw new ApiException(422, 'Der Onboarding-Termin muss mindestens fünf Minuten in der Zukunft liegen.', 'validation_failed', ['field' => 'startsAtLocal']);
        }
        $durationMinutes = (int) ($payload['durationMinutes'] ?? 60);
        if ($durationMinutes < 15 || $durationMinutes > 480) {
            throw new ApiException(422, 'Die Termindauer muss zwischen 15 Minuten und acht Stunden liegen.', 'validation_failed', ['field' => 'durationMinutes']);
        }
        $notes = mb_substr(trim(strip_tags((string) ($payload['notes'] ?? ''))), 0, 1000);
        $end = $start->modify('+' . $durationMinutes . ' minutes');
        $contract = $this->contractForTicket($ticket);
        $technician = $this->users->findTechnician((string) $onboarding['assignedToUserId']);
        $customer = $this->customers->findById((string) $contract['customerId']);
        $recipientEmail = strtolower(trim((string) ($contract['counterparty']['email'] ?? $customer['email'] ?? '')));
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Für den Kunden ist keine gültige E-Mail-Adresse hinterlegt.', 'customer_email_missing');
        }

        $previous = is_array($onboarding['appointment'] ?? null) ? $onboarding['appointment'] : [];
        $sequence = array_key_exists('sequence', $previous) ? max(0, (int) $previous['sequence']) + 1 : 0;
        $uid = trim((string) ($previous['uid'] ?? '')) ?: 'onboarding-' . $ticketId . '@colo-manager';
        $location = trim((string) ($ticket['leadConfiguration']['locationName'] ?? $ticket['leadConfiguration']['locationCode'] ?? ''));
        $appointmentLabel = $this->appointmentLabel($start, $durationMinutes, $timezoneName);
        $calendarName = sprintf('Onboarding-%s.ics', (string) $ticket['number']);
        $calendarContent = $this->calendarGenerator->generate(
            $uid,
            $sequence,
            $start,
            $end,
            sprintf('Technisches Onboarding · %s', (string) ($contract['counterparty']['company'] ?? $customer['name'] ?? $ticket['number'])),
            sprintf('Technisches Onboarding zum Lead-Ticket %s.%s', (string) $ticket['number'], $notes !== '' ? '\n' . $notes : ''),
            $location,
            (string) $technician['email'],
            (string) ($technician['name'] ?? $technician['email']),
            $recipientEmail,
            (string) ($contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $recipientEmail),
        );

        $now = new \DateTimeImmutable('now', $timezone);
        $reminderDue = $start->setTime(7, 0);
        if ($reminderDue < $now) {
            $reminderDue = $now;
        }
        $appointment = [
            'status' => 'scheduled',
            'uid' => $uid,
            'sequence' => $sequence,
            'startAt' => new UTCDateTime($start->getTimestamp() * 1000),
            'endAt' => new UTCDateTime($end->getTimestamp() * 1000),
            'timezone' => $timezoneName,
            'durationMinutes' => $durationMinutes,
            'location' => $location,
            'notes' => $notes,
            'customerEmail' => $recipientEmail,
            'calendarName' => $calendarName,
            'scheduledAt' => new UTCDateTime(),
            'scheduledBy' => [
                'userId' => $auth->userId,
                'name' => (string) ($technician['name'] ?? $auth->email),
                'email' => $auth->email,
            ],
            'emailStatus' => 'pending',
            'reminder' => [
                'status' => 'pending',
                'dueAt' => new UTCDateTime($reminderDue->getTimestamp() * 1000),
                'attempts' => 0,
            ],
        ];
        $this->tickets->update($ticketId, [
            'leadProcess.onboarding.appointment' => $appointment,
            'updatedBy' => $auth->userId,
        ]);
        $this->contracts->update((string) $contract['_id'], ['onboarding.appointment' => $appointment]);

        try {
            $this->notifications->sendOnboardingAppointment(
                $recipientEmail,
                (string) ($contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $recipientEmail),
                (string) $ticket['number'],
                (string) ($technician['name'] ?? $technician['email']),
                $appointmentLabel,
                $location,
                $notes,
                $calendarContent,
                $calendarName,
            );
            $sentAt = new UTCDateTime();
            $this->tickets->update($ticketId, [
                'leadProcess.onboarding.appointment.emailStatus' => 'sent',
                'leadProcess.onboarding.appointment.emailSentAt' => $sentAt,
            ]);
            $this->contracts->update((string) $contract['_id'], [
                'onboarding.appointment.emailStatus' => 'sent',
                'onboarding.appointment.emailSentAt' => $sentAt,
            ]);
        } catch (MailDeliveryException) {
            $this->tickets->update($ticketId, ['leadProcess.onboarding.appointment.emailStatus' => 'failed']);
            $this->contracts->update((string) $contract['_id'], ['onboarding.appointment.emailStatus' => 'failed']);
            throw new ApiException(502, 'Der Termin wurde gespeichert, die Kundenmail konnte jedoch nicht versendet werden.', 'onboarding_appointment_mail_failed');
        }

        $this->addSystemMessage(
            $ticketId,
            $sequence > 0 ? 'Onboarding-Termin aktualisiert' : 'Onboarding-Termin vereinbart',
            sprintf('%s hat den Termin auf %s festgelegt und dem Kunden eine iCalendar-Einladung gesendet.', (string) ($technician['name'] ?? $technician['email']), $appointmentLabel),
            'onboarding_appointment_scheduled',
        );
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /** Erstellt nach technischer Freigabe eine einmalige Passwort-Einladung. */
    public function sendAccountInvitation(AuthContext $auth, string $ticketId): array
    {
        $this->requireTicketManager($auth);
        $ticket = $this->leadTicket($ticketId);
        $onboarding = $ticket['leadProcess']['onboarding'] ?? [];
        if (($onboarding['status'] ?? null) !== 'in_progress') {
            throw new ApiException(409, 'Das technische Onboarding ist noch nicht zur Account-Einladung bereit.', 'onboarding_not_ready');
        }
        if (!$auth->isPlatformAdmin() && (string) ($onboarding['assignedToUserId'] ?? '') !== $auth->userId) {
            throw new ApiException(403, 'Nur der zugewiesene Techniker darf die Portal-Einladung versenden.', 'forbidden');
        }
        $contract = $this->contractForTicket($ticket);
        $customerId = (string) ($contract['customerId'] ?? '');
        $customer = $this->customers->findById($customerId);
        // Ein Portalzugang wird erst freigegeben, wenn der Kunde im Dashboard
        // tatsächlich beide zugesagten persönlichen Kontakte vorfindet.
        if (empty($customer['assignedTechnicianUserId']) || empty($customer['assignedSalesUserId'])) {
            throw new ApiException(422, 'Bitte hinterlegen Sie vor der Portal-Einladung einen zuständigen Techniker und Vertriebler am Kunden.', 'customer_contacts_missing');
        }
        $email = strtolower(trim((string) ($contract['counterparty']['email'] ?? $customer['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Für den Kunden ist keine gültige E-Mail-Adresse hinterlegt.', 'customer_email_missing');
        }
        if ($this->users->findAnyByEmail($email) !== null) {
            throw new ApiException(409, 'Für diese E-Mail-Adresse existiert bereits ein Portalzugang.', 'account_already_exists');
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = new UTCDateTime((time() + self::INVITATION_TOKEN_HOURS * 3600) * 1000);
        $invitation = [
            'status' => 'sent',
            'tokenHash' => hash('sha256', $token),
            'email' => $email,
            'sentAt' => new UTCDateTime(),
            'sentBy' => $auth->userId,
            'expiresAt' => $expiresAt,
            'emailStatus' => 'pending',
        ];
        $this->tickets->update($ticketId, [
            'leadProcess.onboarding.invitation' => $invitation,
            'updatedBy' => $auth->userId,
        ]);
        $this->contracts->update((string) $contract['_id'], ['onboarding.invitation' => $invitation]);
        try {
            $this->notifications->sendAccountInvitation(
                $email,
                (string) ($contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $email),
                (string) ($customer['name'] ?? $contract['counterparty']['company'] ?? 'Ihr Unternehmen'),
                $this->frontendUrl . '/konto-aktivieren.html?token=' . $token,
                self::INVITATION_TOKEN_HOURS,
            );
            $this->tickets->update($ticketId, ['leadProcess.onboarding.invitation.emailStatus' => 'sent']);
            $this->contracts->update((string) $contract['_id'], ['onboarding.invitation.emailStatus' => 'sent']);
        } catch (MailDeliveryException) {
            $this->tickets->update($ticketId, ['leadProcess.onboarding.invitation.emailStatus' => 'failed']);
            $this->contracts->update((string) $contract['_id'], ['onboarding.invitation.emailStatus' => 'failed']);
            throw new ApiException(502, 'Die Einladung wurde gespeichert, die E-Mail konnte jedoch nicht versendet werden.', 'account_invitation_mail_failed');
        }
        $this->addSystemMessage(
            $ticketId,
            'Portal-Einladung versendet',
            sprintf('Der zugewiesene Techniker hat die einmalige Account-Einladung an %s versendet.', $email),
            'account_invitation_sent',
        );
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /** Liefert die nicht sensiblen Daten einer gültigen Portal-Einladung. */
    public function publicInvitation(string $token): array
    {
        $ticket = $this->ticketByInvitationToken($token);
        $contract = $this->contractForTicket($ticket);
        $customer = $this->customers->findById((string) $contract['customerId']);
        $invitation = $ticket['leadProcess']['onboarding']['invitation'];
        return DocumentSerializer::serialize([
            'status' => $invitation['status'],
            'email' => $invitation['email'],
            'company' => $customer['name'] ?? $contract['counterparty']['company'] ?? '',
            'contactName' => $contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? '',
            'expiresAt' => $invitation['expiresAt'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function activateAccount(string $token, array $payload): array
    {
        $ticket = $this->ticketByInvitationToken($token);
        $invitation = $ticket['leadProcess']['onboarding']['invitation'];
        if (($invitation['status'] ?? null) !== 'sent') {
            throw new ApiException(409, 'Diese Einladung wurde bereits verwendet.', 'account_invitation_used');
        }
        $password = (string) ($payload['password'] ?? '');
        $confirmation = (string) ($payload['passwordConfirmation'] ?? '');
        if ($password !== $confirmation) {
            throw new ApiException(422, 'Die Passwortbestätigung stimmt nicht überein.', 'validation_failed', ['field' => 'passwordConfirmation']);
        }
        if (mb_strlen($password) < 12 || mb_strlen($password) > 128
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            throw new ApiException(422, 'Das Passwort benötigt 12 bis 128 Zeichen sowie Großbuchstaben, Kleinbuchstaben und eine Zahl.', 'validation_failed', ['field' => 'password']);
        }
        $contract = $this->contractForTicket($ticket);
        $customerId = (string) ($contract['customerId'] ?? '');
        $customer = $this->customers->findById($customerId);
        $email = strtolower((string) $invitation['email']);
        if ($this->users->findAnyByEmail($email) !== null) {
            throw new ApiException(409, 'Für diese E-Mail-Adresse existiert bereits ein Portalzugang.', 'account_already_exists');
        }
        $user = $this->users->create([
            'name' => (string) ($contract['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $customer['name'] ?? $email),
            'email' => $email,
            'passwordHash' => password_hash($password, PASSWORD_ARGON2ID),
            'role' => 'customer_admin',
            'department' => null,
            'customerId' => $customerId,
            'active' => true,
        ]);

        $subscriptionUpdate = ['contractStart' => $this->dateString($contract['plannedStartDate'] ?? null)];
        foreach ($contract['lineItems'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'plan' && !empty($item['sourceId'])) {
                $subscriptionUpdate['servicePlanId'] = (string) $item['sourceId'];
            }
            if (($item['type'] ?? null) === 'bandwidth' && !empty($item['sourceId'])) {
                $subscriptionUpdate['bandwidthOptionId'] = (string) $item['sourceId'];
            }
        }
        $this->customers->update($customerId, $subscriptionUpdate);
        $activatedContract = $this->contractService->activateFromOnboarding((string) $contract['_id']);
        $now = new UTCDateTime();
        $this->tickets->update((string) $ticket['_id'], [
            'leadProcess.contractStatus' => (string) $activatedContract['status'],
            'leadProcess.onboarding.status' => 'completed',
            'leadProcess.onboarding.completedAt' => $now,
            'leadProcess.onboarding.accountUserId' => (string) $user['_id'],
            'leadProcess.onboarding.invitation.status' => 'activated',
            'leadProcess.onboarding.invitation.activatedAt' => $now,
            'status' => 'closed',
            'updatedBy' => 'public-account-activation',
        ]);
        $this->addSystemMessage(
            (string) $ticket['_id'],
            'Onboarding abgeschlossen',
            sprintf('Der Portalzugang für %s wurde aktiviert. Der Vertrag ist %s und das Lead-Ticket wurde geschlossen.', $email, ($activatedContract['status'] ?? null) === 'scheduled' ? 'für den Starttermin vorgemerkt' : 'aktiv'),
            'onboarding_completed',
        );
        return DocumentSerializer::serialize([
            'status' => 'activated',
            'email' => $email,
            'loginUrl' => $this->frontendUrl . '/login.html',
            'contractStatus' => $activatedContract['status'],
        ]);
    }

    /** @param list<array<string, mixed>> $files @return array<string, mixed> */
    private function validateSignedDocument(array $files): array
    {
        $files = array_values(array_filter($files, static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (count($files) !== 1) {
            throw new ApiException(422, 'Bitte laden Sie genau eine unterschriebene PDF-Datei hoch.', 'validation_failed', ['field' => 'signedContract']);
        }
        $file = $files[0];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);
        $path = (string) ($file['tmp_name'] ?? '');
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $size > self::MAX_SIGNED_DOCUMENT_BYTES) {
            throw new ApiException(413, 'Die Vertragsdatei darf maximal 10 MB groß sein.', 'contract_document_too_large');
        }
        if ($error !== UPLOAD_ERR_OK || $size < 100 || !is_file($path)) {
            throw new ApiException(422, 'Die Vertragsdatei konnte nicht vollständig hochgeladen werden.', 'upload_failed');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $stream = fopen($path, 'rb');
        $magic = is_resource($stream) ? fread($stream, 5) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($mimeType !== 'application/pdf' || $magic !== '%PDF-') {
            throw new ApiException(422, 'Erlaubt ist ausschließlich eine gültige PDF-Datei.', 'invalid_contract_document');
        }
        $baseName = pathinfo(basename((string) ($file['name'] ?? 'unterschriebener-vertrag.pdf')), PATHINFO_FILENAME);
        $baseName = trim((string) preg_replace('/[^\pL\pN._-]+/u', '-', $baseName), '-_.');
        return $file + ['safe_name' => ($baseName !== '' ? $baseName : 'unterschriebener-vertrag') . '.pdf', 'size' => $size];
    }

    /** @return array<string, mixed> */
    private function contractByToken(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new ApiException(404, 'Der Vertragslink wurde nicht gefunden.', 'contract_signature_not_found');
        }
        $contract = $this->contracts->findBySignatureTokenHash(hash('sha256', $token));
        $this->assertNotExpired($contract['signature']['expiresAt'] ?? null, 'Der Vertragslink ist abgelaufen. Bitte wenden Sie sich an Ihren Ansprechpartner.');
        return $contract;
    }

    /** @return array<string, mixed> */
    private function ticketByInvitationToken(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new ApiException(404, 'Die Einladung wurde nicht gefunden.', 'account_invitation_not_found');
        }
        $ticket = $this->tickets->findByAccountInvitationTokenHash(hash('sha256', $token));
        $this->assertNotExpired($ticket['leadProcess']['onboarding']['invitation']['expiresAt'] ?? null, 'Die Einladung ist abgelaufen. Bitte wenden Sie sich an Ihren Techniker.');
        return $ticket;
    }

    private function assertNotExpired(mixed $expiresAt, string $message): void
    {
        if ($expiresAt === null || $expiresAt === '') {
            throw new ApiException(410, $message, 'public_token_expired');
        }
        try {
            $date = $expiresAt instanceof UTCDateTime ? $expiresAt->toDateTimeImmutable() : new \DateTimeImmutable((string) $expiresAt);
        } catch (\Throwable) {
            throw new ApiException(410, $message, 'public_token_expired');
        }
        if ($date < new \DateTimeImmutable()) {
            throw new ApiException(410, $message, 'public_token_expired');
        }
    }

    /** @return array<string, mixed> */
    private function leadContract(string $contractId): array
    {
        $contract = $this->contracts->findById($contractId);
        if (empty($contract['sourceLead']['ticketId'])) {
            throw new ApiException(422, 'Der Signaturprozess ist derzeit nur für Verträge aus Lead-Tickets verfügbar.', 'lead_contract_required');
        }
        return $contract;
    }

    /** @return array<string, mixed> */
    private function leadTicket(string $ticketId): array
    {
        $ticket = $this->tickets->findById($ticketId);
        if (($ticket['type'] ?? null) !== 'lead' || ($ticket['category'] ?? null) !== 'lead') {
            throw new ApiException(422, 'Dieser Prozess ist ausschließlich für Lead-Tickets verfügbar.', 'lead_ticket_required');
        }
        return $ticket;
    }

    /** @return array<string, mixed> */
    private function contractForTicket(array $ticket): array
    {
        $contractId = (string) ($ticket['leadProcess']['contractId'] ?? '');
        if ($contractId === '') {
            throw new ApiException(409, 'Für den Lead wurde noch kein Vertrag angelegt.', 'lead_contract_missing');
        }
        return $this->leadContract($contractId);
    }

    private function notifySalesOfSignedContract(array $ticket, array $contract): void
    {
        $recipients = $this->users->listSalesStaff();
        foreach ($recipients as $recipient) {
            $email = (string) ($recipient['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            try {
                $this->notifications->sendSignedContractReceived(
                    $email,
                    (string) ($recipient['name'] ?? $email),
                    (string) $contract['number'],
                    $this->frontendUrl . '/admin.html?ticket=' . (string) $ticket['_id'],
                );
            } catch (MailDeliveryException) {
                // Der unterschriebene Vertrag bleibt auch bei einem Mailproblem gespeichert.
            }
        }
    }

    /** @param array<string, mixed> $user @return array<string, string> */
    private function actor(AuthContext $auth, array $user): array
    {
        return [
            'type' => 'staff',
            'userId' => $auth->userId,
            'name' => (string) ($user['name'] ?? $auth->email),
            'email' => $auth->email,
        ];
    }

    private function addSystemMessage(string $ticketId, string $title, string $text, string $eventType): void
    {
        $this->messages->create($ticketId, [
            'bodyHtml' => sprintf('<p><strong>%s.</strong> %s</p>', htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'bodyText' => $title . '. ' . $text,
            'author' => ['type' => 'system', 'name' => 'Lead-Prozess'],
            'internal' => true,
            'eventType' => $eventType,
        ]);
        $this->tickets->registerMessage($ticketId, true);
    }

    /** Formatiert den Termin bewusst ohne Abhängigkeit von ext-intl. */
    private function appointmentLabel(\DateTimeImmutable $start, int $durationMinutes, string $timezone): string
    {
        $weekdays = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $end = $start->modify('+' . $durationMinutes . ' minutes');
        return sprintf(
            '%s, %d. %s %s von %s bis %s Uhr (%s)',
            $weekdays[(int) $start->format('N')],
            (int) $start->format('j'),
            $months[(int) $start->format('n')],
            $start->format('Y'),
            $start->format('H:i'),
            $end->format('H:i'),
            $timezone,
        );
    }

    private function requireTicketManager(AuthContext $auth): void
    {
        if (!$auth->canManageTickets()) {
            throw new ApiException(403, 'Diese Aktion ist internen Mitarbeitern vorbehalten.', 'forbidden');
        }
    }

    private function requireSales(AuthContext $auth): void
    {
        if ($auth->isPlatformAdmin()) {
            return;
        }
        if (!$auth->isDatacenterStaff()) {
            throw new ApiException(403, 'Diese Aktion ist der Plattform-Administration und dem Vertrieb vorbehalten.', 'forbidden');
        }
        $user = $this->users->findById($auth->userId);
        if (mb_strtolower(trim((string) ($user['department'] ?? ''))) !== 'vertrieb') {
            throw new ApiException(403, 'Diese Aktion ist der Plattform-Administration und dem Vertrieb vorbehalten.', 'forbidden');
        }
    }

    /** @return array<string, mixed> */
    private function safeContract(array $contract): array
    {
        unset($contract['signature']['tokenHash']);
        unset($contract['onboarding']['invitation']['tokenHash']);
        return DocumentSerializer::serialize($contract);
    }

    /** @return array<string, mixed> */
    private function safeTicket(array $ticket): array
    {
        unset($ticket['leadProcess']['offer']['tokenHash']);
        unset($ticket['leadProcess']['onboarding']['invitation']['tokenHash']);
        return DocumentSerializer::serialize($ticket);
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format('Y-m-d');
        }
        return (new \DateTimeImmutable((string) $value))->format('Y-m-d');
    }
}
