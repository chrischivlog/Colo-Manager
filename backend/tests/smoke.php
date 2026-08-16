<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Kleiner Smoke-Test ohne zusätzliche Testbibliothek. Er läuft innerhalb des
 * API-Containers und prüft den kompletten HTTP-Weg inklusive Apache und MongoDB.
 */

/**
 * @param array<string, mixed>|null $body
 * @return array{status: int, body: array<string, mixed>}
 */
function request(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    if ($raw === false) {
        throw new RuntimeException('HTTP-Aufruf fehlgeschlagen: ' . $method . ' ' . $path);
    }

    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return [
        'status' => (int) ($matches[1] ?? 0),
        'body' => $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
    ];
}

/**
 * Sendet einen echten Multipart-Request, damit die Upload-Grenzen und GridFS
 * nicht nur auf Service-Ebene, sondern über Apache und PHP geprüft werden.
 *
 * @param array<string, string> $fields
 * @param list<array{field: string, name: string, type: string, content: string}> $files
 * @return array{status: int, body: array<string, mixed>}
 */
function multipartRequest(string $path, array $fields, array $files, string $token): array
{
    $boundary = '----ColoManagerSmoke' . bin2hex(random_bytes(8));
    $parts = [];
    foreach ($fields as $name => $value) {
        $parts[] = sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s", $boundary, $name, $value);
    }
    foreach ($files as $file) {
        $parts[] = sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"%s[]\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n%s",
            $boundary,
            $file['field'],
            $file['name'],
            $file['type'],
            $file['content'],
        );
    }
    $content = implode("\r\n", $parts) . "\r\n--{$boundary}--\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($content),
        ]),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Multipart-Aufruf fehlgeschlagen: POST ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

/** @return array{status: int, body: string} */
function rawRequest(string $path, string $token): array
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\nAccept: image/*",
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Binäraufruf fehlgeschlagen: GET ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => $raw];
}

/** Anzahl der im lokalen SMTP-Testpostfach gespeicherten Nachrichten. */
function mailpitMessageCount(): int
{
    $raw = file_get_contents('http://mailpit:8025/api/v1/messages');
    if ($raw === false) {
        throw new RuntimeException('Mailpit konnte für den Benachrichtigungstest nicht gelesen werden.');
    }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return (int) ($payload['messages_count'] ?? $payload['total'] ?? 0);
}

/** Lädt die jüngste Mail mit passendem Betreff aus Mailpit. */
function mailpitMessageBySubject(string $subject): array
{
    $raw = file_get_contents('http://mailpit:8025/api/v1/messages');
    $payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
    foreach ($payload['messages'] ?? [] as $summary) {
        if (($summary['Subject'] ?? null) !== $subject || empty($summary['ID'])) {
            continue;
        }
        $message = file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode((string) $summary['ID']));
        if (is_string($message)) {
            return json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        }
    }
    throw new RuntimeException('Die erwartete Mail wurde in Mailpit nicht gefunden: ' . $subject);
}

/** @param list<int> $allowedStatuses */
function assertStatus(array $response, array $allowedStatuses, string $label): void
{
    if (!in_array($response['status'], $allowedStatuses, true)) {
        throw new RuntimeException(sprintf(
            '%s: Status %d erhalten, erwartet %s. Antwort: %s',
            $label,
            $response['status'],
            implode('/', $allowedStatuses),
            json_encode($response['body'], JSON_UNESCAPED_UNICODE),
        ));
    }
    fwrite(STDOUT, sprintf("[OK] %s (%d)\n", $label, $response['status']));
}

$health = request('GET', '/api/v1/health');
assertStatus($health, [200], 'Healthcheck');

$publicBranding = request('GET', '/api/v1/public/branding');
assertStatus($publicBranding, [200], 'Öffentliches Partner-Branding');
$publicContent = $publicBranding['body']['data']['content'] ?? null;
if (!is_array($publicContent)
    || !is_string($publicContent['landing']['heroTitle'] ?? null)
    || !is_array($publicContent['landing']['faqs'] ?? null)
    || !is_string($publicContent['portal']['headerTitle'] ?? null)
) {
    throw new RuntimeException('Das öffentliche Branding enthält keine vollständige Inhaltskonfiguration.');
}
$brandName = (string) ($publicBranding['body']['data']['companyName'] ?? 'COLO MANAGER');
$brandPrimaryColor = (string) ($publicBranding['body']['data']['primaryColor'] ?? '#0667F9');
$encodedBrandName = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $brandName);
$encodedBrandName = is_string($encodedBrandName) ? $encodedBrandName : $brandName;
$brandHasLogo = ($publicBranding['body']['data']['hasLogo'] ?? false) === true;

$publicOffers = request('GET', '/api/v1/public/offers');
assertStatus($publicOffers, [200], 'Öffentlicher Angebotskatalog');
$publicPlan = $publicOffers['body']['data']['plans'][0] ?? [];
$publicLocations = $publicOffers['body']['data']['locations'] ?? [];
$allowedPublicLocationIds = array_values(array_filter($publicPlan['locationIds'] ?? [], 'is_string'));
$publicLocation = $allowedPublicLocationIds === []
    ? ($publicLocations[0] ?? [])
    : (array_values(array_filter(
        $publicLocations,
        static fn (array $location): bool => in_array($location['id'] ?? null, $allowedPublicLocationIds, true),
    ))[0] ?? []);
$publicPlanId = $publicPlan['id'] ?? null;
$publicBandwidthId = $publicOffers['body']['data']['bandwidthOptions'][0]['id'] ?? null;
$publicLocationId = $publicLocation['id'] ?? null;
$publicBandwidth = $publicOffers['body']['data']['bandwidthOptions'][0] ?? [];
if (!is_string($publicPlanId) || !is_string($publicBandwidthId) || !is_string($publicLocationId)) {
    throw new RuntimeException('Der öffentliche Katalog enthält keine Seed-Angebote.');
}
if (($publicOffers['body']['data']['configurator']['contractTermsMonths'] ?? null) !== [12, 24, 36, 60]) {
    throw new RuntimeException('Die öffentliche Konfigurator-Konfiguration fehlt oder ist unvollständig.');
}

$publicRackUnits = (int) ($publicPlan['rackUnits'] ?? 1);
$publicRackType = $publicRackUnits === 22 ? 'half' : ($publicRackUnits === 44 ? 'full' : 'units');
$publicBillingModel = (string) ($publicBandwidth['billingModel'] ?? 'flat');
$leadBillingAddress = ['street' => 'Teststraße 42', 'postalCode' => '10115', 'city' => 'Berlin', 'country' => 'DE'];
$encodedLeadStreet = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $leadBillingAddress['street']);
$encodedLeadStreet = is_string($encodedLeadStreet) ? $encodedLeadStreet : $leadBillingAddress['street'];

$leadCustomerEmail = 'inquiry-' . bin2hex(random_bytes(5)) . '@example.invalid';
$publicInquiry = request('POST', '/api/v1/public/inquiries', [
    'company' => 'Öffentlicher Testinteressent',
    'contactName' => 'Erika Test',
    'email' => $leadCustomerEmail,
    'locationId' => $publicLocationId,
    'planId' => $publicPlanId,
    'bandwidthOptionId' => $publicBandwidthId,
    'rackUnits' => $publicRackUnits,
    'rackType' => $publicRackType,
    'powerKw' => (float) ($publicPlan['powerKw'] ?? 1.0),
    'networkBillingModel' => $publicBillingModel,
    'contractMonths' => 24,
    'message' => 'Automatisierte Testanfrage',
    'billingAddress' => $leadBillingAddress,
    'consent' => true,
    'website' => '',
]);
assertStatus($publicInquiry, [201], 'Öffentliche Anfrage anlegen');
$publicInquiryId = $publicInquiry['body']['data']['id'] ?? null;
$publicLeadTicketId = $publicInquiry['body']['data']['ticketId'] ?? null;
if (!is_string($publicLeadTicketId) || !is_string($publicInquiry['body']['data']['ticketNumber'] ?? null)) {
    throw new RuntimeException('Die öffentliche Anfrage wurde nicht als Lead-Ticket erfasst.');
}
if (($publicInquiry['body']['data']['confirmationMailStatus'] ?? null) !== 'sent') {
    throw new RuntimeException('Die Bestätigungsmail der öffentlichen Anfrage wurde nicht versendet.');
}
if (($publicInquiry['body']['data']['contractMonths'] ?? null) !== 24
    || ($publicInquiry['body']['data']['rackUnits'] ?? null) !== $publicRackUnits
    || ($publicInquiry['body']['data']['locationId'] ?? null) !== $publicLocationId
    || ($publicInquiry['body']['data']['configurationSnapshot']['locationId'] ?? null) !== $publicLocationId
    || ($publicInquiry['body']['data']['billingAddress'] ?? null) !== $leadBillingAddress) {
    throw new RuntimeException('Die technische Lead-Konfiguration wurde nicht vollständig gespeichert.');
}
assertStatus(request('GET', '/api/v1/inquiries'), [401], 'Anfragenliste ohne Login schützen');

$unauthenticated = request('GET', '/api/v1/devices');
assertStatus($unauthenticated, [401], 'Geschützter Endpunkt ohne Token');

$login = request('POST', '/api/v1/auth/login', [
    'email' => 'demo@colomanager.local',
    'password' => 'Demo123!',
]);
assertStatus($login, [200], 'Login');
$token = $login['body']['data']['accessToken'] ?? null;
if (!is_string($token) || $token === '') {
    throw new RuntimeException('Login-Antwort enthält kein Zugriffstoken.');
}

$me = request('GET', '/api/v1/auth/me', token: $token);
assertStatus($me, [200], 'Aktueller Benutzer');

$currentCustomer = request('GET', '/api/v1/customers/current', token: $token);
assertStatus($currentCustomer, [200], 'Eigener Kunde');
$currentCustomerId = $currentCustomer['body']['data']['id'] ?? null;
if (!is_string($currentCustomerId)) {
    throw new RuntimeException('Der Demo-Kunde enthält keine ID.');
}
if (!is_string($currentCustomer['body']['data']['subscription']['plan']['name'] ?? null)) {
    throw new RuntimeException('Der Demo-Kunde enthält keinen aufgelösten Tarif.');
}

$allCustomers = request('GET', '/api/v1/customers', token: $token);
assertStatus($allCustomers, [403], 'Mandant darf keine Kundenliste sehen');

$locations = request('GET', '/api/v1/locations', token: $token);
assertStatus($locations, [200], 'Standortliste');
$locationId = $locations['body']['data']['items'][0]['id'] ?? null;
if (!is_string($locationId)) {
    throw new RuntimeException('Seed-Standort wurde nicht gefunden.');
}
assertStatus(request('GET', '/api/v1/locations/' . $locationId, token: $token), [200], 'Standortdetail');

$devices = request('GET', '/api/v1/devices', token: $token);
assertStatus($devices, [200], 'Geräteliste');
$deviceId = $devices['body']['data']['items'][0]['id'] ?? null;
if (!is_string($deviceId)) {
    throw new RuntimeException('Seed-Gerät wurde nicht gefunden.');
}
assertStatus(request('GET', '/api/v1/devices/' . $deviceId, token: $token), [200], 'Gerätedetail');

$racks = request('GET', '/api/v1/racks', token: $token);
assertStatus($racks, [200], 'Eigene Rack-Liste');
$demoRackId = $racks['body']['data']['items'][0]['id'] ?? null;
if (!is_string($demoRackId)) {
    throw new RuntimeException('Seed-Rack wurde nicht gefunden.');
}

$plans = request('GET', '/api/v1/plans', token: $token);
assertStatus($plans, [200], 'Aktive Tarifliste');
$bandwidthOptions = request('GET', '/api/v1/bandwidth-options', token: $token);
assertStatus($bandwidthOptions, [200], 'Aktive Bandbreitenliste');

// Kunden-Tickets werden inklusive Rich Text und echtem Bildanhang über die
// öffentliche HTTP-Schnittstelle angelegt und anschließend mandantensicher gelesen.
assertStatus(request('GET', '/api/v1/tickets'), [401], 'Ticketliste ohne Login schützen');
assertStatus(request('POST', '/api/v1/tickets', [
    'subject' => 'Ticket ohne Kategorie',
    'bodyHtml' => '<p>Kategorie muss verpflichtend sein.</p>',
], $token), [422], 'Kundenticket ohne Kategorie ablehnen');
assertStatus(request('POST', '/api/v1/tickets', [
    'category' => 'incident',
    'subject' => 'Interne Kategorie als Kunde',
    'bodyHtml' => '<p>Die Störungskategorie bleibt Mitarbeitern vorbehalten.</p>',
], $token), [422], 'Interne Störungskategorie als Kunde ablehnen');
assertStatus(request('POST', '/api/v1/tickets', [
    'category' => 'lead',
    'subject' => 'Lead-Kategorie als Kunde',
    'bodyHtml' => '<p>Die Kategorie ist öffentlichen Erstanfragen vorbehalten.</p>',
], $token), [422], 'Exklusive Lead-Kategorie als Kunde ablehnen');
assertStatus(request('POST', '/api/v1/tickets', [
    'category' => 'remote_hands',
    'subject' => 'Remote Hands ohne Infrastrukturbezug',
    'bodyHtml' => '<p>Ein Rack oder eine Komponente muss ausgewählt werden.</p>',
], $token), [422], 'Remote-Hands-Ticket ohne Ziel ablehnen');
$tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z0mQAAAAASUVORK5CYII=', true);
if (!is_string($tinyPng)) {
    throw new RuntimeException('Testbild konnte nicht erzeugt werden.');
}
$tinyPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
$customerTicket = multipartRequest('/api/v1/tickets', [
    'category' => 'remote_hands',
    'remoteHandsScope' => 'device',
    'remoteHandsRackId' => $demoRackId,
    'remoteHandsDeviceId' => $deviceId,
    'subject' => 'Automatisches Kundenticket',
    'bodyHtml' => '<p>Der Router zeigt <strong>keinen Link</strong>.</p><script>alert(1)</script>',
], [[
    'field' => 'attachments',
    'name' => 'router-status.png',
    'type' => 'image/png',
    'content' => $tinyPng,
]], $token);
assertStatus($customerTicket, [201], 'Kundenticket mit Bild anlegen');
$customerTicketId = $customerTicket['body']['data']['id'] ?? null;
$customerAttachment = $customerTicket['body']['data']['messages'][0]['attachments'][0] ?? [];
if (!is_string($customerTicketId)
    || ($customerTicket['body']['data']['type'] ?? null) !== 'normal'
    || ($customerTicket['body']['data']['category'] ?? null) !== 'remote_hands'
    || ($customerTicket['body']['data']['remoteHandsTarget']['scope'] ?? null) !== 'device'
    || ($customerTicket['body']['data']['remoteHandsTarget']['rackId'] ?? null) !== $demoRackId
    || ($customerTicket['body']['data']['remoteHandsTarget']['deviceId'] ?? null) !== $deviceId
    || ($customerTicket['body']['data']['remoteHandsTarget']['assetTag'] ?? null) !== 'SRV-0001') {
    throw new RuntimeException('Das normale Kundenticket wurde nicht vollständig angelegt.');
}
if (str_contains((string) ($customerTicket['body']['data']['messages'][0]['bodyHtml'] ?? ''), '<script')) {
    throw new RuntimeException('Unsicheres HTML wurde in einer Ticketnachricht gespeichert.');
}
assertStatus(request('GET', '/api/v1/tickets/' . $customerTicketId, token: $token), [200], 'Eigenes Ticket mit Verlauf laden');
assertStatus(request('POST', '/api/v1/tickets/' . $customerTicketId . '/messages', ['bodyHtml' => '<p>Zusätzliche Diagnose ist verfügbar.</p>'], $token), [201], 'Auf eigenes Ticket antworten');
$customerPdfMessage = multipartRequest('/api/v1/tickets/' . $customerTicketId . '/messages', [
    'bodyHtml' => '<p>Ergänzende PDF-Dokumentation.</p>',
], [[
    'field' => 'attachments',
    'name' => 'dokumentation.pdf',
    'type' => 'application/pdf',
    'content' => $tinyPdf,
]], $token);
assertStatus($customerPdfMessage, [201], 'PDF global an Kundenticket anhängen');
$customerPdfAttachment = $customerPdfMessage['body']['data']['attachments'][0] ?? [];
if (($customerPdfAttachment['mimeType'] ?? null) !== 'application/pdf'
    || rawRequest((string) ($customerPdfAttachment['downloadUrl'] ?? ''), $token)['body'] !== $tinyPdf) {
    throw new RuntimeException('Der globale PDF-Ticketanhang wurde nicht korrekt gespeichert oder ausgeliefert.');
}
fwrite(STDOUT, "[OK] Geschützten PDF-Ticketanhang laden (200)\n");
$attachmentResponse = rawRequest((string) ($customerAttachment['downloadUrl'] ?? ''), $token);
if ($attachmentResponse['status'] !== 200 || $attachmentResponse['body'] !== $tinyPng) {
    throw new RuntimeException('Der geschützte GridFS-Bildanhang konnte nicht korrekt geladen werden.');
}
fwrite(STDOUT, "[OK] Geschützten Ticketanhang laden (200)\n");

// Schreibzugriffe auf den zentralen Katalog bleiben Kundenrollen verwehrt.
assertStatus(request('POST', '/api/v1/plans', ['code' => 'FORBIDDEN'], $token), [403], 'Tarifänderung als Kunde gesperrt');

$adminLogin = request('POST', '/api/v1/auth/login', [
    'email' => 'admin@colomanager.local',
    'password' => 'ChangeMe123!',
]);
assertStatus($adminLogin, [200], 'Admin-Login');
$adminToken = $adminLogin['body']['data']['accessToken'] ?? null;
if (!is_string($adminToken) || $adminToken === '') {
    throw new RuntimeException('Admin-Login enthält kein Zugriffstoken.');
}
assertStatus(request('POST', '/api/v1/branding', [
    'companyName' => $brandName,
    'primaryColor' => $brandPrimaryColor,
    'content' => '{"landing":',
], $adminToken), [422], 'Ungültige Branding-Inhalte ablehnen');
assertStatus(request('GET', '/api/v1/customers?limit=100', token: $adminToken), [200], 'Admin-Kundenliste');
assertStatus(request('GET', '/api/v1/racks?limit=100', token: $adminToken), [200], 'Admin-Rackliste');
assertStatus(request('GET', '/api/v1/plans?limit=100', token: $adminToken), [200], 'Admin-Tarifliste');
assertStatus(request('GET', '/api/v1/bandwidth-options?limit=100', token: $adminToken), [200], 'Admin-Bandbreitenliste');
assertStatus(request('GET', '/api/v1/inquiries?limit=100', token: $adminToken), [200], 'Admin-Anfragenliste');
assertStatus(request('GET', '/api/v1/tickets?limit=100', token: $adminToken), [200], 'Admin-Ticketqueue einschließlich Leads');

// Techniker und Vertriebler arbeiten in derselben Ticketqueue. Ausschließlich
// der Vertrieb erhält zusätzlich Vertrags- und Kundenstammdatenzugriff.
$staffLogin = request('POST', '/api/v1/auth/login', [
    'email' => 'technik@colomanager.local',
    'password' => 'Staff123!',
]);
assertStatus($staffLogin, [200], 'Mitarbeiter-Login');
$staffToken = $staffLogin['body']['data']['accessToken'] ?? null;
$staffId = $staffLogin['body']['data']['user']['id'] ?? null;
if (!is_string($staffToken) || !is_string($staffId)) {
    throw new RuntimeException('Mitarbeiter-Login enthält keine vollständigen Sitzungsdaten.');
}
$salesLogin = request('POST', '/api/v1/auth/login', [
    'email' => 'vertrieb@colomanager.local',
    'password' => 'Staff123!',
]);
assertStatus($salesLogin, [200], 'Vertriebs-Login');
$salesToken = $salesLogin['body']['data']['accessToken'] ?? null;
$salesId = $salesLogin['body']['data']['user']['id'] ?? null;
if (!is_string($salesToken) || $salesToken === '' || !is_string($salesId)) {
    throw new RuntimeException('Vertriebs-Login enthält kein Zugriffstoken.');
}
assertStatus(request('GET', '/api/v1/contracts?limit=100', token: $salesToken), [200], 'Vertrieb erhält Vertragszugriff');
assertStatus(request('GET', '/api/v1/customers?limit=100', token: $salesToken), [200], 'Vertrieb erhält Kundenverwaltung');
assertStatus(request('GET', '/api/v1/locations?limit=100', token: $salesToken), [200], 'Vertrieb lädt Standorte für Kundenzuweisungen');
assertStatus(request('GET', '/api/v1/customers?limit=100', token: $staffToken), [403], 'Mitarbeiter von Kundenverwaltung ausschließen');
assertStatus(request('GET', '/api/v1/locations?limit=100', token: $staffToken), [403], 'Techniker von standortübergreifenden Kundendaten ausschließen');

// ISP- und IP-Daten werden ausschließlich durch Technik/Admin gepflegt. Die
// Kundensicht ist fest auf den Mandanten der Sitzung begrenzt und nur lesbar.
assertStatus(request('GET', '/api/v1/network-assignments/options?customerId=' . $currentCustomerId, token: $staffToken), [200], 'Techniker lädt Kunden- und Standortoptionen für Netzwerkdaten');
assertStatus(request('GET', '/api/v1/network-assignments/options?customerId=' . $currentCustomerId, token: $salesToken), [403], 'Vertrieb von Netzwerkverwaltung ausschließen');
assertStatus(request('POST', '/api/v1/network-assignments', [
    'customerId' => $currentCustomerId,
    'locationId' => $locationId,
    'label' => 'Ungültiger IPv4-Test',
    'ispName' => 'Smoke ISP',
    'addressFamily' => 'ipv4',
    'cidr' => '2001:db8::10/64',
    'usage' => 'wan',
], $staffToken), [422], 'Adressfamilie einer IP-Zuweisung validieren');
$networkAddress = '198.51.100.' . random_int(10, 220) . '/32';
$networkCreate = request('POST', '/api/v1/network-assignments', [
    'customerId' => $currentCustomerId,
    'locationId' => $locationId,
    'label' => 'Smoke WAN-Uplink',
    'ispName' => 'Smoke ISP',
    'serviceReference' => 'SMOKE-CIRCUIT',
    'addressFamily' => 'ipv4',
    'cidr' => $networkAddress,
    'gateway' => '198.51.100.1',
    'dnsServers' => ['1.1.1.1', '9.9.9.9'],
    'vlanId' => 120,
    'usage' => 'wan',
    'status' => 'active',
    'notes' => 'Automatischer Netzwerk-Smoke-Test',
], $staffToken);
assertStatus($networkCreate, [201], 'Techniker legt kundengebundene IP-Zuweisung an');
$networkAssignmentId = $networkCreate['body']['data']['id'] ?? null;
if (!is_string($networkAssignmentId)
    || ($networkCreate['body']['data']['customerId'] ?? null) !== $currentCustomerId
    || ($networkCreate['body']['data']['locationId'] ?? null) !== $locationId
) {
    throw new RuntimeException('Die IP-Zuweisung wurde nicht korrekt mit Kunde und Standort verknüpft.');
}
$networkSearch = request('GET', '/api/v1/network-assignments/search?query=SMOKE-CIRCUIT', token: $staffToken);
assertStatus($networkSearch, [200], 'Techniker findet IP-Zuweisungen über die globale Suche');
if (!in_array($networkAssignmentId, array_column($networkSearch['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Die globale Netzwerksuche liefert die Provider-Referenz nicht zurück.');
}
assertStatus(request('GET', '/api/v1/network-assignments/search?query=SMOKE-CIRCUIT', token: $salesToken), [403], 'Vertrieb von der globalen Netzwerksuche ausschließen');
$customerNetwork = request('GET', '/api/v1/customer/network-assignments?limit=100', token: $token);
assertStatus($customerNetwork, [200], 'Kunde lädt ausschließlich eigene Netzwerkdaten');
if (!in_array($networkAssignmentId, array_column($customerNetwork['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Die Technik-Zuweisung fehlt in der Kundensicht.');
}
assertStatus(request('PATCH', '/api/v1/network-assignments/' . $networkAssignmentId, ['notes' => 'Kunde darf nicht ändern'], $token), [403], 'Kundenänderung an Netzwerkdaten sperren');
$networkUpdate = request('PATCH', '/api/v1/network-assignments/' . $networkAssignmentId, ['vlanId' => 121, 'reverseDns' => 'smoke.example.invalid'], $staffToken);
assertStatus($networkUpdate, [200], 'Techniker aktualisiert IP-Zuweisung');
if (($networkUpdate['body']['data']['vlanId'] ?? null) !== 121) {
    throw new RuntimeException('Die aktualisierte VLAN-ID fehlt in der API-Antwort.');
}
assertStatus(request('DELETE', '/api/v1/network-assignments/' . $networkAssignmentId, token: $staffToken), [204], 'Techniker löscht IP-Zuweisung');
$customerNetworkAfterDelete = request('GET', '/api/v1/customer/network-assignments?limit=100', token: $token);
assertStatus($customerNetworkAfterDelete, [200], 'Kundensicht nach Netzwerk-Löschung laden');
if (in_array($networkAssignmentId, array_column($customerNetworkAfterDelete['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Eine gelöschte IP-Zuweisung bleibt in der Kundensicht sichtbar.');
}

// Die Rackbelegung ist ein eigener, API-first abgesicherter Workflow. Kunden
// pflegen ihre HE selbst; Techniker dürfen erst nach expliziter Freigabe ändern.
$layoutSuffix = strtoupper(bin2hex(random_bytes(3)));
$layoutRackResponse = request('POST', '/api/v1/racks', [
    'customerId' => $currentCustomerId,
    'locationId' => $locationId,
    'code' => 'LAYOUT-' . $layoutSuffix,
    'name' => 'Smoke Half Rack',
    'totalUnits' => 22,
    'usedUnits' => 0,
    'powerLimitKw' => 5.6,
], $adminToken);
assertStatus($layoutRackResponse, [201], 'Halbes Rack für Belegungsprüfung anlegen');
$layoutRackId = (string) ($layoutRackResponse['body']['data']['id'] ?? '');
assertStatus(request('GET', '/api/v1/racks?limit=100', token: $staffToken), [200], 'Techniker lädt Rackübersicht');
assertStatus(request('GET', '/api/v1/racks?limit=100', token: $salesToken), [403], 'Vertrieb von Rackbelegung ausschließen');
$customerRackLayout = request('GET', '/api/v1/racks/' . $layoutRackId . '/layout', token: $token);
assertStatus($customerRackLayout, [200], 'Kunde lädt visuelle Rackbelegung');
if (($customerRackLayout['body']['data']['layoutSupported'] ?? null) !== true
    || ($customerRackLayout['body']['data']['permissions']['canEditLayout'] ?? null) !== true
    || ($customerRackLayout['body']['data']['permissions']['remoteHandsAccessEnabled'] ?? null) !== false) {
    throw new RuntimeException('Die initialen Racklayout-Berechtigungen sind nicht korrekt.');
}
$layoutDeviceResponse = request('POST', '/api/v1/racks/' . $layoutRackId . '/layout/devices', [
    'name' => 'Visualisierter Server',
    'type' => 'server',
    'status' => 'online',
    'rackUnit' => 5,
    'heightUnits' => 2,
    'manufacturer' => 'Smoke Systems',
], $token);
assertStatus($layoutDeviceResponse, [201], 'Kunde bestückt Rack-Höheneinheiten');
$layoutDeviceId = (string) ($layoutDeviceResponse['body']['data']['id'] ?? '');
assertStatus(request('POST', '/api/v1/racks/' . $layoutRackId . '/layout/devices', [
    'name' => 'Kollidierender Switch',
    'type' => 'switch',
    'rackUnit' => 6,
    'heightUnits' => 1,
], $token), [409], 'Doppelte HE-Belegung verhindern');
assertStatus(request('PATCH', '/api/v1/racks/' . $layoutRackId . '/layout/devices/' . $layoutDeviceId, [
    'rackUnit' => 8,
], $staffToken), [403], 'Technikeränderung ohne Rackfreigabe sperren');
$sharedLayout = request('PATCH', '/api/v1/racks/' . $layoutRackId . '/remote-hands-access', ['enabled' => true], $token);
assertStatus($sharedLayout, [200], 'Kunde gibt Rack für Remote Hands frei');
if (($sharedLayout['body']['data']['permissions']['remoteHandsAccessEnabled'] ?? null) !== true) {
    throw new RuntimeException('Die Remote-Hands-Freigabe wurde nicht gespeichert.');
}
assertStatus(request('PATCH', '/api/v1/racks/' . $layoutRackId . '/layout/devices/' . $layoutDeviceId, [
    'rackUnit' => 8,
    'type' => 'firewall',
], $staffToken), [200], 'Freigegebener Techniker ändert Rackbelegung');
$technicianLayout = request('GET', '/api/v1/racks/' . $layoutRackId . '/layout', token: $staffToken);
assertStatus($technicianLayout, [200], 'Techniker sieht belegte Höheneinheiten');
if (($technicianLayout['body']['data']['devices'][0]['rackUnit'] ?? null) !== 8
    || ($technicianLayout['body']['data']['permissions']['canEditLayout'] ?? null) !== true) {
    throw new RuntimeException('Die Technikersicht enthält die freigegebene Rackbelegung nicht korrekt.');
}
assertStatus(request('PATCH', '/api/v1/racks/' . $layoutRackId . '/remote-hands-access', ['enabled' => false], $token), [200], 'Kunde entzieht Remote-Hands-Freigabe');
assertStatus(request('PATCH', '/api/v1/racks/' . $layoutRackId . '/layout/devices/' . $layoutDeviceId, [
    'rackUnit' => 10,
], $staffToken), [403], 'Technikeränderung nach Freigabeentzug sperren');
assertStatus(request('DELETE', '/api/v1/racks/' . $layoutRackId . '/layout/devices/' . $layoutDeviceId, token: $token), [204], 'Kunde entfernt Rackgerät');
assertStatus(request('DELETE', '/api/v1/racks/' . $layoutRackId, token: $adminToken), [204], 'Temporäres Layout-Rack löschen');

$ticketCustomerOptions = request('GET', '/api/v1/tickets/customer-options', token: $staffToken);
assertStatus($ticketCustomerOptions, [200], 'Mitarbeiter lädt sichere Kundenauswahl für Tickets');
$ticketCustomerIds = array_column($ticketCustomerOptions['body']['data']['items'] ?? [], 'id');
if (!in_array($currentCustomerId, $ticketCustomerIds, true)) {
    throw new RuntimeException('Der Demo-Kunde fehlt in der Kundenauswahl für interne Tickets.');
}
assertStatus(request('GET', '/api/v1/tickets/customer-options', token: $token), [403], 'Kundenauswahl vor Kundenrolle schützen');
assertStatus(request('POST', '/api/v1/tickets/internal', [
    'category' => 'incident',
    'customerId' => $currentCustomerId,
    'assignedToUserId' => $staffId,
    'subject' => 'Interne Störungsprüfung',
    'bodyHtml' => '<p>Nur das Datacenter-Team darf diesen Vorgang sehen.</p>',
], $token), [403], 'Internes Ticket als Kunde sperren');
assertStatus(request('POST', '/api/v1/tickets/internal', [
    'category' => 'lead',
    'subject' => 'Interner Lead',
    'bodyHtml' => '<p>Lead-Kategorie darf nicht manuell vergeben werden.</p>',
], $staffToken), [422], 'Exklusive Lead-Kategorie für interne Tickets sperren');
$internalTicket = request('POST', '/api/v1/tickets/internal', [
    'category' => 'incident',
    'customerId' => $currentCustomerId,
    'assignedToUserId' => $staffId,
    'subject' => 'Interne Störungsprüfung',
    'bodyHtml' => '<p>Nur das Datacenter-Team darf diesen Vorgang sehen.</p>',
], $staffToken);
assertStatus($internalTicket, [201], 'Mitarbeiter legt internes Kundenticket an');
$internalTicketId = $internalTicket['body']['data']['id'] ?? null;
if (!is_string($internalTicketId)
    || ($internalTicket['body']['data']['type'] ?? null) !== 'internal'
    || ($internalTicket['body']['data']['visibility'] ?? null) !== 'internal'
    || ($internalTicket['body']['data']['category'] ?? null) !== 'incident'
    || ($internalTicket['body']['data']['messages'][0]['internal'] ?? null) !== true) {
    throw new RuntimeException('Das interne Mitarbeiterticket wurde nicht sicher gekennzeichnet.');
}
$customerQueueAfterInternalTicket = request('GET', '/api/v1/tickets?limit=100', token: $token);
assertStatus($customerQueueAfterInternalTicket, [200], 'Kundenqueue nach internem Ticket laden');
if (in_array($internalTicketId, array_column($customerQueueAfterInternalTicket['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Ein internes Ticket wurde in der Kundenübersicht sichtbar.');
}
assertStatus(request('GET', '/api/v1/tickets/' . $internalTicketId, token: $token), [404], 'Internes Ticket vor direktem Kundenzugriff schützen');
$staffQueue = request('GET', '/api/v1/tickets?limit=100', token: $staffToken);
assertStatus($staffQueue, [200], 'Mitarbeiter lädt eigene und nicht zugewiesene Ticketqueue');
$staffVisibleTicketIds = array_column($staffQueue['body']['data']['items'] ?? [], 'id');
if (!in_array($customerTicketId, $staffVisibleTicketIds, true) || !in_array($publicLeadTicketId, $staffVisibleTicketIds, true) || !in_array($internalTicketId, $staffVisibleTicketIds, true)) {
    throw new RuntimeException('Die Mitarbeiterqueue enthält nicht die eigenen und noch nicht zugewiesenen Tickets.');
}
$salesScopedQueue = request('GET', '/api/v1/tickets?limit=100', token: $salesToken);
assertStatus($salesScopedQueue, [200], 'Vertrieb lädt eigene und nicht zugewiesene Ticketqueue');
if (in_array($internalTicketId, array_column($salesScopedQueue['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Ein fremd zugewiesenes Ticket wurde in der Vertriebsqueue sichtbar.');
}
assertStatus(request('GET', '/api/v1/tickets/' . $internalTicketId, token: $salesToken), [404], 'Fremd zugewiesenes Ticket vor Mitarbeiter schützen');
$incidentQueue = request('GET', '/api/v1/tickets?limit=100&type=internal&category=incident', token: $staffToken);
assertStatus($incidentQueue, [200], 'Interne Störungstickets filtern');
if (!in_array($internalTicketId, array_column($incidentQueue['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Das interne Störungsticket fehlt im Filter.');
}
assertStatus(request('POST', '/api/v1/tickets/' . $internalTicketId . '/messages', [
    'bodyHtml' => '<p>Interner Bearbeitungsstand ohne Kundeninformation.</p>',
], $staffToken), [201], 'Interne Ticketnotiz hinzufügen');
$internalTicketDetail = request('GET', '/api/v1/tickets/' . $internalTicketId, token: $staffToken);
assertStatus($internalTicketDetail, [200], 'Internes Ticket als Mitarbeiter laden');
$internalMessages = $internalTicketDetail['body']['data']['messages'] ?? [];
$lastInternalMessage = end($internalMessages);
if (($lastInternalMessage['internal'] ?? null) !== true || ($internalTicketDetail['body']['data']['status'] ?? null) !== 'in_progress') {
    throw new RuntimeException('Interne Notiz oder interner Folgestatus wurde nicht korrekt gespeichert.');
}
$remoteHandsQueue = request('GET', '/api/v1/tickets?limit=100&category=remote_hands', token: $staffToken);
assertStatus($remoteHandsQueue, [200], 'Ticketqueue nach Remote Hands filtern');
if (!in_array($customerTicketId, array_column($remoteHandsQueue['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Das Remote-Hands-Ticket fehlt im Kategoriefilter.');
}
$leadQueue = request('GET', '/api/v1/tickets?limit=100&category=lead', token: $staffToken);
assertStatus($leadQueue, [200], 'Ticketqueue nach Lead-Anfrage filtern');
$leadTicketsById = array_column($leadQueue['body']['data']['items'] ?? [], null, 'id');
if (($leadTicketsById[$publicLeadTicketId]['category'] ?? null) !== 'lead') {
    throw new RuntimeException('Das Lead-Ticket wurde nicht automatisch der exklusiven Lead-Kategorie zugeordnet.');
}
$salesQueue = request('GET', '/api/v1/tickets?limit=100&category=sales', token: $staffToken);
assertStatus($salesQueue, [200], 'Ticketqueue nach Vertrieb und Upgrade filtern');
if (in_array($publicLeadTicketId, array_column($salesQueue['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Ein Lead-Ticket ist fälschlich in der normalen Vertriebskategorie sichtbar.');
}
assertStatus(request('PATCH', '/api/v1/tickets/' . $customerTicketId, [
    'category' => 'lead',
], $staffToken), [422], 'Lead-Kategorie für ein normales Ticket sperren');
assertStatus(request('PATCH', '/api/v1/tickets/' . $publicLeadTicketId, [
    'category' => 'sales',
], $staffToken), [422], 'Exklusive Kategorie eines Lead-Tickets schützen');
assertStatus(request('GET', '/api/v1/tickets?category=ungueltig', token: $staffToken), [422], 'Ungültigen Ticketkategoriefilter ablehnen');
$assignees = request('GET', '/api/v1/tickets/assignees', token: $staffToken);
assertStatus($assignees, [200], 'Mitarbeiter lädt mögliche Ticketbearbeiter');
if (!in_array($staffId, array_column($assignees['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Der Techniker fehlt in der Bearbeiterauswahl.');
}
assertStatus(request('PATCH', '/api/v1/tickets/' . $customerTicketId, [
    'category' => 'remote_hands',
    'status' => 'in_progress',
    'assignedToUserId' => $staffId,
], $staffToken), [200], 'Mitarbeiter übernimmt Kundenticket');
$customerBeforeInternalNote = request('GET', '/api/v1/tickets/' . $customerTicketId, token: $token);
assertStatus($customerBeforeInternalNote, [200], 'Kundensicht vor interner Notiz laden');
$visibleMessagesBeforeInternalNote = (int) ($customerBeforeInternalNote['body']['data']['messageCount'] ?? -1);
$visibleAttachmentsBeforeInternalNote = (int) ($customerBeforeInternalNote['body']['data']['attachmentCount'] ?? -1);
$customerStatusBeforeInternalNote = $customerBeforeInternalNote['body']['data']['status'] ?? null;
$customerUpdatedAtBeforeInternalNote = $customerBeforeInternalNote['body']['data']['updatedAt'] ?? null;
$customerLastMessageAtBeforeInternalNote = $customerBeforeInternalNote['body']['data']['lastMessageAt'] ?? null;
$mailCountBeforeInternalNote = mailpitMessageCount();

$staffInternalNote = multipartRequest('/api/v1/tickets/' . $customerTicketId . '/messages', [
    'bodyHtml' => '<p>Interne Diagnose der Technik – nicht an den Kunden senden.</p>',
    'sendToCustomer' => '0',
], [[
    'field' => 'attachments',
    'name' => 'interne-diagnose.png',
    'type' => 'image/png',
    'content' => $tinyPng,
]], $staffToken);
assertStatus($staffInternalNote, [201], 'Mitarbeiterantwort bleibt standardmäßig intern');
$internalNoteAttachment = $staffInternalNote['body']['data']['attachments'][0] ?? [];
if (($staffInternalNote['body']['data']['internal'] ?? null) !== true) {
    throw new RuntimeException('Die Mitarbeiterantwort wurde ohne Freigabe nach außen sichtbar gespeichert.');
}
$customerAfterInternalNote = request('GET', '/api/v1/tickets/' . $customerTicketId, token: $token);
assertStatus($customerAfterInternalNote, [200], 'Kundensicht nach interner Notiz laden');
$customerMessageBodies = implode(' ', array_column($customerAfterInternalNote['body']['data']['messages'] ?? [], 'bodyText'));
if (str_contains($customerMessageBodies, 'Interne Diagnose')
    || (int) ($customerAfterInternalNote['body']['data']['messageCount'] ?? -1) !== $visibleMessagesBeforeInternalNote
    || (int) ($customerAfterInternalNote['body']['data']['attachmentCount'] ?? -1) !== $visibleAttachmentsBeforeInternalNote
    || ($customerAfterInternalNote['body']['data']['status'] ?? null) !== $customerStatusBeforeInternalNote
    || ($customerAfterInternalNote['body']['data']['updatedAt'] ?? null) !== $customerUpdatedAtBeforeInternalNote
    || ($customerAfterInternalNote['body']['data']['lastMessageAt'] ?? null) !== $customerLastMessageAtBeforeInternalNote) {
    throw new RuntimeException('Eine interne Mitarbeiterantwort hinterlässt sichtbare Inhalte, Zähler, Status- oder Zeitinformationen in der Kundensicht.');
}
if (mailpitMessageCount() !== $mailCountBeforeInternalNote) {
    throw new RuntimeException('Eine interne Mitarbeiterantwort hat eine Kundenmail ausgelöst.');
}
fwrite(STDOUT, "[OK] Interne Notiz löst keine Kundenmail aus\n");
$internalAttachmentUrl = (string) ($internalNoteAttachment['downloadUrl'] ?? '');
if ($internalAttachmentUrl === '') {
    throw new RuntimeException('Der interne Testanhang enthält keine Downloadadresse.');
}
if (rawRequest($internalAttachmentUrl, $token)['status'] !== 404) {
    throw new RuntimeException('Ein interner Ticketanhang konnte vom Kunden geladen werden.');
}
fwrite(STDOUT, "[OK] Internen Ticketanhang vor Kunde schützen (404)\n");
if (rawRequest($internalAttachmentUrl, $staffToken)['status'] !== 200) {
    throw new RuntimeException('Der interne Ticketanhang konnte vom Mitarbeiter nicht geladen werden.');
}
fwrite(STDOUT, "[OK] Internen Ticketanhang als Mitarbeiter laden (200)\n");

assertStatus(request('POST', '/api/v1/tickets/' . $customerTicketId . '/messages', [
    'bodyHtml' => '<p>Freigegebene Antwort der Technik.</p>',
    'sendToCustomer' => true,
], $staffToken), [201], 'Mitarbeiter gibt Antwort ausdrücklich für Kunden frei');
$staffManagedTicket = request('GET', '/api/v1/tickets/' . $customerTicketId, token: $staffToken);
assertStatus($staffManagedTicket, [200], 'Mitarbeiter lädt bearbeitetes Ticket');
$staffMessages = $staffManagedTicket['body']['data']['messages'] ?? [];
$lastStaffMessage = end($staffMessages);
if (($staffManagedTicket['body']['data']['assignedToUserId'] ?? null) !== $staffId
    || ($staffManagedTicket['body']['data']['status'] ?? null) !== 'waiting_customer'
    || ($lastStaffMessage['author']['type'] ?? null) !== 'staff'
    || ($lastStaffMessage['internal'] ?? null) !== false) {
    throw new RuntimeException('Zuweisung, Folgestatus oder Mitarbeiterautor wurde nicht korrekt gespeichert.');
}
$customerAfterReleasedReply = request('GET', '/api/v1/tickets/' . $customerTicketId, token: $token);
assertStatus($customerAfterReleasedReply, [200], 'Freigegebene Mitarbeiterantwort im Kundenportal laden');
$releasedCustomerBodies = implode(' ', array_column($customerAfterReleasedReply['body']['data']['messages'] ?? [], 'bodyText'));
if (!str_contains($releasedCustomerBodies, 'Freigegebene Antwort der Technik')) {
    throw new RuntimeException('Die ausdrücklich freigegebene Mitarbeiterantwort fehlt im Kundenportal.');
}

assertStatus(request('PATCH', '/api/v1/inquiries/' . $publicInquiryId, ['status' => 'contacted'], $adminToken), [200], 'Anfragestatus bearbeiten');
$syncedLeadTicket = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $adminToken);
assertStatus($syncedLeadTicket, [200], 'Lead-Ticket laden');
if (($syncedLeadTicket['body']['data']['status'] ?? null) !== 'in_progress') {
    throw new RuntimeException('Der Vertriebsstatus wurde nicht mit dem Lead-Ticket synchronisiert.');
}
if (($syncedLeadTicket['body']['data']['requester']['billingAddress'] ?? null) !== $leadBillingAddress) {
    throw new RuntimeException('Die Unternehmensanschrift fehlt im Lead-Ticket des Vertriebs.');
}
$leadTicketNumber = (string) ($syncedLeadTicket['body']['data']['number'] ?? '');
assertStatus(request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/messages', [
    'bodyHtml' => '<p>Diese Nachricht darf nicht extern versendet werden.</p>',
    'sendToCustomer' => true,
], $staffToken), [422], 'Normale externe Antwort bei Lead-Ticket sperren');
$offerBeforeContact = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer', token: $staffToken);
assertStatus($offerBeforeContact, [409], 'Angebotsversand vor dokumentiertem Erstkontakt sperren');
$contactedLead = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/contact', token: $staffToken);
assertStatus($contactedLead, [200], 'Lead-Kontaktaufnahme dokumentieren');
if (($contactedLead['body']['data']['leadProcess']['contact']['status'] ?? null) !== 'completed') {
    throw new RuntimeException('Der erste Lead-Checklistenschritt wurde nicht abgeschlossen.');
}
$defaultOfferDraft = request('GET', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer-draft', token: $staffToken);
assertStatus($defaultOfferDraft, [200], 'Vorbefüllten Angebots- und Vertragsentwurf laden');
$offerDraftPayload = $defaultOfferDraft['body']['data'] ?? [];
if (count($offerDraftPayload['lineItems'] ?? []) < 2
    || (float) ($offerDraftPayload['totals']['monthly'] ?? -1) < 0
    || empty($offerDraftPayload['validUntil'])) {
    throw new RuntimeException('Der Angebotsentwurf wurde nicht aus der Lead-Konfiguration vorbefüllt.');
}
$offerDraftPayload['notes'] = 'Automatisiert geprüfter strukturierter Angebotsentwurf.';
$savedOfferDraft = request('PUT', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer-draft', $offerDraftPayload, $staffToken);
assertStatus($savedOfferDraft, [200], 'Strukturierten Angebotsentwurf speichern');
$draftPreview = rawRequest('/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer-draft/document', $staffToken);
if ($draftPreview['status'] !== 200 || !str_starts_with($draftPreview['body'], '%PDF-1.4')) {
    throw new RuntimeException('Die PDF-Vorschau des strukturierten Angebots ist ungültig.');
}
if (!str_contains($draftPreview['body'], $encodedBrandName)
    || !str_contains($draftPreview['body'], $encodedLeadStreet)
    || !str_contains($draftPreview['body'], $leadBillingAddress['city'])
    || !str_contains($draftPreview['body'], 'EINMALIG NETTO')
    || !str_contains($draftPreview['body'], 'MONATLICH NETTO')
    || ($brandHasLogo && !str_contains($draftPreview['body'], '/Subtype /Image'))) {
    throw new RuntimeException('Die Angebotsvorschau enthält Branding, Anschrift oder Nettopreis-Kennzeichnung nicht vollständig.');
}
fwrite(STDOUT, "[OK] Generierte Angebotsvorschau laden (200)\n");
$sentLeadOffer = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer', token: $staffToken);
assertStatus($sentLeadOffer, [201], 'Strukturiertes Lead-Angebot generieren und versenden');
if (($sentLeadOffer['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'sent'
    || ($sentLeadOffer['body']['data']['leadProcess']['offer']['emailStatus'] ?? null) !== 'sent'
    || empty($sentLeadOffer['body']['data']['leadProcess']['offer']['offerNumber'])
    || (float) ($sentLeadOffer['body']['data']['leadProcess']['offer']['draft']['totals']['monthly'] ?? -1) < 0
    || ($sentLeadOffer['body']['data']['leadProcess']['offer']['counterparty']['billingAddress'] ?? null) !== $leadBillingAddress
    || isset($sentLeadOffer['body']['data']['leadProcess']['offer']['tokenHash'])) {
    throw new RuntimeException('Das Lead-Angebot wurde nicht sicher oder nicht vollständig gespeichert.');
}
$leadAfterOffer = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $staffToken);
if (isset($leadAfterOffer['body']['data']['leadProcess']['offer']['tokenHash'])) {
    throw new RuntimeException('Der Angebots-Token-Hash wurde über die normale Ticket-API offengelegt.');
}
$offerMail = mailpitMessageBySubject('Ihr individuelles Angebot zu ' . $leadTicketNumber);
$mailBody = (string) ($offerMail['Text'] ?? '') . ' ' . (string) ($offerMail['HTML'] ?? '');
if (preg_match('/token=([a-f0-9]{64})/', html_entity_decode($mailBody), $tokenMatch) !== 1) {
    throw new RuntimeException('Die Angebotsmail enthält keinen gültigen Entscheidungslink.');
}
$offerToken = $tokenMatch[1];
assertStatus(request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer/resend', token: $staffToken), [403], 'Techniker vom erneuten Angebotsversand ausschließen');
$mailCountBeforeOfferResend = mailpitMessageCount();
$resentLeadOffer = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer/resend', token: $salesToken);
assertStatus($resentLeadOffer, [200], 'Vertrieb versendet bestehende Angebotsrunde erneut');
if (($resentLeadOffer['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'sent'
    || ($resentLeadOffer['body']['data']['leadProcess']['offer']['offerNumber'] ?? null) !== ($sentLeadOffer['body']['data']['leadProcess']['offer']['offerNumber'] ?? null)
    || ($resentLeadOffer['body']['data']['leadProcess']['offer']['resendCount'] ?? null) !== 1
    || empty($resentLeadOffer['body']['data']['leadProcess']['offer']['lastResentAt'])
    || isset($resentLeadOffer['body']['data']['leadProcess']['offer']['tokenHash'])) {
    throw new RuntimeException('Der erneute Angebotsversand hat die bestehende Angebotsrunde verändert oder unvollständig protokolliert.');
}
if (mailpitMessageCount() !== $mailCountBeforeOfferResend + 1) {
    throw new RuntimeException('Der erneute Angebotsversand hat nicht genau eine neue E-Mail erzeugt.');
}
$resentOfferMail = mailpitMessageBySubject('Ihr individuelles Angebot zu ' . $leadTicketNumber);
$resentMailBody = (string) ($resentOfferMail['Text'] ?? '') . ' ' . (string) ($resentOfferMail['HTML'] ?? '');
if (preg_match('/token=([a-f0-9]{64})/', html_entity_decode($resentMailBody), $resentTokenMatch) !== 1
    || $resentTokenMatch[1] === $offerToken) {
    throw new RuntimeException('Die erneut versendete Angebotsmail enthält keinen neuen sicheren Entscheidungslink.');
}
assertStatus(request('GET', '/api/v1/public/lead-offers/' . $offerToken), [404], 'Vorherigen Angebotslink nach erneutem Versand deaktivieren');
$offerToken = $resentTokenMatch[1];
$leadAfterResend = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $adminToken);
$messagesAfterResend = implode(' ', array_column($leadAfterResend['body']['data']['messages'] ?? [], 'bodyText'));
if (!str_contains($messagesAfterResend, 'erneut versendet')) {
    throw new RuntimeException('Der erneute Angebotsversand wurde nicht im Ticketverlauf dokumentiert.');
}
$publicLeadOffer = request('GET', '/api/v1/public/lead-offers/' . $offerToken);
assertStatus($publicLeadOffer, [200], 'Öffentliche Angebotsansicht über Sicherheitstoken laden');
if (($publicLeadOffer['body']['data']['status'] ?? null) !== 'sent'
    || !str_starts_with((string) ($publicLeadOffer['body']['data']['document']['name'] ?? ''), 'A-' . $leadTicketNumber)
    || empty($publicLeadOffer['body']['data']['configuration']['lineItems'])) {
    throw new RuntimeException('Die öffentliche Angebotsansicht ist unvollständig.');
}
$publicOfferDocument = rawRequest('/api/v1/public/lead-offers/' . $offerToken . '/document', $adminToken);
if ($publicOfferDocument['status'] !== 200 || !str_starts_with($publicOfferDocument['body'], '%PDF-1.4')) {
    throw new RuntimeException('Das geschützte Angebots-PDF konnte nicht geladen werden.');
}
fwrite(STDOUT, "[OK] Geschütztes Angebots-PDF laden (200)\n");
$mailCountBeforeRejection = mailpitMessageCount();
$rejectedOffer = request('POST', '/api/v1/public/lead-offers/' . $offerToken . '/decision', ['decision' => 'rejected']);
assertStatus($rejectedOffer, [200], 'Lead-Angebot öffentlich ablehnen');
assertStatus(request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer/resend', token: $salesToken), [409], 'Entschiedenes Angebot nicht erneut versenden');
assertStatus(request('POST', '/api/v1/public/lead-offers/' . $offerToken . '/decision', ['decision' => 'accepted']), [409], 'Abweichende zweite Angebotsentscheidung sperren');
$rejectedLeadTicket = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $staffToken);
$rejectedMessages = implode(' ', array_column($rejectedLeadTicket['body']['data']['messages'] ?? [], 'bodyText'));
if (($rejectedLeadTicket['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'rejected'
    || ($rejectedLeadTicket['body']['data']['status'] ?? null) !== 'in_progress'
    || ($rejectedLeadTicket['body']['data']['leadProcess']['offer']['salesNotificationStatus'] ?? null) !== 'sent'
    || !str_contains($rejectedMessages, 'Anfragende hat das Angebot abgelehnt')) {
    throw new RuntimeException('Die Angebotsablehnung wurde nicht als aktive Vertriebsaufgabe im Ticket dokumentiert.');
}
if (mailpitMessageCount() <= $mailCountBeforeRejection) {
    throw new RuntimeException('Die Angebotsablehnung hat keine interne Vertriebsmail ausgelöst.');
}
$rejectionMail = mailpitMessageBySubject('Lead ' . $leadTicketNumber . ': Angebot wurde abgelehnt');
$rejectionRecipients = array_map(static fn (array $recipient): string => (string) ($recipient['Address'] ?? ''), $rejectionMail['To'] ?? []);
if (!in_array('vertrieb@colomanager.local', $rejectionRecipients, true)
    || !str_contains((string) ($rejectionMail['Text'] ?? ''), '/admin.html?ticket=' . $publicLeadTicketId)) {
    throw new RuntimeException('Die interne Ablehnungsmail wurde nicht an den Vertrieb oder ohne direkten Ticketlink versendet.');
}
$inquiriesAfterDecision = request('GET', '/api/v1/inquiries?limit=100', token: $adminToken);
$inquiriesById = array_column($inquiriesAfterDecision['body']['data']['items'] ?? [], null, 'id');
if (($inquiriesById[$publicInquiryId]['status'] ?? null) !== 'qualified') {
    throw new RuntimeException('Die Angebotsablehnung wurde nicht als weiter zu bearbeitender Vertriebslead gespeichert.');
}
$newOfferRound = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/next-action', ['action' => 'new_offer'], $staffToken);
assertStatus($newOfferRound, [200], 'Nach Ablehnung neue Angebotsrunde starten');
if (($newOfferRound['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'pending'
    || ($newOfferRound['body']['data']['leadProcess']['offer']['round'] ?? null) !== 2
    || count($newOfferRound['body']['data']['leadProcess']['offerHistory'] ?? []) !== 1
    || ($newOfferRound['body']['data']['leadProcess']['offerHistory'][0]['status'] ?? null) !== 'rejected') {
    throw new RuntimeException('Die erste Angebotsrunde wurde nicht korrekt archiviert oder Runde zwei nicht vorbereitet.');
}
assertStatus(request('GET', '/api/v1/public/lead-offers/' . $offerToken), [404], 'Entscheidungslink der archivierten Angebotsrunde deaktivieren');
$secondDraft = request('GET', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer-draft', token: $staffToken);
assertStatus($secondDraft, [200], 'Kopie des abgelehnten Entwurfs für Runde zwei laden');
$secondDraftPayload = $secondDraft['body']['data'] ?? [];
$secondDraftPayload['lineItems'][0]['monthlyUnitPrice'] = (float) ($secondDraftPayload['lineItems'][0]['monthlyUnitPrice'] ?? 0) + 10;
assertStatus(request('PUT', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer-draft', $secondDraftPayload, $staffToken), [200], 'Zweite Angebotsversion mit geänderten Konditionen speichern');
$secondLeadOffer = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-offer', token: $staffToken);
assertStatus($secondLeadOffer, [201], 'Zweite Lead-Angebotsrunde versenden');
if (($secondLeadOffer['body']['data']['leadProcess']['offer']['round'] ?? null) !== 2
    || ($secondLeadOffer['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'sent') {
    throw new RuntimeException('Die zweite Angebotsrunde wurde nicht korrekt versendet.');
}
$secondOfferMail = mailpitMessageBySubject('Ihr individuelles Angebot zu ' . $leadTicketNumber);
$secondMailBody = (string) ($secondOfferMail['Text'] ?? '') . ' ' . (string) ($secondOfferMail['HTML'] ?? '');
if (preg_match('/token=([a-f0-9]{64})/', html_entity_decode($secondMailBody), $secondTokenMatch) !== 1
    || $secondTokenMatch[1] === $offerToken) {
    throw new RuntimeException('Die zweite Angebotsrunde enthält keinen neuen Entscheidungslink.');
}
$secondOfferToken = $secondTokenMatch[1];
assertStatus(request('POST', '/api/v1/public/lead-offers/' . $secondOfferToken . '/decision', ['decision' => 'accepted']), [200], 'Zweite Angebotsrunde annehmen');
$acceptedSecondRound = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $staffToken);
if (($acceptedSecondRound['body']['data']['leadProcess']['offer']['status'] ?? null) !== 'accepted'
    || ($acceptedSecondRound['body']['data']['leadProcess']['nextAction']['status'] ?? null) !== 'not_required'
    || ($acceptedSecondRound['body']['data']['status'] ?? null) !== 'in_progress'
    || empty($acceptedSecondRound['body']['data']['leadProcess']['contractId'])) {
    throw new RuntimeException('Eine angenommene Folgerunde hat den repetitiven Angebotsprozess nicht korrekt beendet.');
}
$generatedContractId = (string) $acceptedSecondRound['body']['data']['leadProcess']['contractId'];
$generatedContract = request('GET', '/api/v1/contracts/' . $generatedContractId, token: $adminToken);
assertStatus($generatedContract, [200], 'Automatisch erzeugten Vertragsentwurf laden');
if (($generatedContract['body']['data']['status'] ?? null) !== 'pending_assignment'
    || ($generatedContract['body']['data']['sourceLead']['offerRound'] ?? null) !== 2
    || ($generatedContract['body']['data']['sourceLead']['ticketId'] ?? null) !== $publicLeadTicketId
    || (float) ($generatedContract['body']['data']['lineItems'][0]['monthlyUnitPrice'] ?? -1) !== (float) $secondDraftPayload['lineItems'][0]['monthlyUnitPrice']) {
    throw new RuntimeException('Der Vertragsentwurf entspricht nicht dem angenommenen Angebotssnapshot.');
}
assertStatus(request('GET', '/api/v1/contracts?limit=100', token: $staffToken), [403], 'Techniker von Vertragsverwaltung ausschließen');
$leadCustomer = request('POST', '/api/v1/customers', [
    'customerNumber' => 'LEAD-' . strtoupper(bin2hex(random_bytes(4))),
    'name' => 'Öffentlicher Testinteressent',
    'email' => $leadCustomerEmail,
    'status' => 'active',
    'billingAddress' => $leadBillingAddress,
], $salesToken);
assertStatus($leadCustomer, [201], 'Vertrieb legt Kunden aus angenommenem Lead an');
$leadCustomerId = (string) ($leadCustomer['body']['data']['id'] ?? '');
if (($leadCustomer['body']['data']['assignedSalesUserId'] ?? null) !== $salesId) {
    throw new RuntimeException('Der anlegende Vertriebler wurde nicht automatisch am Kunden hinterlegt.');
}
$assignedContractPayload = $generatedContract['body']['data'];
$assignedContractPayload['customerId'] = $leadCustomerId;
$assignedContract = request('PATCH', '/api/v1/contracts/' . $generatedContractId, $assignedContractPayload, $salesToken);
assertStatus($assignedContract, [200], 'Vertrieb ordnet Vertragsentwurf einem Kunden zu');
if (($assignedContract['body']['data']['status'] ?? null) !== 'review') {
    throw new RuntimeException('Der zugeordnete Vertragsentwurf wurde nicht in die interne Prüfung überführt.');
}
if (($assignedContract['body']['data']['counterparty']['billingAddress'] ?? null) !== $leadBillingAddress) {
    throw new RuntimeException('Die Rechnungsanschrift wurde nicht in den Vertragssnapshot übernommen.');
}
$contractDocument = rawRequest('/api/v1/contracts/' . $generatedContractId . '/document', $adminToken);
if ($contractDocument['status'] !== 200 || !str_starts_with($contractDocument['body'], '%PDF-1.4')) {
    throw new RuntimeException('Das angenommene Angebotsdokument fehlt am Vertrag.');
}
fwrite(STDOUT, "[OK] Angebotsdokument über Vertrag laden (200)\n");
$contractPreview = rawRequest('/api/v1/contracts/' . $generatedContractId . '/signature-document', $salesToken);
if ($contractPreview['status'] !== 200 || !str_starts_with($contractPreview['body'], '%PDF-1.4')) {
    throw new RuntimeException('Die unterschriftsfähige Vertragsvorschau konnte nicht erzeugt werden.');
}
if (!str_contains($contractPreview['body'], $encodedBrandName)
    || !str_contains($contractPreview['body'], $encodedLeadStreet)
    || !str_contains($contractPreview['body'], $leadBillingAddress['city'])
    || !str_contains($contractPreview['body'], 'EINMALIG NETTO')
    || !str_contains($contractPreview['body'], 'MONATLICH NETTO')
    || ($brandHasLogo && !str_contains($contractPreview['body'], '/Subtype /Image'))) {
    throw new RuntimeException('Die Vertragsvorschau enthält Branding, Anschrift oder Nettopreis-Kennzeichnung nicht vollständig.');
}
fwrite(STDOUT, "[OK] Unterschriftsfähige Vertragsvorschau laden (200)\n");
$sentContract = request('POST', '/api/v1/contracts/' . $generatedContractId . '/send-for-signature', token: $salesToken);
assertStatus($sentContract, [201], 'Vertrag zur Unterschrift versenden');
if (($sentContract['body']['data']['status'] ?? null) !== 'awaiting_signature'
    || array_key_exists('tokenHash', $sentContract['body']['data']['signature'] ?? [])) {
    throw new RuntimeException('Der Vertragsversand besitzt einen falschen Status oder gibt den Token-Hash aus.');
}
$contractNumber = (string) ($sentContract['body']['data']['number'] ?? '');
$contractMail = mailpitMessageBySubject('Ihr Vertrag ' . $contractNumber . ' zur Unterschrift');
$contractMailBody = html_entity_decode((string) ($contractMail['Text'] ?? '') . ' ' . (string) ($contractMail['HTML'] ?? ''));
if (preg_match('/vertrag\.html\?token=([a-f0-9]{64})/', $contractMailBody, $contractTokenMatch) !== 1) {
    throw new RuntimeException('Die Vertragsmail enthält keinen gültigen Signaturlink.');
}
$contractToken = $contractTokenMatch[1];
$publicContract = request('GET', '/api/v1/public/contracts/' . $contractToken);
assertStatus($publicContract, [200], 'Öffentliche Vertragsseite laden');
if (($publicContract['body']['data']['status'] ?? null) !== 'sent'
    || ($publicContract['body']['data']['contractNumber'] ?? null) !== $contractNumber) {
    throw new RuntimeException('Die öffentliche Vertragsseite enthält nicht den versendeten Vertrag.');
}
$publicContractDocument = rawRequest('/api/v1/public/contracts/' . $contractToken . '/document', $adminToken);
if ($publicContractDocument['status'] !== 200 || !str_starts_with($publicContractDocument['body'], '%PDF-1.4')) {
    throw new RuntimeException('Das öffentliche Vertragsdokument ist nicht erreichbar.');
}
fwrite(STDOUT, "[OK] Öffentliches Vertragsdokument laden (200)\n");
$signedUpload = multipartRequest('/api/v1/public/contracts/' . $contractToken . '/signed-document', [], [[
    'field' => 'signedContract',
    'name' => $contractNumber . '-unterschrieben.pdf',
    'type' => 'application/pdf',
    'content' => $contractPreview['body'],
]], $adminToken);
assertStatus($signedUpload, [200], 'Unterschriebenen Vertrag öffentlich hochladen');
if (($signedUpload['body']['data']['status'] ?? null) !== 'signed_received') {
    throw new RuntimeException('Der öffentliche Vertragsupload wurde nicht als eingegangen markiert.');
}
$signedDocument = rawRequest('/api/v1/contracts/' . $generatedContractId . '/signed-document', $staffToken);
if ($signedDocument['status'] !== 200 || !str_starts_with($signedDocument['body'], '%PDF-1.4')) {
    throw new RuntimeException('Die unterschriebene Fassung ist intern nicht erreichbar.');
}
fwrite(STDOUT, "[OK] Unterschriebenen Vertrag intern laden (200)\n");
$handoff = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/onboarding/handoff', [
    'assignedToUserId' => $staffId,
], $salesToken);
assertStatus($handoff, [200], 'Lead an technisches Onboarding übergeben');
if (($handoff['body']['data']['leadProcess']['onboarding']['assignedToUserId'] ?? null) !== $staffId
    || ($handoff['body']['data']['assignedToUserId'] ?? null) !== $staffId) {
    throw new RuntimeException('Die Onboarding-Übergabe wurde nicht dem gewählten Techniker zugeordnet.');
}
$leadCustomerAfterHandoff = request('GET', '/api/v1/customers/' . $leadCustomerId, token: $salesToken);
assertStatus($leadCustomerAfterHandoff, [200], 'Feste Kundenkontakte nach Onboarding-Übergabe laden');
if (($leadCustomerAfterHandoff['body']['data']['assignedTechnicianUserId'] ?? null) !== $staffId
    || ($leadCustomerAfterHandoff['body']['data']['assignedSalesUserId'] ?? null) !== $salesId) {
    throw new RuntimeException('Technik und Vertrieb wurden nicht dauerhaft am Kunden hinterlegt.');
}
$onboardingStart = new DateTimeImmutable('+2 hours', new DateTimeZone('Europe/Berlin'));
$onboardingPayload = [
    'startsAtLocal' => $onboardingStart->format('Y-m-d\TH:i'),
    'durationMinutes' => 90,
    'timezone' => 'Europe/Berlin',
    'notes' => 'Bitte am Empfang unter Nennung des Ticketcodes anmelden.',
];
assertStatus(request('PUT', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/onboarding/appointment', $onboardingPayload, $salesToken), [403], 'Nur zugewiesenen Techniker den Onboarding-Termin planen lassen');
$appointmentMailCount = mailpitMessageCount();
$scheduledAppointment = request('PUT', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/onboarding/appointment', $onboardingPayload, $staffToken);
assertStatus($scheduledAppointment, [200], 'Techniker plant Onboarding-Termin und versendet iCalendar-Einladung');
$appointment = $scheduledAppointment['body']['data']['leadProcess']['onboarding']['appointment'] ?? [];
if (($appointment['status'] ?? null) !== 'scheduled'
    || ($appointment['durationMinutes'] ?? null) !== 90
    || ($appointment['timezone'] ?? null) !== 'Europe/Berlin'
    || ($appointment['emailStatus'] ?? null) !== 'sent'
    || ($appointment['reminder']['status'] ?? null) !== 'pending'
    || ($appointment['sequence'] ?? null) !== 0) {
    throw new RuntimeException('Der Onboarding-Termin wurde nicht vollständig in Lead und Reminder-Prozess gespeichert.');
}
if (mailpitMessageCount() !== $appointmentMailCount + 1) {
    throw new RuntimeException('Die Terminplanung hat nicht genau eine Kundenmail erzeugt.');
}
$appointmentMail = mailpitMessageBySubject('Ihr Onboarding-Termin zu ' . $leadTicketNumber);
$calendarAttachments = $appointmentMail['Attachments'] ?? $appointmentMail['attachments'] ?? [];
$calendarAttachment = $calendarAttachments[0] ?? [];
if (!str_ends_with(strtolower((string) ($calendarAttachment['FileName'] ?? $calendarAttachment['filename'] ?? $calendarAttachment['Name'] ?? '')), '.ics')
    || !str_starts_with(strtolower((string) ($calendarAttachment['ContentType'] ?? $calendarAttachment['contentType'] ?? '')), 'text/calendar')) {
    throw new RuntimeException('Die Kundenmail enthält keine gültig deklarierte iCalendar-Datei. Antwort: ' . json_encode($calendarAttachments, JSON_UNESCAPED_UNICODE));
}
$calendarPartId = (string) ($calendarAttachment['PartID'] ?? '');
$calendarMailId = (string) ($appointmentMail['ID'] ?? '');
$calendarContent = $calendarPartId !== '' && $calendarMailId !== ''
    ? file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode($calendarMailId) . '/part/' . rawurlencode($calendarPartId))
    : false;
if (!is_string($calendarContent)
    || !str_contains($calendarContent, 'BEGIN:VCALENDAR')
    || !str_contains($calendarContent, 'BEGIN:VEVENT')
    || !str_contains($calendarContent, 'DTSTART:')
    || !str_contains($calendarContent, 'BEGIN:VALARM')) {
    throw new RuntimeException('Der iCalendar-Anhang enthält keinen vollständigen Termin.');
}
$initialAppointmentUid = $appointment['uid'] ?? null;
$updatedOnboardingPayload = $onboardingPayload;
$updatedOnboardingPayload['startsAtLocal'] = $onboardingStart->modify('+30 minutes')->format('Y-m-d\TH:i');
$rescheduleMailCount = mailpitMessageCount();
$rescheduledAppointment = request('PUT', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/onboarding/appointment', $updatedOnboardingPayload, $staffToken);
assertStatus($rescheduledAppointment, [200], 'Techniker aktualisiert Onboarding-Termin und Kalendereinladung');
$appointment = $rescheduledAppointment['body']['data']['leadProcess']['onboarding']['appointment'] ?? [];
if (($appointment['uid'] ?? null) !== $initialAppointmentUid
    || ($appointment['sequence'] ?? null) !== 1
    || ($appointment['emailStatus'] ?? null) !== 'sent'
    || mailpitMessageCount() !== $rescheduleMailCount + 1) {
    throw new RuntimeException('Eine Terminänderung wurde nicht als neue Sequenz derselben Kalendereinladung versendet.');
}
$contractAfterAppointment = request('GET', '/api/v1/contracts/' . $generatedContractId, token: $salesToken);
if (($contractAfterAppointment['body']['data']['onboarding']['appointment']['startAt'] ?? null) !== ($appointment['startAt'] ?? null)) {
    throw new RuntimeException('Der Onboarding-Termin wurde nicht in den Vertrag übernommen.');
}

// Der produktive Worker läuft minütlich. Für den Test wird nur die Fälligkeit
// auf jetzt gesetzt und derselbe CLI-Prozess einmalig ausgeführt.
$testDatabase = (new \ColoManager\Database\MongoConnection(\ColoManager\Config::fromEnvironment()))->database();
$testDatabase->selectCollection('tickets')->updateOne(
    ['_id' => new \MongoDB\BSON\ObjectId($publicLeadTicketId)],
    ['$set' => ['leadProcess.onboarding.appointment.reminder.dueAt' => new \MongoDB\BSON\UTCDateTime()]],
);
$reminderMailCount = mailpitMessageCount();
$workerOutput = [];
$workerExitCode = 0;
exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/send-onboarding-reminders.php'), $workerOutput, $workerExitCode);
if ($workerExitCode !== 0) {
    throw new RuntimeException('Der Onboarding-Reminder-Worker ist fehlgeschlagen: ' . implode("\n", $workerOutput));
}
if (mailpitMessageCount() !== $reminderMailCount + 1) {
    throw new RuntimeException('Der Reminder-Worker hat nicht genau eine Techniker-Mail erzeugt.');
}
$technicianReminderMail = mailpitMessageBySubject('Heute: Onboarding-Termin ' . $leadTicketNumber);
$reminderRecipients = array_map(static fn (mixed $recipient): string => is_array($recipient) ? (string) ($recipient['Address'] ?? '') : (string) $recipient, $technicianReminderMail['To'] ?? []);
if (!in_array('technik@colomanager.local', $reminderRecipients, true)) {
    throw new RuntimeException('Die Erinnerung wurde nicht an den zugewiesenen Techniker gesendet.');
}
$leadAfterReminder = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $staffToken);
if (($leadAfterReminder['body']['data']['leadProcess']['onboarding']['appointment']['reminder']['status'] ?? null) !== 'sent') {
    throw new RuntimeException('Die Techniker-Erinnerung wurde nicht idempotent als versendet markiert.');
}
$mailCountAfterReminder = mailpitMessageCount();
exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/send-onboarding-reminders.php'), $workerOutput, $workerExitCode);
if ($workerExitCode !== 0 || mailpitMessageCount() !== $mailCountAfterReminder) {
    throw new RuntimeException('Der Reminder-Worker hat einen bereits versendeten Termin erneut benachrichtigt.');
}
fwrite(STDOUT, "[OK] Techniker-Erinnerung am Termintag genau einmal versenden\n");
$invitation = request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/onboarding/invite', token: $staffToken);
assertStatus($invitation, [201], 'Techniker versendet Portal-Einladung');
if (array_key_exists('tokenHash', $invitation['body']['data']['leadProcess']['onboarding']['invitation'] ?? [])) {
    throw new RuntimeException('Der Hash der Konto-Einladung wurde über die Ticket-API offengelegt.');
}
$invitationMail = mailpitMessageBySubject('Ihr Zugang zum ' . $brandName . ' Kundenportal');
$invitationMailBody = html_entity_decode((string) ($invitationMail['Text'] ?? '') . ' ' . (string) ($invitationMail['HTML'] ?? ''));
if (preg_match('/konto-aktivieren\.html\?token=([a-f0-9]{64})/', $invitationMailBody, $invitationTokenMatch) !== 1) {
    throw new RuntimeException('Die Portal-Einladung enthält keinen gültigen Aktivierungslink.');
}
$invitationToken = $invitationTokenMatch[1];
$publicInvitation = request('GET', '/api/v1/public/account-invitations/' . $invitationToken);
assertStatus($publicInvitation, [200], 'Öffentliche Portal-Einladung laden');
if (($publicInvitation['body']['data']['email'] ?? null) !== $leadCustomerEmail) {
    throw new RuntimeException('Die Portal-Einladung wurde nicht für die Lead-Adresse erzeugt.');
}
$onboardingPassword = 'Onboarding' . bin2hex(random_bytes(4)) . 'A1!';
$activatedAccount = request('POST', '/api/v1/public/account-invitations/' . $invitationToken . '/activate', [
    'password' => $onboardingPassword,
    'passwordConfirmation' => $onboardingPassword,
]);
assertStatus($activatedAccount, [200], 'Kundenkonto aus Einladung aktivieren');
$leadCustomerLogin = request('POST', '/api/v1/auth/login', [
    'email' => $leadCustomerEmail,
    'password' => $onboardingPassword,
]);
assertStatus($leadCustomerLogin, [200], 'Neu onboardeten Kunden anmelden');
$passwordResetRequest = request('POST', '/api/v1/auth/password/forgot', ['email' => $leadCustomerEmail]);
assertStatus($passwordResetRequest, [202], 'Passwort-Reset anfordern');
$passwordResetMail = mailpitMessageBySubject('Passwort für ' . $brandName . ' zurücksetzen');
$passwordResetMailBody = html_entity_decode((string) ($passwordResetMail['Text'] ?? '') . ' ' . (string) ($passwordResetMail['HTML'] ?? ''));
if (preg_match('/passwort-zuruecksetzen\.html\?token=([a-f0-9]{64})/', $passwordResetMailBody, $passwordResetTokenMatch) !== 1) {
    throw new RuntimeException('Die Passwort-Reset-Mail enthält keinen gültigen Einmal-Link.');
}
$passwordResetToken = $passwordResetTokenMatch[1];
assertStatus(request('GET', '/api/v1/auth/password/reset/' . $passwordResetToken), [200], 'Passwort-Reset-Link prüfen');
$newOnboardingPassword = 'Reset' . bin2hex(random_bytes(6)) . 'Aa1!';
assertStatus(request('POST', '/api/v1/auth/password/reset/' . $passwordResetToken, [
    'password' => $newOnboardingPassword,
    'passwordConfirmation' => $newOnboardingPassword,
]), [200], 'Neues Passwort setzen');
assertStatus(request('POST', '/api/v1/auth/password/reset/' . $passwordResetToken, [
    'password' => $newOnboardingPassword,
    'passwordConfirmation' => $newOnboardingPassword,
]), [409], 'Passwort-Reset-Link nur einmal verwenden');
assertStatus(request('POST', '/api/v1/auth/login', [
    'email' => $leadCustomerEmail,
    'password' => $onboardingPassword,
]), [401], 'Altes Passwort nach Reset ablehnen');
$resetCustomerLogin = request('POST', '/api/v1/auth/login', [
    'email' => $leadCustomerEmail,
    'password' => $newOnboardingPassword,
]);
assertStatus($resetCustomerLogin, [200], 'Anmeldung mit neuem Passwort');
$leadCustomerToken = (string) ($leadCustomerLogin['body']['data']['accessToken'] ?? '');
$onboardedCustomer = request('GET', '/api/v1/customers/current', token: $leadCustomerToken);
assertStatus($onboardedCustomer, [200], 'Onboardeten Kunden mit Tarif laden');
if (($onboardedCustomer['body']['data']['subscription']['plan']['id'] ?? null) !== $publicPlanId
    || ($onboardedCustomer['body']['data']['subscription']['bandwidth']['id'] ?? null) !== $publicBandwidthId
    || ($onboardedCustomer['body']['data']['contacts']['technician']['id'] ?? null) !== $staffId
    || ($onboardedCustomer['body']['data']['contacts']['sales']['id'] ?? null) !== $salesId) {
    throw new RuntimeException('Tarif und Bandbreite wurden aus dem Vertrag nicht auf das neue Kundenkonto übernommen.');
}
$customerContracts = request('GET', '/api/v1/customer/contracts?limit=100', token: $leadCustomerToken);
assertStatus($customerContracts, [200], 'Verträge im neuen Kundenportal laden');
if (!in_array($generatedContractId, array_column($customerContracts['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Der unterzeichnete Vertrag fehlt im neuen Kundenportal.');
}
$customerSignedDocument = rawRequest('/api/v1/customer/contracts/' . $generatedContractId . '/document', $leadCustomerToken);
if ($customerSignedDocument['status'] !== 200 || !str_starts_with($customerSignedDocument['body'], '%PDF-1.4')) {
    throw new RuntimeException('Der Kunde kann seine unterschriebene Vertragsfassung nicht laden.');
}
fwrite(STDOUT, "[OK] Unterschriebenen Vertrag im Kundenportal laden (200)\n");
$leadAfterContractActivation = request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $adminToken);
if (($leadAfterContractActivation['body']['data']['status'] ?? null) !== 'closed'
    || ($leadAfterContractActivation['body']['data']['leadProcess']['onboarding']['status'] ?? null) !== 'completed'
    || !in_array(($leadAfterContractActivation['body']['data']['leadProcess']['contractStatus'] ?? null), ['scheduled', 'active'], true)) {
    throw new RuntimeException('Portalaktivierung, Vertragsstatus und Lead-Abschluss wurden nicht gemeinsam finalisiert.');
}
assertStatus(request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $leadCustomerToken), [404], 'Internes Lead-Ticket vor neuem Kunden verbergen');
assertStatus(request('POST', '/api/v1/tickets/' . $publicLeadTicketId . '/lead-process/next-action', ['action' => 'new_offer'], $staffToken), [409], 'Neue Angebotsrunde nach Annahme sperren');
$inquiriesAfterAcceptance = request('GET', '/api/v1/inquiries?limit=100', token: $adminToken);
$acceptedInquiriesById = array_column($inquiriesAfterAcceptance['body']['data']['items'] ?? [], null, 'id');
if (($acceptedInquiriesById[$publicInquiryId]['status'] ?? null) !== 'won') {
    throw new RuntimeException('Die angenommene zweite Angebotsrunde wurde nicht als gewonnen gespeichert.');
}
assertStatus(request('DELETE', '/api/v1/inquiries/' . $publicInquiryId, token: $adminToken), [409], 'Lead mit Vertrag vor Löschung schützen');

$customerTermination = request('POST', '/api/v1/customer/contracts/' . $generatedContractId . '/termination-request', [
    'reason' => 'Ordentliche Kündigung zum Laufzeitende im automatisierten Test.',
], $leadCustomerToken);
assertStatus($customerTermination, [200], 'Kunde merkt Vertragskündigung vor');
if (($customerTermination['body']['data']['termination']['status'] ?? null) !== 'requested'
    || empty($customerTermination['body']['data']['termination']['effectiveAt'])) {
    throw new RuntimeException('Die ordentliche Kundenkündigung wurde nicht mit ihrem Wirksamkeitsdatum gespeichert.');
}
assertStatus(request('POST', '/api/v1/contracts/' . $generatedContractId . '/terminate', [
    'reason' => 'Vorzeitige Beendigung im automatisierten Test.',
], $salesToken), [403], 'Vertrieb darf Vertrag nicht vorzeitig beenden');
$terminatedContract = request('POST', '/api/v1/contracts/' . $generatedContractId . '/terminate', [
    'reason' => 'Vorzeitige Beendigung im automatisierten Test.',
], $adminToken);
assertStatus($terminatedContract, [200], 'Admin beendet Vertrag vorzeitig');
if (($terminatedContract['body']['data']['status'] ?? null) !== 'terminated'
    || ($terminatedContract['body']['data']['termination']['type'] ?? null) !== 'early_by_admin') {
    throw new RuntimeException('Die vorzeitige Vertragsbeendigung wurde nicht vollständig gespeichert.');
}
assertStatus(request('DELETE', '/api/v1/contracts/' . $generatedContractId, token: $salesToken), [409], 'Vertrieb darf beendeten Vertrag nicht löschen');
assertStatus(request('DELETE', '/api/v1/contracts/' . $generatedContractId, token: $adminToken), [204], 'Admin löscht beendeten Vertrag');

// Ausschließlich die automatisiert erzeugten Testdatensätze werden nach dem
// Löschschutz-Test direkt entfernt. Die öffentliche API nutzt auch bei einer
// Admin-Löschung weiterhin ein revisionsfreundliches Soft-Delete.
$cleanupClient = new \MongoDB\Client((string) getenv('MONGODB_URI'));
$cleanupDatabase = $cleanupClient->selectDatabase((string) (getenv('MONGODB_DATABASE') ?: 'colo_manager'));
$cleanupDatabase->selectCollection('users')->deleteOne(['email' => $leadCustomerEmail]);
$cleanupDatabase->selectCollection('contracts')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($generatedContractId)]);
$cleanupDatabase->selectCollection('customers')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($leadCustomerId)]);
$cleanupDatabase->selectCollection('tickets')->updateOne(
    ['_id' => new \MongoDB\BSON\ObjectId($publicLeadTicketId)],
    ['$unset' => ['leadProcess.contractId' => '', 'leadProcess.contractNumber' => '', 'leadProcess.contractStatus' => '']],
);
assertStatus(request('DELETE', '/api/v1/inquiries/' . $publicInquiryId, token: $adminToken), [204], 'Testvertrag und angenommenen Lead bereinigen');
assertStatus(request('GET', '/api/v1/tickets/' . $publicLeadTicketId, token: $adminToken), [404], 'Gelöschtes Lead-Ticket ausblenden');

// Zweiter Lead prüft den alternativen Prozesszweig „nach Ablehnung schließen“.
$closableInquiry = request('POST', '/api/v1/public/inquiries', [
    'company' => 'Abschluss-Testinteressent',
    'contactName' => 'Max Abschluss',
    'email' => 'close-' . bin2hex(random_bytes(3)) . '@example.invalid',
    'locationId' => $publicLocationId,
    'planId' => $publicPlanId,
    'bandwidthOptionId' => $publicBandwidthId,
    'rackUnits' => $publicRackUnits,
    'rackType' => $publicRackType,
    'powerKw' => (float) ($publicPlan['powerKw'] ?? 1.0),
    'networkBillingModel' => $publicBillingModel,
    'contractMonths' => 12,
    'message' => 'Alternativer Abschlusszweig',
    'billingAddress' => $leadBillingAddress,
    'consent' => true,
    'website' => '',
]);
assertStatus($closableInquiry, [201], 'Zweiten Lead für Schließen-Zweig anlegen');
$closableInquiryId = (string) ($closableInquiry['body']['data']['id'] ?? '');
$closableTicketId = (string) ($closableInquiry['body']['data']['ticketId'] ?? '');
$closableTicketNumber = (string) ($closableInquiry['body']['data']['ticketNumber'] ?? '');
assertStatus(request('POST', '/api/v1/tickets/' . $closableTicketId . '/lead-process/contact', token: $staffToken), [200], 'Kontaktaufnahme des zweiten Leads dokumentieren');
$closableDraft = request('GET', '/api/v1/tickets/' . $closableTicketId . '/lead-offer-draft', token: $staffToken);
assertStatus($closableDraft, [200], 'Entwurf für zweiten Lead laden');
assertStatus(request('PUT', '/api/v1/tickets/' . $closableTicketId . '/lead-offer-draft', $closableDraft['body']['data'] ?? [], $staffToken), [200], 'Entwurf für zweiten Lead speichern');
assertStatus(request('POST', '/api/v1/tickets/' . $closableTicketId . '/lead-offer', token: $staffToken), [201], 'Angebot für Schließen-Zweig generieren');
$closableOfferMail = mailpitMessageBySubject('Ihr individuelles Angebot zu ' . $closableTicketNumber);
$closableMailBody = (string) ($closableOfferMail['Text'] ?? '') . ' ' . (string) ($closableOfferMail['HTML'] ?? '');
if (preg_match('/token=([a-f0-9]{64})/', html_entity_decode($closableMailBody), $closableTokenMatch) !== 1) {
    throw new RuntimeException('Der zweite Lead enthält keinen Entscheidungslink.');
}
assertStatus(request('POST', '/api/v1/public/lead-offers/' . $closableTokenMatch[1] . '/decision', ['decision' => 'rejected']), [200], 'Angebot des zweiten Leads ablehnen');
$closedRejectedLead = request('POST', '/api/v1/tickets/' . $closableTicketId . '/lead-process/next-action', ['action' => 'close'], $staffToken);
assertStatus($closedRejectedLead, [200], 'Abgelehnten Lead über Prozessübersicht schließen');
if (($closedRejectedLead['body']['data']['status'] ?? null) !== 'closed'
    || ($closedRejectedLead['body']['data']['leadProcess']['nextAction']['action'] ?? null) !== 'close') {
    throw new RuntimeException('Der alternative Schließen-Zweig wurde nicht vollständig gespeichert.');
}
$closableInquiries = request('GET', '/api/v1/inquiries?limit=100', token: $adminToken);
$closableInquiriesById = array_column($closableInquiries['body']['data']['items'] ?? [], null, 'id');
if (($closableInquiriesById[$closableInquiryId]['status'] ?? null) !== 'lost') {
    throw new RuntimeException('Der geschlossene abgelehnte Lead wurde nicht als verloren synchronisiert.');
}
assertStatus(request('DELETE', '/api/v1/inquiries/' . $closableInquiryId, token: $adminToken), [204], 'Zweiten Testlead löschen');
assertStatus(request('POST', '/api/v1/tickets/' . $customerTicketId . '/messages', ['bodyHtml' => '<p>Interne Admin-Notiz.</p>'], $adminToken), [201], 'Adminnotiz bleibt ohne Freigabe intern');
assertStatus(request('PATCH', '/api/v1/tickets/' . $customerTicketId, ['status' => 'closed'], $staffToken), [422], 'Remote-Hands-Ticket ohne Zeitnachweis nicht schließen');
assertStatus(request('PATCH', '/api/v1/tickets/' . $customerTicketId, [
    'status' => 'closed',
    'remoteHandsOnsiteMinutes' => 0,
    'remoteHandsAdministrationMinutes' => 10,
], $staffToken), [422], 'Remote-Hands-Abschluss ohne Vor-Ort-Zeit ablehnen');
$closedRemoteHandsTicket = request('PATCH', '/api/v1/tickets/' . $customerTicketId, [
    'status' => 'closed',
    'remoteHandsOnsiteMinutes' => 35,
    'remoteHandsAdministrationMinutes' => 10,
    'remoteHandsBillable' => true,
], $staffToken);
assertStatus($closedRemoteHandsTicket, [200], 'Remote-Hands-Ticket mit Zeitnachweis schließen');
$workLog = $closedRemoteHandsTicket['body']['data']['remoteHandsWorkLog'] ?? [];
if (($workLog['onsiteMinutes'] ?? null) !== 35
    || ($workLog['administrationMinutes'] ?? null) !== 10
    || ($workLog['totalMinutes'] ?? null) !== 45
    || ($workLog['billable'] ?? null) !== true
    || ($workLog['billingStatus'] ?? null) !== 'pending'
    || ($workLog['recordedBy']['userId'] ?? null) !== $staffId) {
    throw new RuntimeException('Der Remote-Hands-Zeitnachweis wurde nicht vollständig gespeichert.');
}
$activeQueueAfterClose = request('GET', '/api/v1/tickets?limit=100', token: $staffToken);
assertStatus($activeQueueAfterClose, [200], 'Geschlossenes Ticket aus operativer Mitarbeiterqueue ausblenden');
if (in_array($customerTicketId, array_column($activeQueueAfterClose['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Ein geschlossenes Ticket blieb ohne Archivfilter in der Mitarbeiterqueue sichtbar.');
}
$closedStaffQueue = request('GET', '/api/v1/tickets?limit=100&status=closed', token: $staffToken);
assertStatus($closedStaffQueue, [200], 'Eigenes geschlossenes Ticket über Archivfilter laden');
if (!in_array($customerTicketId, array_column($closedStaffQueue['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Das eigene geschlossene Ticket fehlt im expliziten Archivfilter.');
}
$customerClosedTicket = request('GET', '/api/v1/tickets/' . $customerTicketId, token: $token);
assertStatus($customerClosedTicket, [200], 'Geschlossenes Remote-Hands-Ticket als Kunde laden');
if (array_key_exists('remoteHandsWorkLog', $customerClosedTicket['body']['data'] ?? [])) {
    throw new RuntimeException('Interne Arbeits- und Abrechnungsdaten wurden an den Kunden ausgegeben.');
}
assertStatus(request('DELETE', '/api/v1/tickets/' . $customerTicketId, token: $adminToken), [204], 'Testticket löschen');
assertStatus(request('DELETE', '/api/v1/tickets/' . $internalTicketId, token: $adminToken), [204], 'Internes Testticket löschen');

// Der Statusbereich wird über öffentliche, kundenspezifische und administrative
// Sichten geprüft. Interne Meldungen dürfen dabei nie nach außen gelangen.
$publicStatus = request('GET', '/api/v1/public/status');
assertStatus($publicStatus, [200], 'Öffentlichen Systemstatus laden');
$baselineSystemStatus = $publicStatus['body']['data']['system'] ?? [];
assertStatus(request('POST', '/api/v1/incidents', ['title' => 'Nicht erlaubt'], $token), [403], 'Statuspflege als Kunde sperren');

$statusSuffix = strtoupper(bin2hex(random_bytes(3)));
$privateIncident = request('POST', '/api/v1/incidents', [
    'title' => 'Interne Teststörung ' . $statusSuffix,
    'description' => 'Nur der betroffene Kunde darf diese Meldung sehen.',
    'status' => 'offen',
    'priority' => 'medium',
    'isPublic' => false,
    'affectsAllCustomers' => false,
    'locationId' => $locationId,
    'rackId' => $demoRackId,
    'startAt' => (new DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
], $adminToken);
assertStatus($privateIncident, [201], 'Interne Störung anlegen');
$privateIncidentId = $privateIncident['body']['data']['id'];

$customerIncidents = request('GET', '/api/v1/customer/incidents?limit=100', token: $token);
assertStatus($customerIncidents, [200], 'Kundenspezifische Störungen laden');
$customerIncidentIds = array_column($customerIncidents['body']['data']['items'] ?? [], 'id');
if (!in_array($privateIncidentId, $customerIncidentIds, true)) {
    throw new RuntimeException('Die interne Störung fehlt in der Kundensicht.');
}
$publicIncidents = request('GET', '/api/v1/public/status/incidents?limit=100');
assertStatus($publicIncidents, [200], 'Öffentliche Störungen laden');
if (in_array($privateIncidentId, array_column($publicIncidents['body']['data']['items'] ?? [], 'id'), true)) {
    throw new RuntimeException('Eine interne Störung wurde öffentlich ausgegeben.');
}
assertStatus(request('PATCH', '/api/v1/incidents/' . $privateIncidentId, [
    'status' => 'in_bearbeitung',
    'comment' => 'Smoke-Test Statuswechsel',
], $adminToken), [200], 'Störungsstatus bearbeiten');
$privateHistory = request('GET', '/api/v1/incidents/' . $privateIncidentId . '/history', token: $adminToken);
assertStatus($privateHistory, [200], 'Störungshistorie laden');
if (count($privateHistory['body']['data']['items'] ?? []) < 2) {
    throw new RuntimeException('Der Statuswechsel wurde nicht historisiert.');
}

$publicIncident = request('POST', '/api/v1/incidents', [
    'title' => 'Öffentliche Teststörung ' . $statusSuffix,
    'description' => 'Diese Meldung prüft die öffentliche Statusberechnung.',
    'status' => 'offen',
    'priority' => 'high',
    'isPublic' => true,
    'affectsAllCustomers' => true,
    'startAt' => (new DateTimeImmutable('-2 minutes'))->format(DATE_ATOM),
], $adminToken);
assertStatus($publicIncident, [201], 'Öffentliche Störung anlegen');
$publicIncidentId = $publicIncident['body']['data']['id'];
$degradedStatus = request('GET', '/api/v1/public/status/system');
assertStatus($degradedStatus, [200], 'Eingeschränkten Systemstatus berechnen');
$expectedIncidentStatus = (int) ($baselineSystemStatus['criticalIncidents'] ?? 0) > 0 ? 'critical' : 'degraded';
if (($degradedStatus['body']['data']['status'] ?? null) !== $expectedIncidentStatus
    || (int) ($degradedStatus['body']['data']['activeIncidents'] ?? 0) !== (int) ($baselineSystemStatus['activeIncidents'] ?? 0) + 1
) {
    throw new RuntimeException('Eine aktive öffentliche Störung wird im Gesamtstatus nicht korrekt berücksichtigt.');
}
assertStatus(request('PATCH', '/api/v1/incidents/' . $publicIncidentId, [
    'status' => 'behoben',
    'comment' => 'Automatisch behoben',
], $adminToken), [200], 'Öffentliche Störung beheben');

$publicMaintenance = request('POST', '/api/v1/maintenance', [
    'title' => 'Öffentliche Testwartung ' . $statusSuffix,
    'description' => 'Test eines laufenden Wartungsfensters.',
    'impact' => 'Keine erwartete Unterbrechung.',
    'status' => 'aktiv',
    'isPublic' => true,
    'affectsAllCustomers' => true,
    'plannedStart' => (new DateTimeImmutable('-10 minutes'))->format(DATE_ATOM),
    'plannedEnd' => (new DateTimeImmutable('+10 minutes'))->format(DATE_ATOM),
], $adminToken);
assertStatus($publicMaintenance, [201], 'Öffentliche Wartung anlegen');
$publicMaintenanceId = $publicMaintenance['body']['data']['id'];
$maintenanceStatus = request('GET', '/api/v1/public/status/system');
assertStatus($maintenanceStatus, [200], 'Wartungsstatus berechnen');
$expectedMaintenanceStatus = match (true) {
    (int) ($baselineSystemStatus['criticalIncidents'] ?? 0) > 0 => 'critical',
    (int) ($baselineSystemStatus['activeIncidents'] ?? 0) > 0 => 'degraded',
    default => 'maintenance',
};
if (($maintenanceStatus['body']['data']['status'] ?? null) !== $expectedMaintenanceStatus
    || (int) ($maintenanceStatus['body']['data']['activeMaintenance'] ?? 0) !== (int) ($baselineSystemStatus['activeMaintenance'] ?? 0) + 1
) {
    throw new RuntimeException('Eine aktive öffentliche Wartung wird im Gesamtstatus nicht korrekt berücksichtigt.');
}
assertStatus(request('PATCH', '/api/v1/maintenance/' . $publicMaintenanceId, ['status' => 'abgeschlossen'], $adminToken), [200], 'Wartung abschließen');
$operationalStatus = request('GET', '/api/v1/public/status/system');
assertStatus($operationalStatus, [200], 'Normalen Systemstatus wiederherstellen');
if (($operationalStatus['body']['data']['status'] ?? null) !== ($baselineSystemStatus['status'] ?? null)) {
    throw new RuntimeException('Nach Abschluss der Testmeldungen wurde der vorherige Gesamtstatus nicht wiederhergestellt.');
}
assertStatus(request('DELETE', '/api/v1/incidents/' . $privateIncidentId, token: $adminToken), [204], 'Interne Teststörung löschen');
assertStatus(request('DELETE', '/api/v1/incidents/' . $publicIncidentId, token: $adminToken), [204], 'Öffentliche Teststörung löschen');
assertStatus(request('DELETE', '/api/v1/maintenance/' . $publicMaintenanceId, token: $adminToken), [204], 'Testwartung löschen');

// Der folgende isolierte Datensatzverbund prüft den kompletten API-First-CRUD-
// Ablauf. Die Ressourcen werden anschließend in fachlich korrekter Reihenfolge
// per Soft Delete entfernt und erscheinen nicht in späteren Listenabfragen.
$suffix = strtoupper(bin2hex(random_bytes(4)));
$createdPlan = request('POST', '/api/v1/plans', [
    'code' => 'TEST-PLAN-' . $suffix,
    'name' => 'Temporärer Tarif',
    'rackUnits' => 10,
    'powerKw' => 2.0,
    'monthlyPrice' => 199.0,
    'status' => 'draft',
    'locationIds' => [$locationId],
], $adminToken);
assertStatus($createdPlan, [201], 'Tarif anlegen');
$planId = $createdPlan['body']['data']['id'];
if (($createdPlan['body']['data']['locationIds'] ?? null) !== [$locationId]) {
    throw new RuntimeException('Die Standortverfügbarkeit des Tarifs wurde nicht gespeichert.');
}
assertStatus(request('PATCH', '/api/v1/plans/' . $planId, ['name' => 'Temporärer Tarif aktualisiert'], $adminToken), [200], 'Tarif bearbeiten');

$createdBandwidth = request('POST', '/api/v1/bandwidth-options', [
    'code' => 'TEST-BW-' . $suffix,
    'name' => 'Temporäre Bandbreite',
    'committedMbps' => 100,
    'burstMbps' => 1000,
    'monthlyPrice' => 49.0,
    'status' => 'draft',
], $adminToken);
assertStatus($createdBandwidth, [201], 'Bandbreite anlegen');
$bandwidthId = $createdBandwidth['body']['data']['id'];
assertStatus(request('PATCH', '/api/v1/bandwidth-options/' . $bandwidthId, ['monthlyPrice' => 59.0], $adminToken), [200], 'Bandbreite bearbeiten');

$createdCustomer = request('POST', '/api/v1/customers', [
    'customerNumber' => 'TEST-' . $suffix,
    'name' => 'Temporärer API-Kunde',
    'email' => strtolower($suffix) . '@example.invalid',
    'billingAddress' => ['street' => 'API-Allee 1', 'postalCode' => '10115', 'city' => 'Berlin', 'country' => 'DE'],
    'servicePlanId' => $planId,
    'bandwidthOptionId' => $bandwidthId,
], $salesToken);
assertStatus($createdCustomer, [201], 'Vertrieb legt Kunden an');
$customerId = $createdCustomer['body']['data']['id'];
assertStatus(request('PATCH', '/api/v1/customers/' . $customerId, ['phone' => '+49 30 123456'], $salesToken), [200], 'Vertrieb bearbeitet Kunden');
assertStatus(request('PATCH', '/api/v1/customers/' . $customerId, ['locationIds' => [$locationId]], $salesToken), [200], 'Vertrieb weist bestehenden Standort zu');

$createdLocation = request('POST', '/api/v1/locations', [
    'customerId' => $customerId,
    'code' => 'LOC-' . $suffix,
    'name' => 'Temporärer Standort',
    'address' => ['city' => 'Berlin', 'country' => 'DE'],
    'coordinates' => ['latitude' => 52.520008, 'longitude' => 13.404954],
], $adminToken);
assertStatus($createdLocation, [201], 'Standort anlegen');
$createdLocationId = $createdLocation['body']['data']['id'];
if (($createdLocation['body']['data']['coordinates']['latitude'] ?? null) !== 52.520008
    || ($createdLocation['body']['data']['coordinates']['longitude'] ?? null) !== 13.404954) {
    throw new RuntimeException('Die Kartenkoordinaten des Standorts wurden nicht gespeichert.');
}
assertStatus(request('PATCH', '/api/v1/plans/' . $planId, [
    'status' => 'active',
    'locationIds' => [$createdLocationId],
], $adminToken), [200], 'Tarif auf einen Standort begrenzen');
$restrictedOffers = request('GET', '/api/v1/public/offers');
assertStatus($restrictedOffers, [200], 'Standortbezogenen Tarif veröffentlichen');
$restrictedPlans = array_column($restrictedOffers['body']['data']['plans'] ?? [], null, 'id');
if (($restrictedPlans[$planId]['locationIds'] ?? null) !== [$createdLocationId]) {
    throw new RuntimeException('Die öffentliche API liefert die Tarif-Standortzuordnung nicht korrekt aus.');
}
assertStatus(request('POST', '/api/v1/public/inquiries', [
    'company' => 'Standortprüfung GmbH',
    'contactName' => 'Erika Standort',
    'email' => 'location-check-' . strtolower($suffix) . '@example.invalid',
    'locationId' => $locationId,
    'planId' => $planId,
    'bandwidthOptionId' => $publicBandwidthId,
    'rackUnits' => 10,
    'rackType' => 'units',
    'powerKw' => 2.0,
    'networkBillingModel' => $publicBillingModel,
    'contractMonths' => 12,
    'billingAddress' => $leadBillingAddress,
    'consent' => true,
], token: null), [422], 'Tarif am falschen Standort ablehnen');
assertStatus(request('PATCH', '/api/v1/locations/' . $createdLocationId, ['name' => 'Standort aktualisiert'], $adminToken), [200], 'Standort bearbeiten');
$assignedLocations = request('GET', '/api/v1/locations?customerId=' . $customerId . '&limit=100', token: $adminToken);
assertStatus($assignedLocations, [200], 'Mehrere Kundenstandorte laden');
if (count($assignedLocations['body']['data']['items'] ?? []) !== 2) {
    throw new RuntimeException('Dem Testkunden wurden nicht beide Standorte zugeordnet.');
}

$createdRack = request('POST', '/api/v1/racks', [
    'customerId' => $customerId,
    'locationId' => $createdLocationId,
    'code' => 'RACK-' . $suffix,
    'name' => 'Temporäres Rack',
    'totalUnits' => 44,
    'usedUnits' => 2,
    'powerLimitKw' => 5.6,
], $adminToken);
assertStatus($createdRack, [201], 'Rack anlegen');
$rackId = $createdRack['body']['data']['id'];
assertStatus(request('PATCH', '/api/v1/racks/' . $rackId, ['usedUnits' => 3], $adminToken), [200], 'Rack bearbeiten');

$createdDevice = request('POST', '/api/v1/devices', [
    'customerId' => $customerId,
    'locationId' => $createdLocationId,
    'rackId' => $rackId,
    'assetTag' => 'SRV-' . $suffix,
    'name' => 'Temporärer Server',
    'type' => 'server',
    'status' => 'online',
    'rackUnit' => 10,
    'heightUnits' => 2,
], $adminToken);
assertStatus($createdDevice, [201], 'Gerät anlegen');
$createdDeviceId = $createdDevice['body']['data']['id'];
assertStatus(request('PATCH', '/api/v1/devices/' . $createdDeviceId, ['rackUnit' => 12], $adminToken), [200], 'Gerät bearbeiten');

// Verwendete Elternressourcen dürfen nicht zu verwaisten Datensätzen führen.
assertStatus(request('DELETE', '/api/v1/plans/' . $planId, token: $adminToken), [409], 'Verwendeten Tarif schützen');
assertStatus(request('DELETE', '/api/v1/bandwidth-options/' . $bandwidthId, token: $adminToken), [409], 'Verwendete Bandbreite schützen');
assertStatus(request('DELETE', '/api/v1/racks/' . $rackId, token: $adminToken), [409], 'Belegtes Rack schützen');
assertStatus(request('DELETE', '/api/v1/locations/' . $createdLocationId, token: $adminToken), [409], 'Belegten Standort schützen');
assertStatus(request('DELETE', '/api/v1/customers/' . $customerId, token: $adminToken), [409], 'Kunden mit Infrastruktur schützen');
assertStatus(request('PATCH', '/api/v1/customers/' . $customerId, ['locationIds' => [$locationId]], $adminToken), [409], 'Standort mit Infrastruktur nicht entziehen');

assertStatus(request('DELETE', '/api/v1/devices/' . $createdDeviceId, token: $adminToken), [204], 'Gerät löschen');
assertStatus(request('DELETE', '/api/v1/racks/' . $rackId, token: $adminToken), [204], 'Rack löschen');
assertStatus(request('PATCH', '/api/v1/customers/' . $customerId, ['locationIds' => []], $adminToken), [200], 'Standortzuweisungen entfernen');
assertStatus(request('DELETE', '/api/v1/locations/' . $createdLocationId, token: $adminToken), [204], 'Standort löschen');
$planAfterLocationDelete = request('GET', '/api/v1/plans/' . $planId, token: $adminToken);
if (($planAfterLocationDelete['body']['data']['locationIds'] ?? null) !== []) {
    throw new RuntimeException('Der gelöschte Standort wurde nicht aus der Tarifverfügbarkeit entfernt.');
}
assertStatus(request('DELETE', '/api/v1/customers/' . $customerId, token: $salesToken), [204], 'Vertrieb löscht ungenutzten Kunden');
assertStatus(request('DELETE', '/api/v1/plans/' . $planId, token: $adminToken), [204], 'Tarif löschen');
assertStatus(request('DELETE', '/api/v1/bandwidth-options/' . $bandwidthId, token: $adminToken), [204], 'Bandbreite löschen');

fwrite(STDOUT, "Alle Smoke-Tests erfolgreich.\n");
