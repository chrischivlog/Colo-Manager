<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use ColoManager\Support\DocumentBranding;

/**
 * Rendert transaktionsorientierte E-Mails ohne Template-Engine. Alle variablen
 * Inhalte werden escaped, bevor sie in HTML eingesetzt werden.
 */
final class MailTemplateRenderer
{
    public function __construct(private readonly DocumentBranding $branding = new DocumentBranding())
    {
    }

    public function passwordReset(string $recipientName, string $resetUrl, int $expiresInMinutes): RenderedMail
    {
        $title = 'Passwort zurücksetzen';
        $intro = sprintf('Hallo %s,', $recipientName);
        $body = sprintf('für Ihr %s Konto wurde das Zurücksetzen des Passworts angefordert.', $this->branding->companyName);
        $hint = sprintf('Der Link ist %d Minuten gültig. Falls Sie die Anfrage nicht gestellt haben, können Sie diese E-Mail ignorieren.', $expiresInMinutes);

        return $this->render(
            subject: sprintf('Passwort für %s zurücksetzen', $this->branding->companyName),
            title: $title,
            intro: $intro,
            body: $body,
            actionLabel: 'Neues Passwort festlegen',
            actionUrl: $resetUrl,
            hint: $hint,
        );
    }

    public function ticketCreated(string $recipientName, string $ticketNumber, string $ticketSubject, string $ticketUrl): RenderedMail
    {
        return $this->render(
            subject: sprintf('Ticket %s wurde erstellt', $ticketNumber),
            title: 'Ticket erfolgreich erstellt',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('Ihre Anfrage „%s“ wurde unter der Nummer %s aufgenommen. Unser Support-Team meldet sich bei Ihnen.', $ticketSubject, $ticketNumber),
            actionLabel: 'Ticket ansehen',
            actionUrl: $ticketUrl,
            hint: 'Antworten und Statusänderungen werden direkt im Kundenportal angezeigt.',
        );
    }

    public function ticketUpdated(string $recipientName, string $ticketNumber, string $updateMessage, string $ticketUrl): RenderedMail
    {
        return $this->render(
            subject: sprintf('Neue Aktivität in Ticket %s', $ticketNumber),
            title: 'Ihr Ticket wurde aktualisiert',
            intro: sprintf('Hallo %s,', $recipientName),
            body: $updateMessage,
            actionLabel: 'Aktualisierung öffnen',
            actionUrl: $ticketUrl,
            hint: sprintf('Diese Nachricht gehört zum Ticket %s.', $ticketNumber),
        );
    }

    public function systemUpdate(string $recipientName, string $title, string $message, string $portalUrl): RenderedMail
    {
        return $this->render(
            subject: $title,
            title: $title,
            intro: sprintf('Hallo %s,', $recipientName),
            body: $message,
            actionLabel: 'Zum Kundenportal',
            actionUrl: $portalUrl,
            hint: 'Sie erhalten diese Nachricht aufgrund Ihrer Benachrichtigungseinstellungen.',
        );
    }

    public function inquiryReceived(
        string $recipientName,
        string $company,
        string $planName,
        string $ticketNumber,
        string $configurationSummary,
        string $offersUrl,
    ): RenderedMail
    {
        return $this->render(
            subject: 'Ihre Colocation-Anfrage ist eingegangen',
            title: 'Ihre Konfiguration ist bei uns',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf("wir haben die Anfrage von %s zum Tarif „%s“ als Lead-Ticket %s in unserem Datacenter erfasst.\n\nIhre Auswahl:\n%s\n\nUnser Team prüft Kapazität und technische Details und erstellt daraus ein individuelles Angebot. Bitte geben Sie uns dafür ein wenig Zeit – ein Ansprechpartner meldet sich persönlich bei Ihnen.", $company, $planName, $ticketNumber, $configurationSummary),
            actionLabel: 'Konfiguration erneut ansehen',
            actionUrl: $offersUrl,
            hint: 'Diese Nachricht bestätigt den Eingang Ihrer unverbindlichen Anfrage. Sie ist noch keine Bestellung und kein verbindliches Angebot.',
        );
    }

    public function leadOffer(
        string $recipientName,
        string $ticketNumber,
        string $documentName,
        string $acceptUrl,
        string $rejectUrl,
    ): RenderedMail {
        return $this->render(
            subject: sprintf('Ihr individuelles Angebot zu %s', $ticketNumber),
            title: 'Ihr Colocation-Angebot ist bereit',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('unser Vertrieb hat Ihr individuelles Angebot „%s“ zur Anfrage %s fertiggestellt. Bitte prüfen Sie das Dokument und teilen Sie uns anschließend Ihre Entscheidung mit.', $documentName, $ticketNumber),
            actionLabel: 'Angebot annehmen',
            actionUrl: $acceptUrl,
            hint: 'Ihre Auswahl wird eindeutig zu Ihrer Anfrage gespeichert. Bei Rückfragen wenden Sie sich bitte an Ihren Ansprechpartner.',
            secondaryActionLabel: 'Angebot ablehnen',
            secondaryActionUrl: $rejectUrl,
        );
    }

    public function leadOfferRejected(
        string $recipientName,
        string $ticketNumber,
        string $requesterName,
        string $company,
        string $ticketUrl,
    ): RenderedMail {
        $leadName = trim($requesterName . ($company !== '' ? ' von ' . $company : ''));
        return $this->render(
            subject: sprintf('Lead %s: Angebot wurde abgelehnt', $ticketNumber),
            title: 'Angebot wurde abgelehnt',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('%s hat das individuelle Angebot zum Lead-Ticket %s abgelehnt. Das Ticket wurde wieder in die aktive Vertriebsbearbeitung gelegt. Bitte prüfen Sie den Verlauf und nehmen Sie bei Bedarf erneut Kontakt auf.', $leadName !== '' ? $leadName : 'Der Anfragende', $ticketNumber),
            actionLabel: 'Lead-Ticket öffnen',
            actionUrl: $ticketUrl,
            hint: 'Diese interne Nachricht wurde automatisch durch die Angebotsentscheidung ausgelöst.',
        );
    }

    public function contractForSignature(
        string $recipientName,
        string $contractNumber,
        ?string $ticketNumber,
        string $signatureUrl,
        int $expiresInDays,
    ): RenderedMail {
        $context = $ticketNumber !== null && $ticketNumber !== ''
            ? sprintf('auf Grundlage Ihres angenommenen Angebots zur Anfrage %s', $ticketNumber)
            : 'auf Grundlage der mit unserem Vertrieb abgestimmten Leistungen';
        return $this->render(
            subject: sprintf('Ihr Vertrag %s zur Unterschrift', $contractNumber),
            title: 'Ihr Colocation-Vertrag ist bereit',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('%s haben wir den Vertrag %s ausgearbeitet. Bitte laden Sie das Dokument über den geschützten Link herunter, unterschreiben Sie es und laden Sie die vollständige PDF-Fassung anschließend auf derselben Seite wieder hoch.', $context, $contractNumber),
            actionLabel: 'Vertrag prüfen und hochladen',
            actionUrl: $signatureUrl,
            hint: sprintf('Der persönliche Link ist %d Tage gültig. Bitte laden Sie ausschließlich die vollständige, unterschriebene PDF-Fassung hoch.', $expiresInDays),
        );
    }

    public function signedContractReceived(string $recipientName, string $contractNumber, string $ticketUrl): RenderedMail
    {
        return $this->render(
            subject: sprintf('%s wurde unterschrieben hochgeladen', $contractNumber),
            title: 'Unterschriebener Vertrag eingegangen',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('Die unterschriebene Fassung von %s wurde über den geschützten Vertragslink hochgeladen. Der Lead kann nun an das technische Onboarding übergeben werden.', $contractNumber),
            actionLabel: 'Lead-Ticket öffnen',
            actionUrl: $ticketUrl,
            hint: 'Diese interne Nachricht wurde automatisch durch den Dokumentenupload ausgelöst.',
        );
    }

    public function accountInvitation(
        string $recipientName,
        string $company,
        string $activationUrl,
        int $expiresInHours,
    ): RenderedMail {
        return $this->render(
            subject: sprintf('Ihr Zugang zum %s Kundenportal', $this->branding->companyName),
            title: 'Ihr Datacenter-Portal ist vorbereitet',
            intro: sprintf('Hallo %s,', $recipientName),
            body: sprintf('das technische Onboarding für %s ist vorbereitet. Legen Sie jetzt über den persönlichen Link ein Passwort für Ihre E-Mail-Adresse fest. Anschließend können Sie sich im %s Kundenportal anmelden, Ihren Tarif und Ihre Vertragsunterlagen einsehen sowie Tickets erstellen.', $company, $this->branding->companyName),
            actionLabel: 'Portalzugang aktivieren',
            actionUrl: $activationUrl,
            hint: sprintf('Der Einladungslink ist %d Stunden gültig und kann nur einmal verwendet werden.', $expiresInHours),
        );
    }

    public function onboardingAppointmentCustomer(
        string $recipientName,
        string $ticketNumber,
        string $technicianName,
        string $appointmentLabel,
        string $location,
        string $notes,
    ): RenderedMail {
        $details = sprintf(
            "Ihr technischer Onboarding-Termin zur Anfrage %s findet am %s statt. Zuständig ist %s.%s%s",
            $ticketNumber,
            $appointmentLabel,
            $technicianName,
            $location !== '' ? "\n\nOrt: " . $location : '',
            $notes !== '' ? "\n\nHinweis: " . $notes : '',
        );
        return $this->render(
            subject: sprintf('Ihr Onboarding-Termin zu %s', $ticketNumber),
            title: 'Ihr technischer Onboarding-Termin',
            intro: sprintf('Hallo %s,', $recipientName),
            body: $details,
            actionLabel: null,
            actionUrl: null,
            hint: 'Die angehängte iCalendar-Datei können Sie direkt in Outlook, Apple Kalender, Google Kalender oder einer anderen Kalenderanwendung speichern.',
        );
    }

    public function onboardingTechnicianReminder(
        string $technicianName,
        string $ticketNumber,
        string $company,
        string $appointmentLabel,
        string $location,
        string $ticketUrl,
    ): RenderedMail {
        return $this->render(
            subject: sprintf('Heute: Onboarding-Termin %s', $ticketNumber),
            title: 'Onboarding-Termin ist heute',
            intro: sprintf('Hallo %s,', $technicianName),
            body: sprintf(
                'Heute steht das technische Onboarding für %s im Lead-Ticket %s an. Termin: %s.%s',
                $company,
                $ticketNumber,
                $appointmentLabel,
                $location !== '' ? "\n\nOrt: " . $location : '',
            ),
            actionLabel: 'Lead-Ticket öffnen',
            actionUrl: $ticketUrl,
            hint: 'Diese interne Erinnerung wurde automatisch am Tag des hinterlegten Onboarding-Termins erzeugt.',
        );
    }

    private function render(
        string $subject,
        string $title,
        string $intro,
        string $body,
        ?string $actionLabel,
        ?string $actionUrl,
        string $hint,
        ?string $secondaryActionLabel = null,
        ?string $secondaryActionUrl = null,
    ): RenderedMail {
        $safeCompanyName = $this->escape($this->branding->companyName);
        $primaryColor = '#' . $this->branding->primaryHex();
        $darkColor = '#' . $this->branding->darkHex();
        $mutedColor = '#' . $this->branding->mutedHex();
        $safeTitle = $this->escape($title);
        $safeIntro = $this->escape($intro);
        $safeBody = nl2br($this->escape($body));
        $actionHtml = '';
        $actionText = null;
        if ($actionLabel !== null && $actionUrl !== null) {
            $safeActionLabel = $this->escape($actionLabel);
            $safeActionUrl = $this->escape($actionUrl);
            $actionHtml = '<tr><td style="padding:14px 34px 24px;"><a href="' . $safeActionUrl . '" style="display:inline-block;background:' . $primaryColor . ';color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:14px 22px;border-radius:999px;">' . $safeActionLabel . '</a>';
            $actionText = $actionLabel . ': ' . $actionUrl;
        }
        $safeHint = $this->escape($hint);
        $secondaryActionHtml = '';
        $secondaryText = null;
        if ($secondaryActionLabel !== null && $secondaryActionUrl !== null) {
            $safeSecondaryLabel = $this->escape($secondaryActionLabel);
            $safeSecondaryUrl = $this->escape($secondaryActionUrl);
            $secondaryActionHtml = '<a href="' . $safeSecondaryUrl . '" style="display:inline-block;margin-left:10px;border:1px solid #cbd7ec;color:' . $darkColor . ';text-decoration:none;font-size:14px;font-weight:700;padding:13px 22px;border-radius:999px;">' . $safeSecondaryLabel . '</a>';
            $secondaryText = $secondaryActionLabel . ': ' . $secondaryActionUrl;
        }
        if ($actionHtml !== '') {
            $actionHtml .= $secondaryActionHtml . '</td></tr>';
        }

        $logoHtml = '';
        if ($this->branding->logoUrl !== null && $this->branding->logoUrl !== '') {
            $safeLogoUrl = $this->escape($this->branding->logoUrl);
            $logoHtml = '<img src="' . $safeLogoUrl . '" alt="' . $safeCompanyName . '" style="display:block;max-width:180px;max-height:48px;width:auto;height:auto;margin:0 0 12px;">';
        }

        // Tabellenlayout und Inline-CSS sorgen für robuste Darstellung in
        // Outlook, Gmail und anderen restriktiven E-Mail-Clients.
        $html = <<<HTML
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$safeTitle}</title></head>
<body style="margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#000f3d;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fb;padding:32px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:24px;overflow:hidden;">
        <tr><td style="background:{$darkColor};padding:26px 34px;color:#ffffff;">
          {$logoHtml}<div style="font-size:20px;font-weight:800;letter-spacing:-0.5px;">{$safeCompanyName}</div>
          <div style="margin-top:5px;font-size:10px;letter-spacing:2px;color:{$mutedColor};">DATACENTER PORTAL</div>
        </td></tr>
        <tr><td style="padding:38px 34px 18px;">
          <div style="font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:{$primaryColor};">Benachrichtigung</div>
          <h1 style="margin:10px 0 22px;font-size:28px;line-height:1.2;color:{$darkColor};">{$safeTitle}</h1>
          <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">{$safeIntro}</p>
          <p style="margin:0;font-size:16px;line-height:1.7;color:#405075;">{$safeBody}</p>
        </td></tr>
        {$actionHtml}
        <tr><td style="padding:0 34px 36px;">
          <div style="border-top:1px solid #e8eef8;padding-top:20px;font-size:12px;line-height:1.6;color:#77819b;">{$safeHint}</div>
        </td></tr>
      </table>
      <p style="margin:18px 0 0;font-size:11px;color:#8b95ad;">© {$safeCompanyName} · Automatisch erzeugte Nachricht</p>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $textParts = [
            $title,
            $intro,
            $body,
        ];
        if ($actionText !== null) {
            $textParts[] = $actionText;
        }
        if ($secondaryText !== null) {
            $textParts[] = $secondaryText;
        }
        $textParts[] = $hint;
        $textParts[] = $this->branding->companyName . ' · Datacenter Portal';
        $text = implode("\n\n", $textParts);

        return new RenderedMail($subject, $html, $text);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
