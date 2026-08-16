<?php

declare(strict_types=1);

namespace ColoManager\Mail;

/**
 * Fachliche Fassade für alle Portal-E-Mails. Andere Services müssen dadurch
 * weder SMTP noch HTML-Templates kennen.
 */
final readonly class NotificationMailService
{
    public function __construct(
        private MailSender $sender,
        private MailTemplateRenderer $templates,
    ) {
    }

    public function sendPasswordReset(string $email, string $name, string $resetUrl, int $expiresInMinutes = 30): void
    {
        $this->send($email, $this->templates->passwordReset($name, $resetUrl, $expiresInMinutes));
    }

    public function sendTicketCreated(string $email, string $name, string $ticketNumber, string $ticketSubject, string $ticketUrl): void
    {
        $this->send($email, $this->templates->ticketCreated($name, $ticketNumber, $ticketSubject, $ticketUrl));
    }

    public function sendTicketUpdated(string $email, string $name, string $ticketNumber, string $message, string $ticketUrl): void
    {
        $this->send($email, $this->templates->ticketUpdated($name, $ticketNumber, $message, $ticketUrl));
    }

    public function sendSystemUpdate(string $email, string $name, string $title, string $message, string $portalUrl): void
    {
        $this->send($email, $this->templates->systemUpdate($name, $title, $message, $portalUrl));
    }

    public function sendInquiryReceived(
        string $email,
        string $name,
        string $company,
        string $planName,
        string $ticketNumber,
        string $configurationSummary,
        string $offersUrl,
    ): void
    {
        $this->send($email, $this->templates->inquiryReceived($name, $company, $planName, $ticketNumber, $configurationSummary, $offersUrl));
    }

    /** Versendet das individuelle Angebot mit getrennten Annahme-/Ablehnlinks. */
    public function sendLeadOffer(
        string $email,
        string $name,
        string $ticketNumber,
        string $documentName,
        string $acceptUrl,
        string $rejectUrl,
    ): void {
        $this->send($email, $this->templates->leadOffer($name, $ticketNumber, $documentName, $acceptUrl, $rejectUrl));
    }

    /** Informiert den zuständigen Vertrieb über ein abgelehntes Angebot. */
    public function sendLeadOfferRejected(
        string $email,
        string $name,
        string $ticketNumber,
        string $requesterName,
        string $company,
        string $ticketUrl,
    ): void {
        $this->send($email, $this->templates->leadOfferRejected($name, $ticketNumber, $requesterName, $company, $ticketUrl));
    }

    /** Versendet den geschützten Download- und Rückuploadlink für den Vertrag. */
    public function sendContractForSignature(
        string $email,
        string $name,
        string $contractNumber,
        ?string $ticketNumber,
        string $signatureUrl,
        int $expiresInDays = 30,
    ): void {
        $this->send($email, $this->templates->contractForSignature($name, $contractNumber, $ticketNumber, $signatureUrl, $expiresInDays));
    }

    /** Informiert den Vertrieb über den Eingang einer unterschriebenen Fassung. */
    public function sendSignedContractReceived(string $email, string $name, string $contractNumber, string $ticketUrl): void
    {
        $this->send($email, $this->templates->signedContractReceived($name, $contractNumber, $ticketUrl));
    }

    /** Versendet die einmalige Einladung zum Festlegen des Kundenpassworts. */
    public function sendAccountInvitation(string $email, string $name, string $company, string $activationUrl, int $expiresInHours = 72): void
    {
        $this->send($email, $this->templates->accountInvitation($name, $company, $activationUrl, $expiresInHours));
    }

    /** Sendet dem Kunden den bestätigten Termin als lesbare Mail und iCalendar-Datei. */
    public function sendOnboardingAppointment(
        string $email,
        string $name,
        string $ticketNumber,
        string $technicianName,
        string $appointmentLabel,
        string $location,
        string $notes,
        string $calendarContent,
        string $calendarName,
    ): void {
        $this->send(
            $email,
            $this->templates->onboardingAppointmentCustomer($name, $ticketNumber, $technicianName, $appointmentLabel, $location, $notes),
            [new MailAttachment($calendarName, 'text/calendar', $calendarContent)],
        );
    }

    /** Interne Erinnerung für den am Onboarding beteiligten Techniker. */
    public function sendOnboardingTechnicianReminder(
        string $email,
        string $name,
        string $ticketNumber,
        string $company,
        string $appointmentLabel,
        string $location,
        string $ticketUrl,
    ): void {
        $this->send($email, $this->templates->onboardingTechnicianReminder(
            $name,
            $ticketNumber,
            $company,
            $appointmentLabel,
            $location,
            $ticketUrl,
        ));
    }

    /** @param list<MailAttachment> $attachments */
    private function send(string $recipient, RenderedMail $rendered, array $attachments = []): void
    {
        $this->sender->send(new MailMessage(
            recipients: [$recipient],
            subject: $rendered->subject,
            html: $rendered->html,
            text: $rendered->text,
            attachments: $attachments,
        ));
    }
}
