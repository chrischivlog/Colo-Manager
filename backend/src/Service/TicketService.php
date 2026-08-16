<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\TicketAttachmentRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\TicketHtmlSanitizer;
use ColoManager\Support\Validator;
use MongoDB\BSON\UTCDateTime;

/**
 * Zentrale Ticketfachlogik für Kundenanfragen und öffentliche Leads. Die
 * gemeinsame Domäne kann später um interne Datacenter-Workflows erweitert
 * werden, ohne Kundentickets oder Leadnummern erneut migrieren zu müssen.
 */
final readonly class TicketService
{
    private const TYPES = ['normal', 'lead', 'internal'];
    private const STATUSES = ['open', 'in_progress', 'waiting_customer', 'closed'];
    private const CATEGORIES = ['incident', 'remote_hands', 'sales', 'lead', 'other'];
    private const CUSTOMER_CATEGORIES = ['remote_hands', 'sales', 'other'];
    private const INTERNAL_CATEGORIES = ['incident', 'remote_hands', 'sales', 'other'];
    private const MAX_ATTACHMENTS = 5;
    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    private const ATTACHMENT_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private TicketRepository $tickets,
        private TicketMessageRepository $messages,
        private TicketAttachmentRepository $attachments,
        private CustomerRepository $customers,
        private UserRepository $users,
        private RackRepository $racks,
        private DeviceRepository $devices,
        private NotificationMailService $notifications,
        private TicketHtmlSanitizer $htmlSanitizer,
        private string $frontendUrl,
    ) {
    }

    public function list(AuthContext $auth, int $page, int $limit, ?string $type, ?string $status, ?string $category, ?string $search): array
    {
        Validator::enum(['type' => $type], 'type', self::TYPES);
        Validator::enum(['status' => $status], 'status', self::STATUSES);
        Validator::enum(['category' => $category], 'category', self::CATEGORIES);
        $customerId = $auth->canManageTickets() ? null : $this->requireCustomer($auth);
        $staffQueueUserId = $auth->isDatacenterStaff() && !$auth->isPlatformAdmin() ? $auth->userId : null;
        $result = $this->tickets->list($page, $limit, $customerId, $type, $status, $category, $search, $staffQueueUserId);
        if ($customerId !== null) {
            $result['items'] = array_map($this->customerTicketView(...), $result['items']);
        } else {
            $result['items'] = array_map($this->safeTicketView(...), $result['items']);
        }
        return DocumentSerializer::serialize($result);
    }

    /** Liefert die möglichen internen Bearbeiter ohne sensible Kontofelder. */
    public function assignees(AuthContext $auth): array
    {
        $this->requireTicketManager($auth);
        $assignees = array_map(static fn (array $user): array => [
            'id' => $user['_id'],
            'name' => $user['name'] ?? $user['email'],
            'email' => $user['email'],
            'role' => $user['role'],
            'department' => $user['department'] ?? null,
        ], $this->users->listTicketAssignees());
        return DocumentSerializer::serialize(['items' => $assignees]);
    }

    /** Liefert Mitarbeitern ausschließlich die für Tickets benötigten Kundendaten. */
    public function customerOptions(AuthContext $auth): array
    {
        $this->requireTicketManager($auth);
        $result = $this->customers->list(1, 500);
        $items = array_values(array_map(static fn (array $customer): array => [
            'id' => $customer['_id'],
            'name' => $customer['name'] ?? 'Unbenannter Kunde',
            'customerNumber' => $customer['customerNumber'] ?? null,
        ], array_filter($result['items'], static fn (array $customer): bool => ($customer['status'] ?? null) === 'active')));
        return DocumentSerializer::serialize(['items' => $items]);
    }

    /** @param array<string, mixed> $payload @param list<array<string, mixed>> $files */
    public function create(AuthContext $auth, array $payload, array $files): array
    {
        $customerId = $this->requireCustomer($auth);
        Validator::required($payload, ['subject', 'category']);
        Validator::enum($payload, 'category', self::CUSTOMER_CATEGORIES);
        $subject = $this->subject((string) $payload['subject']);
        $preparedFiles = $this->validateFiles($files);
        $body = $this->messageBody((string) ($payload['bodyHtml'] ?? $payload['message'] ?? ''), $preparedFiles !== []);
        $user = $this->users->findById($auth->userId);
        $customer = $this->customers->findById($customerId);
        $requesterName = (string) ($user['name'] ?? $customer['contactPerson']['name'] ?? $customer['name'] ?? $auth->email);
        $remoteHandsTarget = (string) $payload['category'] === 'remote_hands'
            ? $this->remoteHandsTarget($payload, $customerId)
            : null;

        $ticketData = [
            'type' => 'normal',
            'visibility' => 'customer',
            'category' => (string) $payload['category'],
            'subject' => $subject,
            'customerId' => $customerId,
            'requester' => [
                'userId' => $auth->userId,
                'name' => $requesterName,
                'email' => $auth->email,
                'company' => (string) ($customer['name'] ?? ''),
            ],
            'source' => 'customer_portal',
            'createdBy' => $auth->userId,
            'updatedBy' => $auth->userId,
        ];
        if ($remoteHandsTarget !== null) {
            // Der Snapshot hält den ursprünglichen Bezug auch dann lesbar,
            // wenn Rack oder Gerät später umbenannt beziehungsweise versetzt wird.
            $ticketData['remoteHandsTarget'] = $remoteHandsTarget;
        }
        $ticket = $this->tickets->create($ticketData);
        $message = $this->createMessageWithAttachments((string) $ticket['_id'], $auth, $body, $preparedFiles, false);

        try {
            $this->notifications->sendTicketCreated(
                $auth->email,
                $requesterName,
                (string) $ticket['number'],
                $subject,
                $this->frontendUrl . '/tickets?ticket=' . (string) $ticket['_id'],
            );
            $this->tickets->update((string) $ticket['_id'], ['confirmationMailStatus' => 'sent']);
        } catch (MailDeliveryException) {
            // Das Ticket bleibt auch bei einem temporären Mailproblem erhalten.
            $this->tickets->update((string) $ticket['_id'], ['confirmationMailStatus' => 'failed']);
        }

        return $this->detail($auth, (string) $ticket['_id']);
    }

    /** @param array<string, mixed> $payload @param list<array<string, mixed>> $files */
    public function createInternal(AuthContext $auth, array $payload, array $files): array
    {
        $this->requireTicketManager($auth);
        Validator::required($payload, ['subject', 'category']);
        Validator::enum($payload, 'category', self::INTERNAL_CATEGORIES);
        $subject = $this->subject((string) $payload['subject']);
        $preparedFiles = $this->validateFiles($files);
        $body = $this->messageBody((string) ($payload['bodyHtml'] ?? $payload['message'] ?? ''), $preparedFiles !== []);
        $user = $this->users->findById($auth->userId);

        $customerId = !empty($payload['customerId']) ? (string) $payload['customerId'] : null;
        $customer = $customerId !== null ? $this->customers->findById($customerId) : null;
        $assignedToUserId = !empty($payload['assignedToUserId']) ? (string) $payload['assignedToUserId'] : $auth->userId;
        $this->users->findTicketAssignee($assignedToUserId);

        $ticket = $this->tickets->create([
            'type' => 'internal',
            'visibility' => 'internal',
            'category' => (string) $payload['category'],
            'subject' => $subject,
            'customerId' => $customerId,
            'assignedToUserId' => $assignedToUserId,
            'requester' => [
                'userId' => $auth->userId,
                'name' => (string) ($user['name'] ?? $auth->email),
                'email' => $auth->email,
                'company' => (string) ($customer['name'] ?? 'Datacenter intern'),
            ],
            'source' => 'staff_panel',
            'createdBy' => $auth->userId,
            'updatedBy' => $auth->userId,
        ]);
        $this->createMessageWithAttachments((string) $ticket['_id'], $auth, $body, $preparedFiles, true);

        // Bewusst keine Kundenmail: interne Tickets bleiben bis zu einem
        // späteren, expliziten Freigabeworkflow vollständig unsichtbar.
        return $this->detail($auth, (string) $ticket['_id']);
    }

    public function detail(AuthContext $auth, string $id): array
    {
        $ticket = $this->ticketForAuth($auth, $id);
        $includeInternal = $auth->canManageTickets();
        $ticket['messages'] = $this->messagesWithDownloadUrls($this->messages->listForTicket($id, $includeInternal), $id);
        if (!$includeInternal) {
            $ticket = $this->customerTicketView($ticket);
            // Im Detail werden die sichtbaren Werte exakt aus dem gefilterten
            // Verlauf berechnet; interne Notizen hinterlassen so keine Spuren.
            $ticket['messageCount'] = count($ticket['messages']);
            $ticket['attachmentCount'] = array_sum(array_map(
                static fn (array $message): int => count($message['attachments'] ?? []),
                $ticket['messages'],
            ));
            $lastVisibleMessage = $ticket['messages'] !== [] ? $ticket['messages'][array_key_last($ticket['messages'])] : null;
            if ($lastVisibleMessage !== null) {
                $ticket['lastMessageAt'] = $lastVisibleMessage['createdAt'] ?? $ticket['lastMessageAt'];
            }
        } else {
            $ticket = $this->safeTicketView($ticket);
        }
        return DocumentSerializer::serialize($ticket);
    }

    /** @param array<string, mixed> $payload @param list<array<string, mixed>> $files */
    public function addMessage(AuthContext $auth, string $id, array $payload, array $files): array
    {
        $ticket = $this->ticketForAuth($auth, $id);
        if (($ticket['status'] ?? null) === 'closed') {
            throw new ApiException(409, 'Geschlossene Tickets können nicht beantwortet werden.', 'ticket_closed');
        }
        $preparedFiles = $this->validateFiles($files);
        $body = $this->messageBody((string) ($payload['bodyHtml'] ?? $payload['message'] ?? ''), $preparedFiles !== []);
        $ticketIsInternal = ($ticket['visibility'] ?? null) === 'internal';
        $sendToCustomer = $auth->canManageTickets()
            && filter_var($payload['sendToCustomer'] ?? false, FILTER_VALIDATE_BOOLEAN) === true;
        if ($auth->canManageTickets() && ($ticket['type'] ?? null) === 'lead' && $sendToCustomer) {
            throw new ApiException(422, 'Lead-Tickets werden nicht über normale Kundenantworten versendet. Nutzen Sie dafür den Angebotsprozess.', 'lead_external_reply_not_allowed');
        }
        // Mitarbeiter schreiben grundsätzlich intern. Nur der bewusst gesetzte
        // Freigabehaken macht eine Nachricht für den Kunden sichtbar.
        $isInternal = $ticketIsInternal || ($auth->canManageTickets() && !$sendToCustomer);
        $message = $this->createMessageWithAttachments($id, $auth, $body, $preparedFiles, $isInternal);

        // Antworten bewegen den rudimentären Workflow automatisch in einen
        // sinnvollen Folgezustand; Mitarbeiter können ihn danach manuell ändern.
        if ($auth->canManageTickets()) {
            if ($ticketIsInternal) {
                // Rein interne Tickets laufen unabhängig vom Kundenportal.
                $this->tickets->update($id, ['status' => 'in_progress', 'updatedBy' => $auth->userId]);
            } elseif (!$isInternal) {
                // Nur eine ausdrücklich freigegebene Antwort verändert den
                // für den Kunden sichtbaren Workflowstatus.
                $nextStatus = ($ticket['type'] ?? 'normal') === 'lead' ? 'in_progress' : 'waiting_customer';
                $this->tickets->update($id, ['status' => $nextStatus, 'updatedBy' => $auth->userId]);
            }
        } elseif (($ticket['status'] ?? null) === 'waiting_customer') {
            $this->tickets->update($id, ['status' => 'in_progress', 'updatedBy' => $auth->userId]);
        }

        if (!$isInternal && $auth->canManageTickets() && !empty($ticket['requester']['email'])) {
            try {
                $this->notifications->sendTicketUpdated(
                    (string) $ticket['requester']['email'],
                    (string) ($ticket['requester']['name'] ?? $ticket['requester']['email']),
                    (string) $ticket['number'],
                    $body['text'] !== '' ? $body['text'] : 'Ein neuer Dateianhang wurde hinzugefügt.',
                    $this->frontendUrl . '/tickets?ticket=' . $id,
                );
            } catch (MailDeliveryException) {
                // Eine fehlgeschlagene Benachrichtigung darf die Antwort nicht verlieren.
            }
        }

        return DocumentSerializer::serialize($this->messageWithDownloadUrls($message, $id));
    }

    /** @param array<string, mixed> $payload */
    public function update(AuthContext $auth, string $id, array $payload): array
    {
        $this->requireTicketManager($auth);
        Validator::enum($payload, 'status', self::STATUSES);
        Validator::enum($payload, 'category', self::CATEGORIES);
        $ticket = $this->tickets->findById($id);
        $nextCategory = (string) ($payload['category'] ?? $ticket['category'] ?? 'other');
        $nextStatus = (string) ($payload['status'] ?? $ticket['status'] ?? 'open');
        if ($nextCategory === 'lead' && ($ticket['type'] ?? null) !== 'lead') {
            throw new ApiException(422, 'Die Kategorie Lead-Anfrage ist ausschließlich für öffentliche Erstanfragen verfügbar.', 'validation_failed', ['field' => 'category']);
        }
        if (($ticket['type'] ?? null) === 'lead' && $nextCategory !== 'lead') {
            throw new ApiException(422, 'Lead-Tickets behalten dauerhaft die Kategorie Lead-Anfrage.', 'validation_failed', ['field' => 'category']);
        }
        if ($nextCategory === 'incident' && ($ticket['visibility'] ?? null) !== 'internal') {
            throw new ApiException(422, 'Die Kategorie Störung ist ausschließlich für interne Tickets verfügbar.', 'validation_failed', ['field' => 'category']);
        }
        $data = Validator::only($payload, ['status', 'priority', 'assignedToUserId', 'category']);
        if (array_key_exists('assignedToUserId', $data) && !empty($data['assignedToUserId'])) {
            $this->users->findTicketAssignee((string) $data['assignedToUserId']);
        }
        if (isset($payload['subject'])) {
            $data['subject'] = $this->subject((string) $payload['subject']);
        }

        // Remote-Hands-Einsätze müssen beim Übergang auf „geschlossen“ mit
        // ihrer operativen und administrativen Arbeitszeit dokumentiert werden.
        if ($nextCategory === 'remote_hands' && $nextStatus === 'closed' && ($ticket['status'] ?? null) !== 'closed') {
            if (!array_key_exists('remoteHandsOnsiteMinutes', $payload)) {
                throw new ApiException(422, 'Bitte tragen Sie die Arbeitszeit vor Ort ein.', 'validation_failed', ['field' => 'remoteHandsOnsiteMinutes']);
            }
            $onsiteMinutes = $this->workMinutes($payload['remoteHandsOnsiteMinutes'], 'remoteHandsOnsiteMinutes');
            $administrationMinutes = $this->workMinutes($payload['remoteHandsAdministrationMinutes'] ?? 0, 'remoteHandsAdministrationMinutes');
            if ($onsiteMinutes < 1) {
                throw new ApiException(422, 'Für ein geschlossenes Remote-Hands-Ticket muss mindestens eine Minute Vor-Ort-Arbeitszeit erfasst werden.', 'validation_failed', ['field' => 'remoteHandsOnsiteMinutes']);
            }
            $billable = filter_var($payload['remoteHandsBillable'] ?? false, FILTER_VALIDATE_BOOLEAN) === true;
            if ($billable && empty($ticket['customerId'])) {
                throw new ApiException(422, 'Ohne zugeordneten Kunden kann der Einsatz nicht zur Abrechnung vorgemerkt werden.', 'validation_failed', ['field' => 'remoteHandsBillable']);
            }
            $user = $this->users->findById($auth->userId);
            $data['remoteHandsWorkLog'] = [
                'onsiteMinutes' => $onsiteMinutes,
                'administrationMinutes' => $administrationMinutes,
                'totalMinutes' => $onsiteMinutes + $administrationMinutes,
                'billable' => $billable,
                'billingStatus' => $billable ? 'pending' : 'not_billable',
                'recordedBy' => [
                    'userId' => $auth->userId,
                    'name' => (string) ($user['name'] ?? $auth->email),
                    'email' => $auth->email,
                ],
                'recordedAt' => new UTCDateTime(),
            ];
        }
        $data['updatedBy'] = $auth->userId;
        return DocumentSerializer::serialize($this->tickets->update($id, $data));
    }

    public function delete(AuthContext $auth, string $id): void
    {
        $this->requirePlatformAdmin($auth);
        $ticket = $this->tickets->findById($id);
        if (!empty($ticket['leadProcess']['contractId'])) {
            throw new ApiException(409, 'Ein Lead mit erzeugtem Vertrag kann nicht gelöscht werden.', 'resource_in_use');
        }
        $this->attachments->deleteForTicket($id);
        $this->tickets->softDelete($id);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function downloadAttachment(AuthContext $auth, string $ticketId, string $attachmentId): array
    {
        $this->ticketForAuth($auth, $ticketId);
        $attachment = $this->attachments->download($attachmentId, $ticketId);
        if (!$auth->canManageTickets()) {
            $messageId = (string) ($attachment['messageId'] ?? '');
            if ($messageId === '' || $this->messages->isInternalForTicket($messageId, $ticketId)) {
                throw new ApiException(404, 'Der Ticketanhang wurde nicht gefunden.', 'ticket_attachment_not_found');
            }
        }
        unset($attachment['messageId']);
        return $attachment;
    }

    /**
     * Erzeugt aus der vorhandenen öffentlichen Angebotsanfrage ein Lead-Ticket.
     * Die Anfrage bleibt als Vertriebssnapshot bestehen und verweist auf die
     * gemeinsame Ticketnummer.
     *
     * @param array<string, mixed> $inquiry
     */
    public function createLeadTicket(array $inquiry, string $inquiryId, string $configurationSummary): array
    {
        $planName = (string) ($inquiry['configurationSnapshot']['planName'] ?? 'Colocation');
        $message = trim((string) ($inquiry['message'] ?? ''));
        $plainText = "Gewünschte Konfiguration:\n" . $configurationSummary;
        if ($message !== '') {
            $plainText .= "\n\nZusätzliche Nachricht:\n" . $message;
        }
        $body = $this->htmlSanitizer->sanitize('<p>' . nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>');
        $ticket = $this->tickets->create([
            'type' => 'lead',
            'visibility' => 'external',
            'category' => 'lead',
            'subject' => 'Colocation-Anfrage: ' . $planName,
            'customerId' => null,
            'inquiryId' => $inquiryId,
            'requester' => [
                'name' => (string) ($inquiry['contactName'] ?? ''),
                'email' => (string) ($inquiry['email'] ?? ''),
                'company' => (string) ($inquiry['company'] ?? ''),
                'phone' => (string) ($inquiry['phone'] ?? ''),
                // Die Rechnungsanschrift folgt dem Lead bis in den späteren
                // Kunden- und Vertragssnapshot, ohne sie aus Freitext zu lesen.
                'billingAddress' => $inquiry['billingAddress'] ?? null,
            ],
            'source' => 'public_offer',
            'leadConfiguration' => ($inquiry['configurationSnapshot'] ?? []) + [
                'rackUnits' => $inquiry['rackUnits'] ?? null,
                'rackType' => $inquiry['rackType'] ?? null,
                'powerKw' => $inquiry['powerKw'] ?? null,
                'networkBillingModel' => $inquiry['networkBillingModel'] ?? null,
                'contractMonths' => $inquiry['contractMonths'] ?? null,
            ],
            'leadProcess' => [
                'contact' => ['status' => 'pending'],
                'offer' => ['status' => 'pending', 'round' => 1],
                'offerHistory' => [],
                'nextAction' => ['status' => 'not_required'],
            ],
            'createdBy' => 'public',
            'updatedBy' => 'public',
        ]);
        $this->messages->create((string) $ticket['_id'], [
            'bodyHtml' => $body['html'],
            'bodyText' => $body['text'],
            'author' => ['type' => 'requester', 'name' => (string) ($inquiry['contactName'] ?? ''), 'email' => (string) ($inquiry['email'] ?? '')],
            'internal' => false,
        ]);
        $this->tickets->registerMessage((string) $ticket['_id']);
        return DocumentSerializer::serialize($this->tickets->findById((string) $ticket['_id']));
    }

    public function syncLeadStatus(string $inquiryId, string $inquiryStatus): void
    {
        $ticket = $this->tickets->findByInquiryId($inquiryId);
        if ($ticket === null) {
            return;
        }
        $ticketStatus = match ($inquiryStatus) {
            'contacted', 'qualified' => 'in_progress',
            'won', 'lost' => 'closed',
            default => 'open',
        };
        $this->tickets->update((string) $ticket['_id'], ['status' => $ticketStatus, 'updatedBy' => 'inquiry_sync']);
    }

    public function deleteLeadByInquiry(string $inquiryId): void
    {
        $ticket = $this->tickets->findByInquiryId($inquiryId);
        if ($ticket !== null) {
            if (!empty($ticket['leadProcess']['contractId'])) {
                throw new ApiException(409, 'Die Anfrage besitzt bereits einen Vertragsentwurf und kann nicht gelöscht werden.', 'resource_in_use');
            }
            $this->attachments->deleteForTicket((string) $ticket['_id']);
            $this->tickets->softDelete((string) $ticket['_id']);
        }
    }

    /** @param list<array<string, mixed>> $files @return list<array<string, mixed>> */
    private function validateFiles(array $files): array
    {
        $files = array_values(array_filter($files, static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw new ApiException(422, 'Pro Nachricht sind maximal fünf Dateien erlaubt.', 'validation_failed', ['field' => 'attachments']);
        }

        $prepared = [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($files as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new ApiException(413, 'Eine Datei überschreitet die maximale Upload-Größe von 10 MB.', 'attachment_too_large');
            }
            if ($error !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
                throw new ApiException(422, 'Eine Datei konnte nicht vollständig hochgeladen werden.', 'upload_failed');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size < 1 || $size > self::MAX_ATTACHMENT_BYTES) {
                throw new ApiException(413, 'Eine Datei überschreitet die maximale Upload-Größe von 10 MB.', 'attachment_too_large');
            }
            $mimeType = (string) $finfo->file((string) $file['tmp_name']);
            if (!isset(self::ATTACHMENT_MIME_TYPES[$mimeType])) {
                throw new ApiException(422, 'Erlaubt sind ausschließlich JPG-, PNG-, GIF-, WebP- und PDF-Dateien.', 'invalid_attachment_type');
            }
            $baseName = pathinfo(basename((string) ($file['name'] ?? 'bild')), PATHINFO_FILENAME);
            $baseName = trim((string) preg_replace('/[^\pL\pN._-]+/u', '-', $baseName), '-_.');
            $prepared[] = $file + [
                'safe_name' => ($baseName !== '' ? $baseName : 'ticket-datei') . '.' . self::ATTACHMENT_MIME_TYPES[$mimeType],
                'mime_type' => $mimeType,
                'size' => $size,
            ];
        }
        return $prepared;
    }

    /** @return array{html: string, text: string} */
    private function messageBody(string $html, bool $hasFiles): array
    {
        $body = $this->htmlSanitizer->sanitize($html);
        if ($body['text'] === '' && !$hasFiles) {
            throw new ApiException(422, 'Bitte geben Sie eine Nachricht ein oder fügen Sie eine Datei hinzu.', 'validation_failed', ['field' => 'bodyHtml']);
        }
        return $body;
    }

    private function subject(string $subject): string
    {
        $subject = trim(strip_tags($subject));
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 160) {
            throw new ApiException(422, 'Der Betreff muss zwischen 3 und 160 Zeichen lang sein.', 'validation_failed', ['field' => 'subject']);
        }
        return $subject;
    }

    private function workMinutes(mixed $value, string $field): int
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 0 || $minutes > 100000) {
            throw new ApiException(422, 'Arbeitszeiten müssen als positive ganze Minuten angegeben werden.', 'validation_failed', ['field' => $field]);
        }
        return $minutes;
    }

    /** @param array{html: string, text: string} $body @param list<array<string, mixed>> $files */
    private function createMessageWithAttachments(string $ticketId, AuthContext $auth, array $body, array $files, bool $internal): array
    {
        $user = $this->users->findById($auth->userId);
        $message = $this->messages->create($ticketId, [
            'bodyHtml' => $body['html'],
            'bodyText' => $body['text'],
            'author' => [
                'type' => $auth->canManageTickets() ? 'staff' : 'customer',
                'userId' => $auth->userId,
                'name' => (string) ($user['name'] ?? $auth->email),
                'email' => $auth->email,
            ],
            'internal' => $internal,
        ]);
        $this->tickets->registerMessage($ticketId, $internal);

        foreach ($files as $file) {
            $attachment = $this->attachments->store(
                $ticketId,
                (string) $message['_id'],
                (string) $file['tmp_name'],
                (string) $file['safe_name'],
                (string) $file['mime_type'],
                (int) $file['size'],
                $auth->userId,
            );
            $message = $this->messages->addAttachment((string) $message['_id'], $attachment);
            $this->tickets->registerAttachment($ticketId, $internal);
        }
        return $message;
    }

    /** @param list<array<string, mixed>> $messages @return list<array<string, mixed>> */
    private function messagesWithDownloadUrls(array $messages, string $ticketId): array
    {
        return array_map(fn (array $message): array => $this->messageWithDownloadUrls($message, $ticketId), $messages);
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function messageWithDownloadUrls(array $message, string $ticketId): array
    {
        $message['attachments'] = array_map(static function (array $attachment) use ($ticketId): array {
            $attachment['downloadUrl'] = sprintf('/api/v1/tickets/%s/attachments/%s', $ticketId, $attachment['id']);
            return $attachment;
        }, is_array($message['attachments'] ?? null) ? $message['attachments'] : []);
        return $message;
    }

    /** Blendet interne Aktivitätszähler aus der Kundensicht aus. */
    private function customerTicketView(array $ticket): array
    {
        $ticket['messageCount'] = $ticket['publicMessageCount'] ?? $ticket['messageCount'] ?? 0;
        $ticket['attachmentCount'] = $ticket['publicAttachmentCount'] ?? $ticket['attachmentCount'] ?? 0;
        $publicActivityAt = $ticket['lastPublicMessageAt'] ?? $ticket['lastMessageAt'] ?? $ticket['createdAt'] ?? null;
        $ticket['lastMessageAt'] = $publicActivityAt;
        // Interne Notizen aktualisieren den technischen Datensatz, dürfen aber
        // keinen neuen Aktivitätszeitpunkt an den Kunden verraten.
        $ticket['updatedAt'] = $publicActivityAt;
        unset(
            $ticket['publicMessageCount'],
            $ticket['publicAttachmentCount'],
            $ticket['lastPublicMessageAt'],
            $ticket['remoteHandsWorkLog'],
        );
        return $ticket;
    }

    /**
     * Prüft den Infrastrukturbezug eines Remote-Hands-Tickets streng gegen
     * den angemeldeten Kunden. Vom Browser übermittelte Namen und Asset-Tags
     * werden bewusst nicht vertraut, sondern direkt aus MongoDB übernommen.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function remoteHandsTarget(array $payload, string $customerId): array
    {
        Validator::required($payload, ['remoteHandsScope']);
        Validator::enum($payload, 'remoteHandsScope', ['rack', 'device']);
        $scope = (string) $payload['remoteHandsScope'];

        if ($scope === 'rack') {
            Validator::required($payload, ['remoteHandsRackId']);
            $rack = $this->racks->findByIdForCustomer((string) $payload['remoteHandsRackId'], $customerId);

            return $this->remoteHandsTargetSnapshot($rack, null, $scope);
        }

        Validator::required($payload, ['remoteHandsDeviceId']);
        $device = $this->devices->findByIdForCustomer((string) $payload['remoteHandsDeviceId'], $customerId);
        $rackId = isset($device['rackId']) ? (string) $device['rackId'] : '';
        if ($rackId === '') {
            throw new ApiException(422, 'Die gewählte Komponente ist keinem Rack zugeordnet.', 'remote_hands_device_without_rack');
        }
        if (!empty($payload['remoteHandsRackId']) && (string) $payload['remoteHandsRackId'] !== $rackId) {
            throw new ApiException(422, 'Die gewählte Komponente gehört nicht zum angegebenen Rack.', 'remote_hands_target_mismatch');
        }
        $rack = $this->racks->findByIdForCustomer($rackId, $customerId);

        return $this->remoteHandsTargetSnapshot($rack, $device, $scope);
    }

    /** @param array<string, mixed> $rack @param array<string, mixed>|null $device @return array<string, mixed> */
    private function remoteHandsTargetSnapshot(array $rack, ?array $device, string $scope): array
    {
        return [
            'scope' => $scope,
            'rackId' => (string) $rack['_id'],
            'rackName' => (string) ($rack['name'] ?? $rack['code'] ?? 'Rack'),
            'rackCode' => (string) ($rack['code'] ?? ''),
            'rackUnits' => (int) ($rack['totalUnits'] ?? 0),
            'locationId' => isset($rack['locationId']) ? (string) $rack['locationId'] : null,
            'deviceId' => $device !== null ? (string) $device['_id'] : null,
            'deviceName' => $device['name'] ?? null,
            'deviceType' => $device['type'] ?? null,
            'assetTag' => $device['assetTag'] ?? null,
            'rackUnit' => $device !== null ? (int) ($device['rackUnit'] ?? 0) : null,
            'heightUnits' => $device !== null ? (int) ($device['heightUnits'] ?? 1) : null,
        ];
    }

    /**
     * Das öffentliche Angebots-Token wird ausschließlich gehasht gespeichert
     * und zusätzlich aus jeder normalen Mitarbeiter-API-Antwort entfernt.
     */
    private function safeTicketView(array $ticket): array
    {
        unset($ticket['leadProcess']['offer']['tokenHash']);
        unset($ticket['leadProcess']['onboarding']['invitation']['tokenHash']);
        foreach ($ticket['leadProcess']['offerHistory'] ?? [] as $index => $_offer) {
            unset($ticket['leadProcess']['offerHistory'][$index]['tokenHash']);
        }
        return $ticket;
    }

    /** @return array<string, mixed> */
    private function ticketForAuth(AuthContext $auth, string $id): array
    {
        if ($auth->isPlatformAdmin()) {
            return $this->tickets->findById($id);
        }
        if ($auth->isDatacenterStaff()) {
            return $this->tickets->findByIdForStaffQueue($id, $auth->userId);
        }
        return $this->tickets->findByIdForCustomer($id, $this->requireCustomer($auth));
    }

    private function requireCustomer(AuthContext $auth): string
    {
        if ($auth->customerId === null) {
            throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'customer_required');
        }
        return $auth->customerId;
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Diese Aktion ist Plattform-Administratoren vorbehalten.', 'forbidden');
        }
    }

    private function requireTicketManager(AuthContext $auth): void
    {
        if (!$auth->canManageTickets()) {
            throw new ApiException(403, 'Diese Aktion ist internen Mitarbeitern vorbehalten.', 'forbidden');
        }
    }
}
