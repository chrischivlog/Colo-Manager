<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Mail\MailDeliveryException;
use ColoManager\Mail\NotificationMailService;
use ColoManager\Repository\InquiryRepository;
use ColoManager\Repository\TicketAttachmentRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Support\DocumentSerializer;
use ColoManager\Support\OfferPdfGenerator;
use MongoDB\BSON\UTCDateTime;

/**
 * Bildet den ersten verbindlichen Vertriebsprozess eines Lead-Tickets ab:
 * Kontaktaufnahme, Versand genau eines PDF-Angebots und öffentliche Entscheidung.
 */
final readonly class LeadOfferService
{
    public function __construct(
        private TicketRepository $tickets,
        private TicketMessageRepository $messages,
        private TicketAttachmentRepository $attachments,
        private InquiryRepository $inquiries,
        private UserRepository $users,
        private ContractService $contracts,
        private OfferPdfGenerator $pdfGenerator,
        private NotificationMailService $notifications,
        private string $frontendUrl,
    ) {
    }

    /** Markiert den ersten Checklistenschritt und protokolliert ihn im Verlauf. */
    public function markContacted(AuthContext $auth, string $ticketId): array
    {
        $this->requireManager($auth);
        $ticket = $this->leadTicket($ticketId);
        if (($ticket['leadProcess']['contact']['status'] ?? 'pending') !== 'completed') {
            $user = $this->users->findById($auth->userId);
            $now = new UTCDateTime();
            $this->tickets->update($ticketId, [
                'leadProcess.contact' => [
                    'status' => 'completed',
                    'completedAt' => $now,
                    'completedBy' => [
                        'userId' => $auth->userId,
                        'name' => (string) ($user['name'] ?? $auth->email),
                        'email' => $auth->email,
                    ],
                ],
                'status' => 'in_progress',
                'updatedBy' => $auth->userId,
            ]);
            $this->addSystemMessage($ticketId, 'Kontaktaufnahme dokumentiert', sprintf(
                '%s hat die Kontaktaufnahme mit dem Anfragenden als erledigt markiert.',
                (string) ($user['name'] ?? $auth->email),
            ));
            if (!empty($ticket['inquiryId'])) {
                $this->inquiries->update((string) $ticket['inquiryId'], ['status' => 'contacted']);
            }
        }
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /** Liefert den gespeicherten oder aus der Lead-Konfiguration vorbefüllten Entwurf. */
    public function draft(AuthContext $auth, string $ticketId): array
    {
        $this->requireManager($auth);
        return DocumentSerializer::serialize($this->contracts->offerDraftFromLead($this->leadTicket($ticketId)));
    }

    /** @param array<string, mixed> $payload */
    public function saveDraft(AuthContext $auth, string $ticketId, array $payload): array
    {
        $this->requireManager($auth);
        $ticket = $this->leadTicket($ticketId);
        if (($ticket['leadProcess']['contact']['status'] ?? 'pending') !== 'completed') {
            throw new ApiException(409, 'Bitte dokumentieren Sie zuerst die Kontaktaufnahme.', 'lead_contact_required');
        }
        if (($ticket['leadProcess']['offer']['status'] ?? 'pending') !== 'pending') {
            throw new ApiException(409, 'Ein bereits versendetes Angebot kann nicht überschrieben werden.', 'lead_offer_not_ready');
        }
        $draft = $this->contracts->prepareOfferDraft($payload);
        $this->tickets->update($ticketId, [
            'leadProcess.offer.draft' => $draft,
            'leadProcess.offer.draftUpdatedAt' => new UTCDateTime(),
            'leadProcess.offer.draftUpdatedBy' => $auth->userId,
            'updatedBy' => $auth->userId,
        ]);
        return DocumentSerializer::serialize($draft);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function previewDraft(AuthContext $auth, string $ticketId): array
    {
        $this->requireManager($auth);
        $ticket = $this->leadTicket($ticketId);
        $draft = $ticket['leadProcess']['offer']['draft'] ?? null;
        if (!is_array($draft) || $draft === []) {
            throw new ApiException(409, 'Bitte speichern Sie den Angebotsentwurf zuerst.', 'lead_offer_draft_required');
        }
        $round = max(1, (int) ($ticket['leadProcess']['offer']['round'] ?? 1));
        $content = $this->pdfGenerator->generate($ticket, $draft, $round);
        return [
            'content' => $content,
            'name' => sprintf('Angebot-%s-R%d-Entwurf.pdf', (string) $ticket['number'], $round),
            'mimeType' => 'application/pdf',
            'size' => strlen($content),
        ];
    }

    /** Generiert aus dem gespeicherten Snapshot das PDF und versendet es. */
    public function sendOffer(AuthContext $auth, string $ticketId): array
    {
        $this->requireManager($auth);
        $ticket = $this->leadTicket($ticketId);
        if (($ticket['leadProcess']['contact']['status'] ?? 'pending') !== 'completed') {
            throw new ApiException(409, 'Bitte dokumentieren Sie zuerst die Kontaktaufnahme.', 'lead_contact_required');
        }
        if (($ticket['leadProcess']['offer']['status'] ?? 'pending') !== 'pending') {
            throw new ApiException(409, 'Die aktuelle Angebotsrunde ist nicht zum Versand freigegeben.', 'lead_offer_not_ready');
        }
        $draft = $ticket['leadProcess']['offer']['draft'] ?? null;
        if (!is_array($draft) || $draft === []) {
            throw new ApiException(409, 'Bitte speichern Sie zuerst den strukturierten Angebotsentwurf.', 'lead_offer_draft_required');
        }
        $recipientEmail = (string) ($ticket['requester']['email'] ?? '');
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Für den Lead ist keine gültige E-Mail-Adresse hinterlegt.', 'lead_email_missing');
        }

        $user = $this->users->findById($auth->userId);
        $round = max(1, (int) ($ticket['leadProcess']['offer']['round'] ?? (count($ticket['leadProcess']['offerHistory'] ?? []) + 1)));
        $offerNumber = sprintf('A-%s-R%d', (string) $ticket['number'], $round);
        $pdf = $this->pdfGenerator->generate($ticket, $draft, $round);
        $documentName = $offerNumber . '.pdf';
        $message = $this->messages->create($ticketId, [
            'bodyHtml' => sprintf('<p><strong>Strukturiertes Angebot %s versendet.</strong> Das erzeugte PDF wurde dem Anfragenden über einen geschützten Angebotslink bereitgestellt.</p>', htmlspecialchars($offerNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'bodyText' => sprintf('Strukturiertes Angebot %s versendet. Das erzeugte PDF wurde über einen geschützten Angebotslink bereitgestellt.', $offerNumber),
            'author' => [
                'type' => 'staff',
                'userId' => $auth->userId,
                'name' => (string) ($user['name'] ?? $auth->email),
                'email' => $auth->email,
            ],
            'internal' => true,
            'eventType' => 'lead_offer_sent',
        ]);
        $this->tickets->registerMessage($ticketId, true);
        $attachment = $this->attachments->storeContent(
            $ticketId,
            (string) $message['_id'],
            $pdf,
            $documentName,
            'application/pdf',
            $auth->userId,
        );
        $this->messages->addAttachment((string) $message['_id'], $attachment);
        $this->tickets->registerAttachment($ticketId, true);

        $token = bin2hex(random_bytes(32));
        $now = new UTCDateTime();
        $offer = [
            'status' => 'sent',
            'round' => $round,
            'offerNumber' => $offerNumber,
            // Der vollständig berechnete Snapshot bleibt unveränderlich an der
            // versendeten Runde und wird bei Annahme Vertragsgrundlage.
            'draft' => $draft,
            // Auch die Empfänger- und Rechnungsdaten gehören zur konkreten
            // Angebotsversion. Stammdatenänderungen am Lead dürfen ein bereits
            // versendetes Angebot später nicht rückwirkend verändern.
            'counterparty' => [
                'company' => (string) ($ticket['requester']['company'] ?? ''),
                'contactName' => (string) ($ticket['requester']['name'] ?? ''),
                'email' => $recipientEmail,
                'phone' => (string) ($ticket['requester']['phone'] ?? ''),
                'billingAddress' => $ticket['requester']['billingAddress'] ?? null,
            ],
            'tokenHash' => hash('sha256', $token),
            'attachmentId' => $attachment['id'],
            'documentName' => $attachment['name'],
            'documentSize' => $attachment['size'],
            'recipientEmail' => $recipientEmail,
            'sentAt' => $now,
            'sentBy' => [
                'userId' => $auth->userId,
                'name' => (string) ($user['name'] ?? $auth->email),
                'email' => $auth->email,
            ],
            'emailStatus' => 'pending',
        ];
        $this->tickets->update($ticketId, [
            'leadProcess.offer' => $offer,
            'leadProcess.nextAction' => ['status' => 'not_required', 'reason' => 'awaiting_decision'],
            'status' => 'in_progress',
            'updatedBy' => $auth->userId,
        ]);

        $reviewUrl = $this->frontendUrl . '/angebot.html?token=' . $token;
        try {
            $this->notifications->sendLeadOffer(
                $recipientEmail,
                (string) ($ticket['requester']['name'] ?? $recipientEmail),
                (string) $ticket['number'],
                $documentName,
                $reviewUrl . '&decision=accepted',
                $reviewUrl . '&decision=rejected',
            );
            $this->tickets->update($ticketId, ['leadProcess.offer.emailStatus' => 'sent']);
        } catch (MailDeliveryException) {
            $this->tickets->update($ticketId, ['leadProcess.offer.emailStatus' => 'failed']);
            throw new ApiException(502, 'Das Angebot wurde gespeichert, die E-Mail konnte jedoch nicht versendet werden.', 'lead_offer_mail_failed');
        }

        if (!empty($ticket['inquiryId'])) {
            $this->inquiries->update((string) $ticket['inquiryId'], ['status' => 'qualified']);
        }
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /**
     * Versendet die aktuelle Angebotsrunde erneut, ohne PDF, Preise oder
     * Angebotsnummer zu verändern. Aus Sicherheitsgründen wird dabei ein
     * frischer Entscheidungslink erzeugt und der vorherige Link deaktiviert.
     */
    public function resendOffer(AuthContext $auth, string $ticketId): array
    {
        $this->requireSales($auth);
        $ticket = $this->leadTicket($ticketId);
        $offer = is_array($ticket['leadProcess']['offer'] ?? null) ? $ticket['leadProcess']['offer'] : [];
        if (($offer['status'] ?? 'pending') !== 'sent') {
            throw new ApiException(409, 'Nur ein versendetes Angebot mit ausstehender Entscheidung kann erneut verschickt werden.', 'lead_offer_not_resendable');
        }

        $recipientEmail = (string) ($offer['counterparty']['email'] ?? $ticket['requester']['email'] ?? '');
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Für das Angebot ist keine gültige Empfängeradresse hinterlegt.', 'lead_email_missing');
        }
        $documentName = (string) ($offer['documentName'] ?? '');
        $offerNumber = (string) ($offer['offerNumber'] ?? '');
        if ($documentName === '' || $offerNumber === '' || empty($offer['attachmentId'])) {
            throw new ApiException(409, 'Das versendete Angebotsdokument ist nicht vollständig hinterlegt.', 'lead_offer_document_missing');
        }

        $user = $this->users->findById($auth->userId);
        $token = bin2hex(random_bytes(32));
        $previousTokenHash = $offer['tokenHash'] ?? null;
        $now = new UTCDateTime();
        $resendCount = max(0, (int) ($offer['resendCount'] ?? 0)) + 1;
        $resendActor = [
            'userId' => $auth->userId,
            'name' => (string) ($user['name'] ?? $auth->email),
            'email' => $auth->email,
        ];

        // Der neue Hash wird vor dem SMTP-Versand aktiviert. Schlägt der
        // Versand fehl, stellen wir den vorherigen gültigen Link wieder her.
        $this->tickets->update($ticketId, [
            'leadProcess.offer.tokenHash' => hash('sha256', $token),
            'leadProcess.offer.emailStatus' => 'pending',
            'updatedBy' => $auth->userId,
        ]);
        $reviewUrl = $this->frontendUrl . '/angebot.html?token=' . $token;
        try {
            $this->notifications->sendLeadOffer(
                $recipientEmail,
                (string) ($offer['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? $recipientEmail),
                (string) $ticket['number'],
                $documentName,
                $reviewUrl . '&decision=accepted',
                $reviewUrl . '&decision=rejected',
            );
        } catch (MailDeliveryException) {
            $this->tickets->update($ticketId, [
                'leadProcess.offer.tokenHash' => $previousTokenHash,
                'leadProcess.offer.emailStatus' => 'failed',
                'updatedBy' => $auth->userId,
            ]);
            throw new ApiException(502, 'Die Angebotsmail konnte nicht erneut versendet werden.', 'lead_offer_resend_mail_failed');
        }

        $this->tickets->update($ticketId, [
            'leadProcess.offer.emailStatus' => 'sent',
            'leadProcess.offer.resendCount' => $resendCount,
            'leadProcess.offer.lastResentAt' => $now,
            'leadProcess.offer.lastResentBy' => $resendActor,
            'updatedBy' => $auth->userId,
        ]);
        $this->addSystemMessage(
            $ticketId,
            sprintf('Angebotsmail %s erneut versendet', $offerNumber),
            sprintf('%s hat die unveränderte Angebotsrunde erneut an %s versendet. Der vorherige Entscheidungslink wurde ersetzt.', $resendActor['name'], $recipientEmail),
            'lead_offer_resent',
        );
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /** Liefert ausschließlich die für die öffentliche Angebotsseite nötigen Daten. */
    public function publicOffer(string $token): array
    {
        $ticket = $this->ticketByToken($token);
        $offer = $ticket['leadProcess']['offer'];
        return DocumentSerializer::serialize([
            'ticketNumber' => $ticket['number'],
            'subject' => $ticket['subject'],
            'recipientName' => $offer['counterparty']['contactName'] ?? $ticket['requester']['name'] ?? null,
            'company' => $offer['counterparty']['company'] ?? $ticket['requester']['company'] ?? null,
            'billingAddress' => $offer['counterparty']['billingAddress'] ?? $ticket['requester']['billingAddress'] ?? null,
            'status' => $offer['status'],
            'round' => $offer['round'] ?? 1,
            'offerNumber' => $offer['offerNumber'] ?? null,
            'sentAt' => $offer['sentAt'] ?? null,
            'decisionAt' => $offer['decisionAt'] ?? null,
            'document' => [
                'name' => $offer['documentName'],
                'size' => $offer['documentSize'] ?? null,
                'downloadUrl' => '/api/v1/public/lead-offers/' . $token . '/document',
            ],
            'configuration' => $offer['draft'] ?? $ticket['leadConfiguration'] ?? null,
        ]);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function publicDocument(string $token): array
    {
        $ticket = $this->ticketByToken($token);
        $attachmentId = (string) ($ticket['leadProcess']['offer']['attachmentId'] ?? '');
        if ($attachmentId === '') {
            throw new ApiException(404, 'Das Angebotsdokument wurde nicht gefunden.', 'lead_offer_document_not_found');
        }
        $document = $this->attachments->download($attachmentId, (string) $ticket['_id']);
        unset($document['messageId']);
        return $document;
    }

    public function decide(string $token, string $decision): array
    {
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            throw new ApiException(422, 'Bitte wählen Sie Annahme oder Ablehnung.', 'validation_failed', ['field' => 'decision']);
        }
        $ticket = $this->ticketByToken($token);
        $current = (string) ($ticket['leadProcess']['offer']['status'] ?? 'pending');
        if ($current === $decision) {
            if ($decision === 'accepted') {
                $this->ensureAcceptedContract($ticket);
            }
            return $this->publicOffer($token);
        }
        if ($current !== 'sent') {
            throw new ApiException(409, 'Für dieses Angebot wurde bereits eine Entscheidung gespeichert.', 'lead_offer_already_decided');
        }

        $now = new UTCDateTime();
        $this->tickets->update((string) $ticket['_id'], [
            'leadProcess.offer.status' => $decision,
            'leadProcess.offer.decisionAt' => $now,
            'leadProcess.nextAction' => $decision === 'accepted'
                ? ['status' => 'not_required', 'reason' => 'accepted']
                : ['status' => 'pending', 'round' => (int) ($ticket['leadProcess']['offer']['round'] ?? 1)],
            // Auch eine Ablehnung bleibt eine aktive Vertriebsaufgabe. So kann
            // der Bearbeiter nachfassen oder die nächsten Schritte festlegen.
            'status' => 'in_progress',
            'updatedBy' => 'public_offer_decision',
        ]);
        $label = $decision === 'accepted' ? 'angenommen' : 'abgelehnt';
        $this->addSystemMessage(
            (string) $ticket['_id'],
            'Entscheidung zum Angebot',
            sprintf('Der Anfragende hat das Angebot %s.', $label),
            'lead_offer_' . $decision,
        );
        if (!empty($ticket['inquiryId'])) {
            $this->inquiries->update((string) $ticket['inquiryId'], ['status' => $decision === 'accepted' ? 'won' : 'qualified']);
        }
        if ($decision === 'accepted') {
            $this->ensureAcceptedContract($this->tickets->findById((string) $ticket['_id']));
        } else {
            $this->notifySalesOfRejection($ticket);
        }
        return $this->publicOffer($token);
    }

    /**
     * Nach einer Ablehnung entscheidet der Vertrieb bewusst zwischen einer
     * weiteren Angebotsrunde und dem endgültigen Schließen des Leads.
     */
    public function chooseNextAction(AuthContext $auth, string $ticketId, string $action): array
    {
        $this->requireManager($auth);
        if (!in_array($action, ['new_offer', 'close'], true)) {
            throw new ApiException(422, 'Bitte wählen Sie ein neues Angebot oder das Schließen des Tickets.', 'validation_failed', ['field' => 'action']);
        }
        $ticket = $this->leadTicket($ticketId);
        $offer = $ticket['leadProcess']['offer'] ?? [];
        if (($offer['status'] ?? null) !== 'rejected'
            || ($ticket['leadProcess']['nextAction']['status'] ?? null) !== 'pending') {
            throw new ApiException(409, 'Für dieses Lead-Ticket ist aktuell keine Vertriebsentscheidung offen.', 'lead_next_action_not_pending');
        }

        $user = $this->users->findById($auth->userId);
        $decision = [
            'status' => 'completed',
            'action' => $action,
            'completedAt' => new UTCDateTime(),
            'completedBy' => [
                'userId' => $auth->userId,
                'name' => (string) ($user['name'] ?? $auth->email),
                'email' => $auth->email,
            ],
        ];

        if ($action === 'close') {
            $this->tickets->update($ticketId, [
                'leadProcess.nextAction' => $decision,
                'status' => 'closed',
                'updatedBy' => $auth->userId,
            ]);
            $this->addSystemMessage(
                $ticketId,
                'Lead-Prozess beendet',
                sprintf('%s hat den Lead nach der Angebotsablehnung geschlossen.', (string) ($user['name'] ?? $auth->email)),
                'lead_closed_after_rejection',
            );
            if (!empty($ticket['inquiryId'])) {
                $this->inquiries->update((string) $ticket['inquiryId'], ['status' => 'lost']);
            }
            return $this->safeTicket($this->tickets->findById($ticketId));
        }

        $archivedOffer = $offer;
        unset($archivedOffer['tokenHash']);
        $archivedOffer['archivedAt'] = new UTCDateTime();
        $history = is_array($ticket['leadProcess']['offerHistory'] ?? null)
            ? array_values($ticket['leadProcess']['offerHistory'])
            : [];
        $history[] = $archivedOffer;
        $nextRound = max(2, (int) ($offer['round'] ?? count($history)) + 1);
        $this->tickets->update($ticketId, [
            'leadProcess.offerHistory' => $history,
            // Die nächste Runde startet mit einer bearbeitbaren Kopie der
            // letzten Struktur; die archivierte Version bleibt unverändert.
            'leadProcess.offer' => [
                'status' => 'pending',
                'round' => $nextRound,
                'draft' => is_array($offer['draft'] ?? null) ? $offer['draft'] : $this->contracts->offerDraftFromLead($ticket),
            ],
            'leadProcess.nextAction' => $decision,
            'status' => 'in_progress',
            'updatedBy' => $auth->userId,
        ]);
        $this->addSystemMessage(
            $ticketId,
            sprintf('Angebotsrunde %d gestartet', $nextRound),
            sprintf('%s hat die Erstellung eines neuen Angebots gewählt.', (string) ($user['name'] ?? $auth->email)),
            'lead_new_offer_round_started',
        );
        if (!empty($ticket['inquiryId'])) {
            $this->inquiries->update((string) $ticket['inquiryId'], ['status' => 'qualified']);
        }
        return $this->safeTicket($this->tickets->findById($ticketId));
    }

    /** Informiert bevorzugt den zugewiesenen Vertriebler, sonst das Vertriebsteam. */
    private function notifySalesOfRejection(array $ticket): void
    {
        $recipients = [];
        foreach ([$ticket['assignedToUserId'] ?? null, $ticket['leadProcess']['offer']['sentBy']['userId'] ?? null] as $userId) {
            if (empty($userId)) {
                continue;
            }
            try {
                $user = $this->users->findTicketAssignee((string) $userId);
                if (mb_strtolower((string) ($user['department'] ?? '')) === 'vertrieb') {
                    $recipients[] = $user;
                    break;
                }
            } catch (ApiException) {
                // Gelöschte oder deaktivierte Bearbeiter werden übersprungen.
            }
        }
        if ($recipients === []) {
            $recipients = $this->users->listSalesStaff();
        }

        $sent = 0;
        foreach ($recipients as $recipient) {
            $email = (string) ($recipient['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            try {
                $this->notifications->sendLeadOfferRejected(
                    $email,
                    (string) ($recipient['name'] ?? $email),
                    (string) $ticket['number'],
                    (string) ($ticket['requester']['name'] ?? ''),
                    (string) ($ticket['requester']['company'] ?? ''),
                    $this->frontendUrl . '/admin.html?ticket=' . (string) $ticket['_id'],
                );
                $sent++;
            } catch (MailDeliveryException) {
                // Die Kundenentscheidung bleibt auch bei einem Mailproblem gültig.
            }
        }
        $this->tickets->update((string) $ticket['_id'], [
            'leadProcess.offer.salesNotificationStatus' => $sent > 0 ? 'sent' : 'failed',
            'leadProcess.offer.salesNotificationRecipients' => $sent,
        ]);
    }

    /** Schließt auch nach einem Teilfehler die idempotente Vertragskonvertierung ab. */
    private function ensureAcceptedContract(array $ticket): void
    {
        if (!empty($ticket['leadProcess']['contractId'])) {
            return;
        }
        $offer = $ticket['leadProcess']['offer'] ?? [];
        if (($offer['status'] ?? null) !== 'accepted') {
            return;
        }
        $contract = $this->contracts->createFromAcceptedLead($ticket, $offer);
        $this->tickets->update((string) $ticket['_id'], [
            'leadProcess.contractId' => (string) $contract['_id'],
            'leadProcess.contractNumber' => (string) $contract['number'],
            'leadProcess.contractStatus' => (string) $contract['status'],
        ]);
        $this->addSystemMessage(
            (string) $ticket['_id'],
            'Vertragsentwurf angelegt',
            sprintf('%s wurde automatisch aus der angenommenen Angebotsrunde %d erzeugt und wartet auf Kundenzuordnung.', (string) $contract['number'], (int) ($offer['round'] ?? 1)),
            'contract_draft_created',
        );
    }

    /** @return array<string, mixed> */
    private function leadTicket(string $ticketId): array
    {
        $ticket = $this->tickets->findById($ticketId);
        if (($ticket['type'] ?? null) !== 'lead' || ($ticket['category'] ?? null) !== 'lead') {
            throw new ApiException(422, 'Der Angebotsprozess ist ausschließlich für Tickets der Kategorie Lead-Anfrage verfügbar.', 'lead_ticket_required');
        }
        return $ticket;
    }

    /** @return array<string, mixed> */
    private function ticketByToken(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new ApiException(404, 'Das Angebot wurde nicht gefunden.', 'lead_offer_not_found');
        }
        return $this->tickets->findByLeadOfferTokenHash(hash('sha256', $token));
    }

    private function addSystemMessage(string $ticketId, string $title, string $text, string $eventType = 'lead_contact_completed'): void
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->messages->create($ticketId, [
            'bodyHtml' => sprintf('<p><strong>%s.</strong> %s</p>', $safeTitle, $safeText),
            'bodyText' => $title . '. ' . $text,
            'author' => ['type' => 'system', 'name' => 'Lead-Prozess'],
            'internal' => true,
            'eventType' => $eventType,
        ]);
        $this->tickets->registerMessage($ticketId, true);
    }

    private function requireManager(AuthContext $auth): void
    {
        if (!$auth->canManageTickets()) {
            throw new ApiException(403, 'Diese Aktion ist internen Mitarbeitern vorbehalten.', 'forbidden');
        }
    }

    /** Der erneute externe Versand ist ausschließlich dem Vertrieb vorbehalten. */
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
    private function safeTicket(array $ticket): array
    {
        unset($ticket['leadProcess']['offer']['tokenHash']);
        foreach ($ticket['leadProcess']['offerHistory'] ?? [] as $index => $_offer) {
            unset($ticket['leadProcess']['offerHistory'][$index]['tokenHash']);
        }
        return DocumentSerializer::serialize($ticket);
    }
}
