<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Repository\BrandingAssetRepository;
use ColoManager\Repository\BrandingRepository;
use ColoManager\Support\DocumentBranding;
use ColoManager\Support\DocumentSerializer;

/**
 * Verwaltet das White-Label-Branding mit sicheren Standardwerten, einer
 * strikten Prüfung der hochgeladenen Bilddateien sowie einer validierten
 * YouTube- oder direkten Video-URL für den Startseiten-Hintergrund.
 */
final readonly class BrandingService
{
    public const DEFAULT_COMPANY_NAME = 'COLO MANAGER';
    public const DEFAULT_PRIMARY_COLOR = '#0667F9';
    private const MAX_LOGO_BYTES = 2_097_152;
    private const MIME_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    private const DEFAULT_CONTENT = [
        'landing' => [
            'navTariffs' => 'Tarife',
            'navBenefits' => 'Vorteile',
            'navProcess' => 'Ablauf',
            'navStatus' => 'Systemstatus',
            'navFaq' => 'FAQ',
            'loginLabel' => 'Kundenlogin',
            'inquiryLabel' => 'Angebot anfragen',
            'heroEyebrow' => 'Colocation ohne Umwege',
            'heroTitle' => 'Ihre Systeme.',
            'heroAccent' => 'Unser Datacenter.',
            'heroDescription' => 'Sichere Rackflächen, planbare Leistung und skalierbare Bandbreite – transparent kalkuliert und persönlich begleitet.',
            'heroPrimaryCta' => 'Rack-Typ auswählen',
            'heroSecondaryCta' => 'Individuell anfragen',
            'heroBenefit1' => '24/7 Zutritt',
            'heroBenefit2' => 'Redundante Versorgung',
            'heroBenefit3' => 'Persönlicher Support',
            'statusEyebrow' => 'Betriebsstatus',
            'statusTitle' => 'Datacenter bereit',
            'statusAvailable' => 'Verfügbar',
            'statusAvailabilityValue' => '99,99 %',
            'statusAvailabilityLabel' => 'Verfügbarkeit',
            'statusSupportValue' => '24/7',
            'statusSupportLabel' => 'Service Desk',
            'statusCtaEyebrow' => 'Bereit für Ihren ersten Schritt?',
            'statusCtaTitle' => 'In 2 Minuten zur Anfrage.',
            'plansEyebrow' => 'Colocation-Tarife',
            'plansTitle' => 'Ein guter Platz für Wachstum.',
            'plansDescription' => 'Entdecken Sie den vollständigen Produktkatalog. Den gewünschten und für das Produkt verfügbaren Standort wählen Sie erst im ersten Schritt der Anfrage.',
            'benefitsEyebrow' => 'Ihre Infrastruktur',
            'benefitsTitle' => 'Professionell betrieben. Einfach verwaltet.',
            'benefitsDescription' => 'Von der Zutrittsplanung bis zum Verbrauchsüberblick bündelt COLO MANAGER später alle Services in einem zentralen Kundenportal.',
            'benefit1Title' => 'Redundante Energie',
            'benefit1Description' => 'A/B-Versorgung, USV und Notstrom für planbaren Betrieb.',
            'benefit2Title' => 'Flexible Konnektivität',
            'benefit2Description' => 'Vom Business-Uplink bis zur dedizierten 10-Gbit/s-Anbindung.',
            'benefit3Title' => 'Kontrollierter Zutritt',
            'benefit3Description' => 'Dokumentierte, jederzeit planbare Arbeiten vor Ort.',
            'benefit4Title' => 'Remote Hands',
            'benefit4Description' => 'Unterstützung durch erfahrene Techniker, wenn es darauf ankommt.',
            'processEyebrow' => 'So geht es weiter',
            'processTitle' => 'Vom Bedarf zum Rack.',
            'process1Title' => 'Tarif auswählen',
            'process1Description' => 'Wählen Sie Rackspace, Leistung und eine passende Bandbreite.',
            'process2Title' => 'Anfrage senden',
            'process2Description' => 'Teilen Sie uns die wichtigsten Eckdaten Ihres Projekts mit.',
            'process3Title' => 'Persönlich abstimmen',
            'process3Description' => 'Wir prüfen Kapazität und Details und erstellen Ihr konkretes Angebot.',
            'requestEyebrow' => 'Ihr Rack. Ihre Anforderungen.',
            'requestTitle' => 'Wählen Sie zuerst Ihren Rack-Typ.',
            'requestDescription' => 'Die verfügbaren Rack-Typen werden direkt aus den im Adminbereich gepflegten Tarifen geladen. Danach ergänzen Sie nur noch Bandbreite, Leistung und Laufzeit.',
            'requestButton' => 'Zu den Rack-Typen',
            'requestHint' => 'Unverbindlich · ohne Registrierung · persönliche Rückmeldung',
            'requestStepsEyebrow' => 'So funktioniert es',
            'requestStep1Title' => 'Technik auswählen',
            'requestStep1Description' => 'Rackspace, Strom und Netzwerk passend kombinieren.',
            'requestStep2Title' => 'Kontaktdaten ergänzen',
            'requestStep2Description' => 'Damit wir Rückfragen und das Angebot richtig zuordnen.',
            'requestStep3Title' => 'Individuelles Angebot erhalten',
            'requestStep3Description' => 'Wir prüfen Kapazität und melden uns persönlich.',
            'requestDisclaimer' => 'Noch keine Bestellung oder Vertragsbindung',
            'faqEyebrow' => 'Häufige Fragen',
            'faqTitle' => 'Gut zu wissen.',
            'faqs' => [
                ['question' => 'Ist die Anfrage bereits eine Bestellung?', 'answer' => 'Nein. Die Anfrage ist unverbindlich. Ein Vertrag entsteht erst nach technischer Abstimmung und Ihrer ausdrücklichen Bestätigung.'],
                ['question' => 'Kann ich später mehr Rackspace oder Bandbreite buchen?', 'answer' => 'Ja. Tarife und Netzwerkoptionen sind auf Wachstum ausgelegt und können abhängig von der verfügbaren Kapazität erweitert werden.'],
                ['question' => 'Wie schnell kann ein Rack bereitgestellt werden?', 'answer' => 'Das hängt von Kapazität, Verkabelung und Ihren Anforderungen ab. Nach der Anfrage erhalten Sie eine konkrete Einschätzung.'],
            ],
            'footerTagline' => 'Vendor-neutrale Colocation Services',
            'footerStatus' => 'Systemstatus',
            'footerLogin' => 'Kundenlogin',
            'footerPrivacy' => 'Datenschutz',
            'footerImprint' => 'Impressum',
        ],
        'portal' => [
            'headerTitle' => 'Datacenter Kundenportal',
            'navOverviewEyebrow' => 'Übersicht',
            'navDashboard' => 'Dashboard',
            'navRacks' => 'Racks & Server',
            'navNetwork' => 'Netzwerk',
            'navServiceEyebrow' => 'Service',
            'navTickets' => 'Tickets',
            'navPlans' => 'Tarife & Optionen',
            'navDocuments' => 'Verträge & Dokumente',
            'navAccount' => 'Mein Konto',
            'supportEyebrow' => 'Direkter Kontakt',
            'supportTitle' => '24/7 Datacenter Service',
            'supportLink' => 'Support starten',
            'logoutLabel' => 'Sicher abmelden',
            'heroEyebrowPrefix' => 'Willkommen zurück,',
            'heroTitle' => 'Ihr Vertrag.',
            'heroAccent' => 'Ihr Service-Team.',
            'heroDescription' => 'Vertrag, gebuchter Tarif und Supportanfragen übersichtlich an einem zentralen Ort.',
            'heroTicketCta' => 'Neues Ticket',
            'heroContractsCta' => 'Verträge öffnen',
            'contractsEyebrow' => 'Vertragsverhältnis',
            'contractsTitle' => 'Ihr Vertrag',
            'contractsDescription' => 'Laufzeit, Status und unterschriebene Vertragsfassung auf einen Blick.',
            'contractsSecurity' => 'Geschützt',
            'planEyebrow' => 'Ihr aktueller Tarif',
            'planUpgrade' => 'Upgrade-Möglichkeiten',
            'ticketsEyebrow' => 'Support',
            'ticketsTitle' => 'Aktuelle Tickets',
            'ticketsAll' => 'Alle Tickets anzeigen',
            'contactsEyebrow' => 'Persönlich erreichbar',
            'contactsTitle' => 'Ihre Ansprechpartner',
            'contactsDescription' => 'Feste Kontakte für Technik und kaufmännische Fragen.',
            'technicianLabel' => 'Technik',
            'salesLabel' => 'Vertrieb',
            'contactsTicket' => 'Kontakt über Ticket aufnehmen',
            'footerText' => '© 2026 COLO MANAGER · Vendor-neutrales Kundenportal',
            'footerPrivacy' => 'Datenschutz',
            'footerImprint' => 'Impressum',
            'footerStatus' => 'Statusseite',
            'footerHelp' => 'Hilfe',
        ],
    ];

    public function __construct(
        private BrandingRepository $branding,
        private BrandingAssetRepository $assets,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(): array
    {
        return $this->serialize($this->branding->find());
    }

    /**
     * Liefert Logo, Name und Farbe als unveränderlichen Snapshot an PDF- und
     * Mailausgaben. Ein defektes/verwaistes Logo blockiert die Dokumentausgabe
     * nicht; in diesem Fall greift der Initialen-Fallback.
     */
    public function documentBranding(?string $publicBaseUrl = null): DocumentBranding
    {
        $settings = $this->serialize($this->branding->find());
        $logoUrl = is_string($settings['logoUrl']) ? $settings['logoUrl'] : null;
        if ($logoUrl !== null && $publicBaseUrl !== null && $publicBaseUrl !== '') {
            $logoUrl = rtrim($publicBaseUrl, '/') . '/' . ltrim($logoUrl, '/');
        }

        return new DocumentBranding(
            companyName: (string) $settings['companyName'],
            primaryColor: (string) $settings['primaryColor'],
            logoUrl: $logoUrl,
            // Das unter Umständen mehrere Megabyte große GridFS-Asset wird
            // erst geladen, wenn tatsächlich ein PDF erzeugt wird. Normale
            // API-Aufrufe und reine Mailausgaben bleiben dadurch schlank.
            logoLoader: $settings['hasLogo'] === true
                ? fn (): array => $this->logo()
                : null,
        );
    }

    /** @param array<string, mixed> $payload @param array<string, list<array<string, mixed>>> $files @return array<string, mixed> */
    public function update(AuthContext $auth, array $payload, array $files): array
    {
        $this->requirePlatformAdmin($auth);
        $companyName = trim((string) ($payload['companyName'] ?? ''));
        $primaryColor = strtoupper(trim((string) ($payload['primaryColor'] ?? '')));

        $characterCount = preg_match_all('/./us', $companyName, $characters);
        if ($companyName === '' || $characterCount === false || $characterCount > 80) {
            throw new ApiException(422, 'Der Unternehmensname muss zwischen 1 und 80 Zeichen lang sein.', 'validation_failed', ['field' => 'companyName']);
        }
        if (preg_match('/^#[0-9A-F]{6}$/', $primaryColor) !== 1) {
            throw new ApiException(422, 'Die Primärfarbe muss als sechsstelliger HEX-Wert angegeben werden.', 'validation_failed', ['field' => 'primaryColor']);
        }

        $current = $this->branding->find();
        // Fehlt das Feld bei älteren API-Clients, bleibt die vorhandene
        // Konfiguration bestehen. Ein explizit leerer Wert entfernt das Video.
        $heroVideo = array_key_exists('heroVideoUrl', $payload)
            ? $this->heroVideoSource((string) $payload['heroVideoUrl'])
            : [
                'type' => isset($current['heroVideoType']) ? (string) $current['heroVideoType'] : null,
                'url' => isset($current['heroVideoUrl']) ? (string) $current['heroVideoUrl'] : null,
                'youtubeVideoId' => isset($current['heroYoutubeVideoId']) ? (string) $current['heroYoutubeVideoId'] : null,
            ];
        $content = array_key_exists('content', $payload)
            ? $this->validateContent((string) $payload['content'])
            : $this->mergeContent(isset($current['content']) && is_array($current['content']) ? $current['content'] : []);
        $updated = $this->branding->saveSettings([
            'companyName' => $companyName,
            'primaryColor' => $primaryColor,
            'heroVideoType' => $heroVideo['type'],
            'heroVideoUrl' => $heroVideo['url'],
            'heroYoutubeVideoId' => $heroVideo['youtubeVideoId'],
            'content' => $content,
        ], $auth->userId);

        $logo = $files['logo'][0] ?? null;
        if (is_array($logo) && (int) ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $validated = $this->validateLogo($logo);
            $stored = $this->assets->store(
                (string) $logo['tmp_name'],
                'platform-logo.' . $validated['extension'],
                $validated['mimeType'],
                (int) $logo['size'],
                $auth->userId,
            );
            $updated = $this->branding->setLogo($stored, $auth->userId);
            $this->assets->delete(isset($current['logo']['id']) ? (string) $current['logo']['id'] : null);
        }

        return $this->serialize($updated);
    }

    /** @return array<string, mixed> */
    public function removeLogo(AuthContext $auth): array
    {
        $this->requirePlatformAdmin($auth);
        $current = $this->branding->find();
        $updated = $this->branding->clearLogo($auth->userId);
        $this->assets->delete(isset($current['logo']['id']) ? (string) $current['logo']['id'] : null);
        return $this->serialize($updated);
    }

    /** @return array{content: string, name: string, mimeType: string, size: int} */
    public function logo(): array
    {
        $settings = $this->branding->find();
        $logoId = isset($settings['logo']['id']) ? (string) $settings['logo']['id'] : '';
        if ($logoId === '') {
            throw new ApiException(404, 'Es wurde noch kein eigenes Logo hinterlegt.', 'branding_logo_not_found');
        }
        return $this->assets->download($logoId);
    }

    /** @param array<string, mixed> $file @return array{mimeType: string, extension: string} */
    private function validateLogo(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'Das Logo konnte nicht vollständig hochgeladen werden.', 'branding_logo_upload_failed');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_LOGO_BYTES) {
            throw new ApiException(422, 'Das Logo darf maximal 2 MB groß sein.', 'validation_failed', ['field' => 'logo']);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        if (!is_string($mimeType) || !isset(self::MIME_TYPES[$mimeType]) || $dimensions === false) {
            throw new ApiException(422, 'Erlaubt sind ausschließlich PNG-, JPG- oder WebP-Logos.', 'validation_failed', ['field' => 'logo']);
        }
        if ($dimensions[0] < 32 || $dimensions[1] < 32 || $dimensions[0] > 4096 || $dimensions[1] > 4096) {
            throw new ApiException(422, 'Das Logo muss zwischen 32 und 4096 Pixel breit und hoch sein.', 'validation_failed', ['field' => 'logo']);
        }

        return ['mimeType' => $mimeType, 'extension' => self::MIME_TYPES[$mimeType]];
    }

    /**
     * Akzeptiert eine Video-ID oder übliche youtube.com-/youtu.be-URLs und
     * gibt ausschließlich die ungefährliche elfstellige YouTube-ID zurück.
     */
    private function youtubeVideoId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1) {
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            throw new ApiException(422, 'Bitte geben Sie eine gültige YouTube-URL oder Video-ID an.', 'validation_failed', ['field' => 'heroVideoUrl']);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ApiException(422, 'Bitte geben Sie eine gültige YouTube-URL oder Video-ID an.', 'validation_failed', ['field' => 'heroVideoUrl']);
        }

        $videoId = null;
        if ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
            $videoId = explode('/', trim((string) ($parts['path'] ?? ''), '/'))[0] ?? null;
        } elseif (
            $host === 'youtube.com'
            || str_ends_with($host, '.youtube.com')
            || $host === 'youtube-nocookie.com'
            || str_ends_with($host, '.youtube-nocookie.com')
        ) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = isset($query['v']) ? (string) $query['v'] : null;
            if ($videoId === null || $videoId === '') {
                $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
                if (in_array($segments[0] ?? '', ['embed', 'shorts', 'live'], true)) {
                    $videoId = $segments[1] ?? null;
                }
            }
        }

        if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            throw new ApiException(422, 'Die YouTube-URL enthält keine gültige Video-ID.', 'validation_failed', ['field' => 'heroVideoUrl']);
        }
        return $videoId;
    }

    /**
     * Normalisiert entweder einen YouTube-Link oder eine direkte Browser-
     * Video-URL. Die Anwendung lädt die externe Datei nicht serverseitig.
     *
     * @return array{type: ?string, url: ?string, youtubeVideoId: ?string}
     */
    private function heroVideoSource(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['type' => null, 'url' => null, 'youtubeVideoId' => null];
        }

        try {
            $youtubeVideoId = $this->youtubeVideoId($value);
            if ($youtubeVideoId !== null) {
                return [
                    'type' => 'youtube',
                    'url' => 'https://www.youtube.com/watch?v=' . $youtubeVideoId,
                    'youtubeVideoId' => $youtubeVideoId,
                ];
            }
        } catch (ApiException) {
            // Direkte Video-URLs werden im nächsten Schritt separat geprüft.
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            throw new ApiException(422, 'Erlaubt sind YouTube-Links oder direkte URLs zu MP4-, WebM- und OGV-Videos.', 'validation_failed', ['field' => 'heroVideoUrl']);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($extension, ['mp4', 'webm', 'ogv'], true)) {
            throw new ApiException(422, 'Erlaubt sind YouTube-Links oder direkte URLs zu MP4-, WebM- und OGV-Videos.', 'validation_failed', ['field' => 'heroVideoUrl']);
        }
        return ['type' => 'direct', 'url' => $value, 'youtubeVideoId' => null];
    }

    /** @return array<string, mixed> */
    private function validateContent(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(422, 'Die Seiteninhalte konnten nicht gelesen werden.', 'validation_failed', ['field' => 'content']);
        }
        if (!is_array($decoded)) {
            throw new ApiException(422, 'Die Seiteninhalte müssen als Objekt übermittelt werden.', 'validation_failed', ['field' => 'content']);
        }

        $content = self::DEFAULT_CONTENT;
        foreach (['landing', 'portal'] as $section) {
            $submitted = $decoded[$section] ?? [];
            if (!is_array($submitted)) {
                throw new ApiException(422, 'Der Inhaltsbereich ist ungültig.', 'validation_failed', ['field' => 'content.' . $section]);
            }
            foreach (self::DEFAULT_CONTENT[$section] as $key => $default) {
                if ($key === 'faqs') {
                    continue;
                }
                $value = trim((string) ($submitted[$key] ?? $default));
                $maxLength = str_contains(strtolower($key), 'description') ? 800 : 240;
                $length = preg_match_all('/./us', $value, $characters);
                if ($value === '' || $length === false || $length > $maxLength) {
                    throw new ApiException(422, sprintf('Der Inhalt „%s“ muss zwischen 1 und %d Zeichen lang sein.', $key, $maxLength), 'validation_failed', ['field' => 'content.' . $section . '.' . $key]);
                }
                $content[$section][$key] = $value;
            }
        }

        $submittedFaqs = $decoded['landing']['faqs'] ?? self::DEFAULT_CONTENT['landing']['faqs'];
        if (!is_array($submittedFaqs) || count($submittedFaqs) > 20) {
            throw new ApiException(422, 'Es sind maximal 20 FAQ-Einträge möglich.', 'validation_failed', ['field' => 'content.landing.faqs']);
        }
        $content['landing']['faqs'] = [];
        foreach ($submittedFaqs as $index => $faq) {
            if (!is_array($faq)) {
                throw new ApiException(422, 'Ein FAQ-Eintrag ist ungültig.', 'validation_failed', ['field' => 'content.landing.faqs.' . $index]);
            }
            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || mb_strlen($question) > 240 || $answer === '' || mb_strlen($answer) > 1600) {
                throw new ApiException(422, 'FAQ-Fragen dürfen 240 und Antworten 1.600 Zeichen nicht überschreiten.', 'validation_failed', ['field' => 'content.landing.faqs.' . $index]);
            }
            $content['landing']['faqs'][] = ['question' => $question, 'answer' => $answer];
        }
        return $content;
    }

    /** @param array<string, mixed> $stored @return array<string, mixed> */
    private function mergeContent(array $stored): array
    {
        $content = self::DEFAULT_CONTENT;
        foreach (['landing', 'portal'] as $section) {
            $values = isset($stored[$section]) && is_array($stored[$section]) ? $stored[$section] : [];
            foreach (self::DEFAULT_CONTENT[$section] as $key => $default) {
                if ($key === 'faqs') {
                    continue;
                }
                if (isset($values[$key]) && is_string($values[$key]) && trim($values[$key]) !== '') {
                    $content[$section][$key] = $values[$key];
                }
            }
        }
        if (isset($stored['landing']['faqs']) && is_array($stored['landing']['faqs'])) {
            $faqs = [];
            foreach (array_slice($stored['landing']['faqs'], 0, 20) as $faq) {
                if (is_array($faq) && trim((string) ($faq['question'] ?? '')) !== '' && trim((string) ($faq['answer'] ?? '')) !== '') {
                    $faqs[] = ['question' => (string) $faq['question'], 'answer' => (string) $faq['answer']];
                }
            }
            $content['landing']['faqs'] = $faqs;
        }
        return $content;
    }

    /** @param array<string, mixed>|null $document @return array<string, mixed> */
    private function serialize(?array $document): array
    {
        $serialized = $document === null ? [] : DocumentSerializer::serialize($document);
        $updatedAt = $serialized['updatedAt'] ?? null;
        $hasLogo = !empty($serialized['logo']['id']);
        $heroYoutubeVideoId = preg_match('/^[A-Za-z0-9_-]{11}$/', (string) ($serialized['heroYoutubeVideoId'] ?? '')) === 1
            ? (string) $serialized['heroYoutubeVideoId']
            : null;
        $heroVideoType = in_array(($serialized['heroVideoType'] ?? null), ['youtube', 'direct'], true)
            ? (string) $serialized['heroVideoType']
            : ($heroYoutubeVideoId !== null ? 'youtube' : null);
        $heroVideoUrl = $heroVideoType === 'youtube' && $heroYoutubeVideoId !== null
            ? 'https://www.youtube.com/watch?v=' . $heroYoutubeVideoId
            : ($heroVideoType === 'direct' ? (string) ($serialized['heroVideoUrl'] ?? '') : null);

        return [
            'companyName' => (string) ($serialized['companyName'] ?? self::DEFAULT_COMPANY_NAME),
            'primaryColor' => (string) ($serialized['primaryColor'] ?? self::DEFAULT_PRIMARY_COLOR),
            'heroVideoType' => $heroVideoType,
            'heroVideoUrl' => $heroVideoUrl !== '' ? $heroVideoUrl : null,
            'heroYoutubeVideoId' => $heroYoutubeVideoId,
            'content' => $this->mergeContent(isset($serialized['content']) && is_array($serialized['content']) ? $serialized['content'] : []),
            'hasLogo' => $hasLogo,
            'logoUrl' => $hasLogo ? '/api/v1/public/branding/logo?v=' . rawurlencode((string) ($updatedAt ?? '1')) : null,
            'logo' => $hasLogo ? [
                'name' => (string) ($serialized['logo']['name'] ?? 'Logo'),
                'mimeType' => (string) ($serialized['logo']['mimeType'] ?? ''),
                'size' => (int) ($serialized['logo']['size'] ?? 0),
            ] : null,
            'updatedAt' => $updatedAt,
        ];
    }

    private function requirePlatformAdmin(AuthContext $auth): void
    {
        if (!$auth->isPlatformAdmin()) {
            throw new ApiException(403, 'Das Plattform-Branding darf nur durch Administratoren geändert werden.', 'forbidden');
        }
    }
}
