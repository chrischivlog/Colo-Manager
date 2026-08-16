<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\Client;

/** Gezielter Integrationstest für den ticketlosen Vertrag und seinen Nachtrag. */
function jsonRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
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
        'timeout' => 10,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => $raw === '' ? [] : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)];
}

function binaryRequest(string $path, string $token): array
{
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "Authorization: Bearer {$token}\r\n", 'ignore_errors' => true]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => (string) $raw];
}

function signedUpload(string $path, string $pdf): array
{
    $boundary = '----ManualContract' . bin2hex(random_bytes(8));
    $content = "--{$boundary}\r\nContent-Disposition: form-data; name=\"signedContract[]\"; filename=\"unterzeichnet.pdf\"\r\nContent-Type: application/pdf\r\n\r\n{$pdf}\r\n--{$boundary}--\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Accept: application/json\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($content),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents('http://localhost' . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)];
}

function assertCode(array $response, int $code, string $label): void
{
    if ($response['status'] !== $code) {
        throw new RuntimeException($label . ' fehlgeschlagen: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    }
    fwrite(STDOUT, "[OK] {$label} ({$code})\n");
}

$baseId = null;
$addendumId = null;
$database = (new Client((string) getenv('MONGODB_URI')))->selectDatabase((string) getenv('MONGODB_DATABASE'));

try {
    $login = jsonRequest('POST', '/api/v1/auth/login', ['email' => 'vertrieb@colomanager.local', 'password' => 'Staff123!']);
    assertCode($login, 200, 'Vertriebslogin');
    $token = (string) $login['body']['data']['accessToken'];
    $customers = jsonRequest('GET', '/api/v1/customers?limit=100', token: $token);
    assertCode($customers, 200, 'Kunden laden');
    $customer = array_values(array_filter($customers['body']['data']['items'], static fn (array $item): bool => !empty($item['billingAddress']['street'])))[0] ?? null;
    if ($customer === null) {
        throw new RuntimeException('Kein Testkunde mit Rechnungsanschrift vorhanden.');
    }
    $payload = [
        'customerId' => $customer['id'],
        'agreementType' => 'base',
        'title' => 'Ticketloser Integrationstest',
        'plannedStartDate' => date('Y-m-d'),
        'contractMonths' => 12,
        'noticeMonths' => 3,
        'renewalMonths' => 12,
        'billingInterval' => 'monthly',
        'lineItems' => [['type' => 'bandwidth', 'name' => '100 Mbit/s Basis', 'quantity' => 1, 'unit' => 'Monat', 'monthlyUnitPrice' => 99, 'oneTimeUnitPrice' => 0]],
    ];
    $created = jsonRequest('POST', '/api/v1/contracts', $payload, $token);
    assertCode($created, 201, 'Vertrag ohne Ticket anlegen');
    $baseId = (string) $created['body']['data']['id'];
    if (!empty($created['body']['data']['sourceLead'])) {
        throw new RuntimeException('Der manuelle Vertrag besitzt unerwartet eine Ticketreferenz.');
    }

    $preview = binaryRequest('/api/v1/contracts/' . $baseId . '/signature-document', $token);
    if ($preview['status'] !== 200 || !str_starts_with($preview['body'], '%PDF-1.4')) {
        throw new RuntimeException('Die ticketlose Vertragsvorschau ist ungültig.');
    }
    file_put_contents('/tmp/colo-manager-manual-contract.pdf', $preview['body']);
    fwrite(STDOUT, "[OK] Ticketloses Vertrags-PDF erzeugen (200)\n");

    $sent = jsonRequest('POST', '/api/v1/contracts/' . $baseId . '/send-for-signature', token: $token);
    assertCode($sent, 201, 'Ticketlosen Vertrag versenden');
    $number = (string) $sent['body']['data']['number'];
    $messages = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR)['messages'] ?? [];
    $mail = array_values(array_filter($messages, static fn (array $item): bool => ($item['Subject'] ?? null) === 'Ihr Vertrag ' . $number . ' zur Unterschrift'))[0] ?? null;
    if ($mail === null) {
        throw new RuntimeException('Die E-Mail zum manuellen Vertrag fehlt.');
    }
    $mailData = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/message/' . $mail['ID']), true, 512, JSON_THROW_ON_ERROR);
    $mailBody = html_entity_decode((string) ($mailData['Text'] ?? '') . ' ' . (string) ($mailData['HTML'] ?? ''));
    if (preg_match('/vertrag\.html\?token=([a-f0-9]{64})/', $mailBody, $match) !== 1) {
        throw new RuntimeException('Die Vertragsmail enthält keinen Signaturlink.');
    }
    $signed = signedUpload('/api/v1/public/contracts/' . $match[1] . '/signed-document', $preview['body']);
    assertCode($signed, 200, 'Ticketlosen Vertrag unterschrieben hochladen');
    $activated = jsonRequest('POST', '/api/v1/contracts/' . $baseId . '/activate', token: $token);
    assertCode($activated, 200, 'Ticketlosen Vertrag aktivieren');
    if (($activated['body']['data']['status'] ?? null) !== 'active') {
        throw new RuntimeException('Der Vertrag wurde nicht aktiv.');
    }

    $payload['agreementType'] = 'addendum';
    $payload['parentContractId'] = $baseId;
    $payload['title'] = 'Nachtrag Bandbreitenerhöhung';
    $payload['lineItems'] = [['type' => 'bandwidth', 'name' => '+900 Mbit/s Upgrade', 'quantity' => 1, 'unit' => 'Monat', 'monthlyUnitPrice' => 349, 'oneTimeUnitPrice' => 0]];
    $addendum = jsonRequest('POST', '/api/v1/contracts', $payload, $token);
    assertCode($addendum, 201, 'Nachtrag zum aktiven Vertrag anlegen');
    $addendumId = (string) $addendum['body']['data']['id'];
    if (($addendum['body']['data']['parentContractId'] ?? null) !== $baseId || ($addendum['body']['data']['agreementType'] ?? null) !== 'addendum') {
        throw new RuntimeException('Die Nachtragsbeziehung wurde nicht korrekt gespeichert.');
    }
    assertCode(jsonRequest('DELETE', '/api/v1/contracts/' . $addendumId, token: $token), 204, 'Nachtragsentwurf bereinigen');
    $addendumId = null;
} finally {
    // Der produktive API-Vertrag bleibt bewusst unveränderlich. Der Test räumt
    // deshalb ausschließlich seine exakt bekannten Integrationstest-Datensätze auf.
    foreach (array_filter([$baseId, $addendumId]) as $id) {
        $objectId = new ObjectId($id);
        $files = $database->selectCollection('contract_documents.files')->find(['metadata.contractId' => $objectId])->toArray();
        foreach ($files as $file) {
            $database->selectCollection('contract_documents.chunks')->deleteMany(['files_id' => $file['_id']]);
        }
        $database->selectCollection('contract_documents.files')->deleteMany(['metadata.contractId' => $objectId]);
        $database->selectCollection('contracts')->deleteOne(['_id' => $objectId]);
    }
}

fwrite(STDOUT, "Ticketloser Vertrags- und Nachtragsfluss erfolgreich.\n");
