<?php

declare(strict_types=1);

use ColoManager\Config;
use ColoManager\Database\MongoConnection;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\BandwidthOptionRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\IncidentHistoryRepository;
use ColoManager\Repository\IncidentRepository;
use ColoManager\Repository\InquiryRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\MaintenanceRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\UserRepository;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Liest Seed-Werte aus der Umgebung, ohne Produktionsgeheimnisse einzuchecken. */
function seedEnv(string $name, string $default): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
}

$config = Config::fromEnvironment();
$connection = new MongoConnection($config);
$database = $connection->database();

$users = new UserRepository($database);
$customers = new CustomerRepository($database);
$locations = new LocationRepository($database);
$devices = new DeviceRepository($database);
$racks = new RackRepository($database);
$plans = new PlanRepository($database);
$bandwidthOptions = new BandwidthOptionRepository($database);
$inquiries = new InquiryRepository($database);
$incidents = new IncidentRepository($database);
$incidentHistory = new IncidentHistoryRepository($database);
$maintenance = new MaintenanceRepository($database);

// Indizes sind Teil des Datenmodells und werden idempotent angelegt.
$users->ensureIndexes();
$customers->ensureIndexes();
$locations->ensureIndexes();
$devices->ensureIndexes();
$racks->ensureIndexes();
$plans->ensureIndexes();
$bandwidthOptions->ensureIndexes();
$inquiries->ensureIndexes();
$incidents->ensureIndexes();
$incidentHistory->ensureIndexes();
$maintenance->ensureIndexes();

$now = new UTCDateTime();

// Ein kleiner echter Katalog macht Adminbereich und Kundenportal direkt nutzbar.
$planCollection = $database->selectCollection('plans');
$seedPlans = [
    ['code' => 'COLO-START', 'name' => 'Colocation Start', 'rackUnits' => 10, 'powerKw' => 2.0, 'monthlyPrice' => 429.0, 'setupFee' => 199.0, 'description' => 'Der kompakte Einstieg für einzelne Systeme.', 'features' => ['10 HE Rackspace', 'A/B-Stromversorgung', '24/7 Zutritt']],
    ['code' => 'COLO-BUSINESS', 'name' => 'Colocation Business', 'rackUnits' => 22, 'powerKw' => 5.6, 'monthlyPrice' => 849.0, 'setupFee' => 299.0, 'description' => 'Mehr Platz und Leistung für produktive Plattformen.', 'features' => ['22 HE Rackspace', '5,6 kW Leistung', 'Remote Hands inklusive']],
    ['code' => 'COLO-DEDICATED', 'name' => 'Colocation Dedicated', 'rackUnits' => 44, 'powerKw' => 10.0, 'monthlyPrice' => 1499.0, 'setupFee' => 499.0, 'description' => 'Ein vollständiges Rack für wachsende Infrastruktur.', 'features' => ['44 HE Rackspace', '10 kW Leistung', 'Priorisierter Support']],
];
foreach ($seedPlans as $seedPlan) {
    $planCollection->updateOne(['code' => $seedPlan['code']], ['$setOnInsert' => $seedPlan + ['status' => 'active', 'createdAt' => $now, 'updatedAt' => $now, 'deletedAt' => null]], ['upsert' => true]);
}
$businessPlan = $planCollection->findOne(['code' => 'COLO-BUSINESS']);

$bandwidthCollection = $database->selectCollection('bandwidthOptions');
$seedBandwidths = [
    ['code' => 'BW-100', 'name' => 'Flat 100', 'committedMbps' => 100, 'burstMbps' => 100, 'monthlyPrice' => 89.0, 'billingModel' => 'flat', 'symmetric' => true, 'description' => '100 Mbit/s symmetrisch ohne Volumenlimit.'],
    ['code' => 'BW-200', 'name' => 'Flat 200', 'committedMbps' => 200, 'burstMbps' => 200, 'monthlyPrice' => 139.0, 'billingModel' => 'flat', 'symmetric' => true, 'description' => '200 Mbit/s symmetrisch ohne Volumenlimit.'],
    ['code' => 'BW-VOL-100', 'name' => 'Volume 100', 'committedMbps' => 100, 'burstMbps' => 100, 'monthlyPrice' => 59.0, 'billingModel' => 'volume', 'symmetric' => true, 'includedTransferTb' => 5.0, 'overagePricePerTb' => 12.0, 'description' => '100 Mbit/s symmetrisch mit 5 TB Inklusivvolumen.'],
    ['code' => 'BW-VOL-200', 'name' => 'Volume 200', 'committedMbps' => 200, 'burstMbps' => 200, 'monthlyPrice' => 99.0, 'billingModel' => 'volume', 'symmetric' => true, 'includedTransferTb' => 10.0, 'overagePricePerTb' => 12.0, 'description' => '200 Mbit/s symmetrisch mit 10 TB Inklusivvolumen.'],
    ['code' => 'BW-1000', 'name' => 'Flat 1000', 'committedMbps' => 1000, 'burstMbps' => 1000, 'monthlyPrice' => 249.0, 'billingModel' => 'flat', 'symmetric' => true, 'description' => '1 Gbit/s symmetrisch ohne Volumenlimit.'],
    ['code' => 'BW-10000', 'name' => 'Datacenter 10G', 'committedMbps' => 10000, 'burstMbps' => 10000, 'monthlyPrice' => 899.0, 'billingModel' => 'flat', 'symmetric' => true, 'description' => 'Dedizierte symmetrische 10-Gbit/s-Anbindung.'],
];
foreach ($seedBandwidths as $seedBandwidth) {
    $bandwidthCollection->updateOne(['code' => $seedBandwidth['code']], ['$setOnInsert' => $seedBandwidth + ['status' => 'active', 'createdAt' => $now, 'updatedAt' => $now, 'deletedAt' => null]], ['upsert' => true]);
}
// Altbestände erhalten die neuen fachlichen Felder, ohne individuell gepflegte
// Preise oder Beschreibungen beim erneuten Seed-Lauf zu überschreiben.
$bandwidthCollection->updateMany(
    ['billingModel' => ['$exists' => false]],
    ['$set' => ['billingModel' => 'flat', 'symmetric' => true]],
);
$businessBandwidth = $bandwidthCollection->findOne(['code' => 'BW-1000']);

$customerCollection = $database->selectCollection('customers');
$customerCollection->updateOne(
    ['customerNumber' => 'CM-DEMO-001'],
    [
        '$setOnInsert' => [
            'name' => 'Schneider IT GmbH',
            'email' => 'kontakt@schneider-it.example',
            'phone' => '+49 711 5550100',
            'status' => 'active',
            'billingAddress' => [
                'street' => 'Musterstraße 12',
                'postalCode' => '70173',
                'city' => 'Stuttgart',
                'country' => 'DE',
            ],
            'contactPerson' => ['name' => 'Markus Schneider', 'email' => 'demo@colomanager.local'],
            'servicePlanId' => $businessPlan['_id'],
            'bandwidthOptionId' => $businessBandwidth['_id'],
            'contractStart' => '2026-01-01',
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ],
    ],
    ['upsert' => true],
);
$customer = $customerCollection->findOne(['customerNumber' => 'CM-DEMO-001']);
$customerId = $customer['_id'];

// Auch eine bereits vorhandene Demo-Installation erhält den aktuellen Tarif,
// ohne dass dafür der persistente Docker-Datenträger gelöscht werden muss.
$customerCollection->updateOne(
    ['_id' => $customerId],
    ['$set' => [
        'servicePlanId' => $businessPlan['_id'],
        'bandwidthOptionId' => $businessBandwidth['_id'],
        'contractStart' => $customer['contractStart'] ?? '2026-01-01',
        'updatedAt' => $now,
    ]],
);

$userCollection = $database->selectCollection('users');
$seedUsers = [
    [
        'email' => strtolower(seedEnv('SEED_ADMIN_EMAIL', 'admin@colomanager.local')),
        'name' => 'Platform Administrator',
        'password' => seedEnv('SEED_ADMIN_PASSWORD', 'ChangeMe123!'),
        'role' => 'platform_admin',
        'customerId' => null,
    ],
    [
        'email' => strtolower(seedEnv('SEED_CUSTOMER_EMAIL', 'demo@colomanager.local')),
        'name' => 'Markus Schneider',
        'password' => seedEnv('SEED_CUSTOMER_PASSWORD', 'Demo123!'),
        'role' => 'customer_admin',
        'customerId' => $customerId,
    ],
    [
        'email' => strtolower(seedEnv('SEED_TECHNICIAN_EMAIL', 'technik@colomanager.local')),
        'name' => 'Nina Technik',
        'password' => seedEnv('SEED_TECHNICIAN_PASSWORD', 'Staff123!'),
        'role' => 'datacenter_staff',
        'department' => 'Technik',
        'customerId' => null,
    ],
    [
        'email' => strtolower(seedEnv('SEED_SALES_EMAIL', 'vertrieb@colomanager.local')),
        'name' => 'Daniel Vertrieb',
        'password' => seedEnv('SEED_SALES_PASSWORD', 'Staff123!'),
        'role' => 'datacenter_staff',
        'department' => 'Vertrieb',
        'customerId' => null,
    ],
];

foreach ($seedUsers as $seedUser) {
    // $setOnInsert lässt bestehende Passwörter bei erneutem Seeding unverändert.
    $userCollection->updateOne(
        ['email' => $seedUser['email']],
        ['$setOnInsert' => [
            'name' => $seedUser['name'],
            'email' => $seedUser['email'],
            'passwordHash' => password_hash($seedUser['password'], PASSWORD_ARGON2ID),
            'role' => $seedUser['role'],
            'authSource' => 'local',
            'department' => $seedUser['department'] ?? null,
            'customerId' => $seedUser['customerId'],
            'active' => true,
            'createdAt' => $now,
            'updatedAt' => $now,
            'deletedAt' => null,
        ]],
        ['upsert' => true],
    );
}

// Der Demo-Kunde zeigt im Portal direkt beide festen Ansprechpartner. Bei
// bestehenden Installationen ergänzt ein erneuter Seed-Lauf nur fehlende Werte.
$seedTechnician = $userCollection->findOne(['email' => strtolower(seedEnv('SEED_TECHNICIAN_EMAIL', 'technik@colomanager.local'))]);
$seedSales = $userCollection->findOne(['email' => strtolower(seedEnv('SEED_SALES_EMAIL', 'vertrieb@colomanager.local'))]);
$contactDefaults = [];
if ($seedTechnician !== null) {
    $contactDefaults['assignedTechnicianUserId'] = $seedTechnician['_id'];
}
if ($seedSales !== null) {
    $contactDefaults['assignedSalesUserId'] = $seedSales['_id'];
}
if ($contactDefaults !== []) {
    $customerCollection->updateOne(['_id' => $customerId], ['$set' => $contactDefaults + ['updatedAt' => $now]]);
}

$locationCollection = $database->selectCollection('locations');
$locationCollection->updateOne(
    ['customerId' => $customerId, 'code' => 'DE-01'],
    ['$setOnInsert' => [
        'customerId' => $customerId,
        'code' => 'DE-01',
        'name' => 'Datacenter Standort DE-01',
        'address' => [
            'street' => 'Datacenter Allee 1',
            'postalCode' => '60314',
            'city' => 'Frankfurt am Main',
            'country' => 'DE',
        ],
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
        'createdAt' => $now,
        'updatedAt' => $now,
        'deletedAt' => null,
    ]],
    ['upsert' => true],
);
$location = $locationCollection->findOne(['customerId' => $customerId, 'code' => 'DE-01']);
$locationId = $location['_id'];

// Migration auf die Viele-zu-viele-Zuordnung: Jeder bisher über customerId
// geführte Standort wird einmalig in customers.locationIds übernommen. Die
// alte customerId bleibt als Herkunftsinformation erhalten.
foreach ($locationCollection->find(['customerId' => ['$ne' => null], 'deletedAt' => null]) as $existingLocation) {
    $customerCollection->updateOne(
        ['_id' => $existingLocation['customerId'], 'deletedAt' => null],
        [
            '$addToSet' => ['locationIds' => $existingLocation['_id']],
            '$set' => ['updatedAt' => $now],
        ],
    );
}

$rackCollection = $database->selectCollection('racks');
foreach ([
    ['code' => 'DC1-A-14', 'name' => 'Rack DC1-A-14', 'room' => 'Datensaal A', 'row' => 'Reihe 03', 'totalUnits' => 44, 'usedUnits' => 38, 'powerLimitKw' => 5.6],
    ['code' => 'DC1-B-07', 'name' => 'Rack DC1-B-07', 'room' => 'Datensaal B', 'row' => 'Reihe 01', 'totalUnits' => 44, 'usedUnits' => 26, 'powerLimitKw' => 5.6],
] as $seedRack) {
    $rackCollection->updateOne(
        ['customerId' => $customerId, 'code' => $seedRack['code']],
        ['$setOnInsert' => $seedRack + ['customerId' => $customerId, 'locationId' => $locationId, 'status' => 'active', 'createdAt' => $now, 'updatedAt' => $now, 'deletedAt' => null]],
        ['upsert' => true],
    );
}
$demoRack = $rackCollection->findOne(['customerId' => $customerId, 'code' => 'DC1-A-14']);

$deviceCollection = $database->selectCollection('devices');
$deviceCollection->updateOne(
    ['customerId' => $customerId, 'assetTag' => 'SRV-0001'],
    ['$setOnInsert' => [
        'customerId' => $customerId,
        'locationId' => $locationId,
        'assetTag' => 'SRV-0001',
        'name' => 'Production Hypervisor 01',
        'type' => 'server',
        'status' => 'online',
        'rackId' => $demoRack['_id'],
        'rack' => 'DC1-A-14',
        'rackUnit' => 18,
        'heightUnits' => 2,
        'manufacturer' => 'Dell',
        'model' => 'PowerEdge R760',
        'serialNumber' => 'DEMO-SERIAL-001',
        'managementIp' => '10.20.0.11',
        'powerWatts' => 420,
        'createdAt' => $now,
        'updatedAt' => $now,
        'deletedAt' => null,
    ]],
    ['upsert' => true],
);

// Bestehende Demo-Geräte werden ebenfalls auf die echte Rack-Ressource migriert.
$deviceCollection->updateOne(
    ['customerId' => $customerId, 'assetTag' => 'SRV-0001'],
    ['$set' => ['rackId' => $demoRack['_id'], 'updatedAt' => $now]],
);

// ========================================================================
// EPIC E04: Demo-Daten für Störungen und Wartungen
// ========================================================================

// Relative Zeitpunkte halten die öffentliche Statusseite auch Monate nach dem
// ersten Setup aussagekräftig. UTCDateTime erhält dabei ein DateTime-Objekt –
// niemals einen ISO-String, der vom MongoDB-Treiber nicht akzeptiert wird.
$resolvedStart = new UTCDateTime((new DateTimeImmutable('-12 days'))->setTime(8, 0));
$resolvedEnd = new UTCDateTime((new DateTimeImmutable('-12 days'))->setTime(10, 45));
$plannedStart = new UTCDateTime((new DateTimeImmutable('+14 days'))->setTime(2, 0));
$plannedEnd = new UTCDateTime((new DateTimeImmutable('+14 days'))->setTime(5, 0));

// Eine behobene, öffentliche Demo-Störung erscheint nur in der Chronologie und
// beeinflusst den aktuellen Systemstatus nicht mehr.
$incidentCollection = $database->selectCollection('incidents');
$incidentCollection->updateOne(
    ['title' => 'Netzwerkstörung Datensaal A'],
    ['$set' => [
        'title' => 'Netzwerkstörung Datensaal A',
        'description' => 'Eine fehlerhafte Netzwerkkomponente führte zeitweise zu erhöhter Paketlaufzeit. Die Komponente wurde ersetzt.',
        'status' => 'behoben',
        'priority' => 'high',
        'critical' => false,
        'isPublic' => true,
        'affectsAllCustomers' => true,
        'startAt' => $resolvedStart,
        'endAt' => $resolvedEnd,
        'infrastructure' => [
            'locationId' => $location['_id'],
            'rackId' => null,
        ],
        'customerIds' => [],
        'createdBy' => 'system',
        'updatedBy' => 'system',
        'createdAt' => $resolvedStart,
        'updatedAt' => $resolvedEnd,
        'deletedAt' => null,
    ]],
    ['upsert' => true],
);

$incident = $incidentCollection->findOne(['title' => 'Netzwerkstörung Datensaal A']);
$incidentId = $incident['_id'];

// Die Historie wird mit stabilen Statuspaaren idempotent ergänzt.
$historyCollection = $database->selectCollection('incident_history');
$historyEntries = [
    [
        'incidentId' => $incidentId,
        'oldStatus' => null,
        'newStatus' => 'offen',
        'comment' => 'Störung gemeldet durch Monitoring-System',
        'createdBy' => 'system',
        'createdAt' => $resolvedStart,
    ],
    [
        'incidentId' => $incidentId,
        'oldStatus' => 'offen',
        'newStatus' => 'in_untersuchung',
        'comment' => 'Netzwerkteam beginnt mit der Analyse',
        'createdBy' => 'admin@colomanager.local',
        'createdAt' => $resolvedStart,
    ],
    [
        'incidentId' => $incidentId,
        'oldStatus' => 'in_untersuchung',
        'newStatus' => 'in_bearbeitung',
        'comment' => 'Defekter Switch identifiziert, Austausch wird vorbereitet',
        'createdBy' => 'admin@colomanager.local',
        'createdAt' => $resolvedEnd,
    ],
    [
        'incidentId' => $incidentId,
        'oldStatus' => 'in_bearbeitung',
        'newStatus' => 'behoben',
        'comment' => 'Switch ausgetauscht, alle Verbindungen wiederhergestellt',
        'createdBy' => 'admin@colomanager.local',
        'createdAt' => $resolvedEnd,
    ],
];

foreach ($historyEntries as $entry) {
    $historyCollection->updateOne(
        [
            'incidentId' => $entry['incidentId'],
            'oldStatus' => $entry['oldStatus'],
            'newStatus' => $entry['newStatus'],
        ],
        ['$setOnInsert' => $entry],
        ['upsert' => true],
    );
}

// Eine bevorstehende Wartung demonstriert öffentliche und kundenseitige Anzeige,
// ohne einen laufenden Ausfall vorzutäuschen.
$maintenanceCollection = $database->selectCollection('maintenance');
$maintenanceCollection->updateOne(
    ['title' => 'Geplante Stromversorgungswartung'],
    ['$set' => [
        'title' => 'Geplante Stromversorgungswartung',
        'description' => 'Prüfung der redundanten Strompfade und planmäßiger Funktionstest der USV-Anlage.',
        'status' => 'geplant',
        'plannedStart' => $plannedStart,
        'plannedEnd' => $plannedEnd,
        'impact' => 'Die redundante Versorgung bleibt bestehen; ein Ausfall ist nicht vorgesehen.',
        'isPublic' => true,
        'affectsAllCustomers' => true,
        'infrastructure' => [
            'locationId' => $location['_id'],
            'rackId' => null,
        ],
        'customerIds' => [],
        'createdBy' => 'admin@colomanager.local',
        'updatedBy' => 'admin@colomanager.local',
        'createdAt' => $now,
        'updatedAt' => $now,
        'deletedAt' => null,
    ]],
    ['upsert' => true],
);

fwrite(STDOUT, "Seed abgeschlossen.\n");
fwrite(STDOUT, sprintf("Admin: %s\n", $seedUsers[0]['email']));
fwrite(STDOUT, sprintf("Kunden-Admin: %s\n", $seedUsers[1]['email']));
fwrite(STDOUT, sprintf("Technik: %s\n", $seedUsers[2]['email']));
fwrite(STDOUT, sprintf("Vertrieb: %s\n", $seedUsers[3]['email']));
fwrite(STDOUT, "Demo-Störung und Wartung wurden angelegt.\n");
