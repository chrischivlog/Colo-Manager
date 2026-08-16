# COLO MANAGER API

OOP-Backend für das Colocation-Portal, umgesetzt mit PHP 8.4, MongoDB 8, JWT-Authentifizierung und einem konfigurierbaren SMTP-Mailer. Das API läuft vollständig in Docker und ist vom vorhandenen Frontend getrennt.

## Architektur

- `Controller`: Übersetzt HTTP-Anfragen und -Antworten.
- `Service`: Enthält Geschäftslogik, Validierung und Berechtigungen.
- `Repository`: Kapselt MongoDB-Abfragen.
- `Auth`: Erstellt und prüft JWTs sowie Rollen.
- `Http`: Kleiner, frameworkfreier Router und einheitliche JSON-Fehler.
- `Support`: Wiederverwendbare Validierung und BSON-Serialisierung.
- `Mail`: SMTP-Transport, sichere HTML-/Textvorlagen und fachliche Benachrichtigungen.

Die Mandantentrennung basiert auf der `customerId` im signierten JWT. Ein Kundenbenutzer kann dadurch ausschließlich Standorte und Geräte seines eigenen Unternehmens lesen oder bearbeiten.

## Start

```bash
cp .env.example .env
docker compose up --build -d
docker compose exec api php bin/seed.php
```

Die API ist danach unter `http://localhost:8080/api/v1` erreichbar.

Die Anmeldung ist unter `http://localhost:3000/login.html` erreichbar. Nach einem erfolgreichen Login werden JWT und letzte Browseraktivität im `localStorage` gespeichert und dadurch von allen Tabs derselben Origin gemeinsam verwendet. Zusätzlich ist jedes JWT über eine zufällige Sitzungs-ID an eine widerrufbare MongoDB-Sitzung gekoppelt. Nach standardmäßig 300 Sekunden Inaktivität melden Frontend und API den Benutzer verbindlich ab; ein sichtbarer Countdown warnt in der letzten Minute. `SESSION_IDLE_TTL` konfiguriert dieses Fenster, während `JWT_TTL` weiterhin die absolute Obergrenze bildet. Kunden werden zum Portal, Plattform-Administratoren und Datacenter-Mitarbeiter automatisch zum Mitarbeiter-Panel unter `http://localhost:3000/admin` weitergeleitet.

Unter `http://localhost:3000/konto` verwalten alle Rollen ihre eigene Anmeldeadresse, ihr Passwort und Authenticator-2FA. E-Mail- und Passwortänderungen verlangen erneut das aktuelle Passwort; bei bereits aktivierter 2FA zusätzlich einen gültigen TOTP-Code. Authenticator-Secrets werden mit einem aus `JWT_SECRET` abgeleiteten Schlüssel verschlüsselt in MongoDB gespeichert und nie in normalen Kontoantworten ausgegeben. Nach einer Passwortänderung werden alle anderen Sitzungen des Benutzers widerrufen. Über „Passwort vergessen?“ kann weiterhin ein 30 Minuten gültiger Einmal-Link angefordert werden. Die Anfrage antwortet für bekannte und unbekannte E-Mail-Adressen identisch; MongoDB speichert ausschließlich den SHA-256-Hash des Tokens. Das bestehende Passwort bleibt bis zur erfolgreichen Neuvergabe gültig.

Die öffentliche Angebots- und Anfrageseite ist ohne Anmeldung unter `http://localhost:3000/angebote` erreichbar. Sie lädt ausschließlich aktive Tarife und Bandbreiten aus dem API-Katalog und filtert Tarife nach dem ausgewählten Datacenter-Standort.
Die Tarifkacheln bilden den im Adminbereich hinterlegten Rackspace ab. Nach Auswahl eines Standorts und Tarifs fragt der Konfigurator Bandbreitenmodell, Bandbreite, Leistungsaufnahme, Vertragslaufzeit, Kontaktdaten und die vollständige Rechnungsanschrift ab. Nach dem Speichern entstehen ein Vertriebsdatensatz und ein verknüpftes `lead`-Ticket mit gemeinsamer Ticketnummer. Die Adresse wird bei der Kundenerstellung vorausgefüllt und beim Zuordnen unveränderlich in den Vertragssnapshot übernommen. Ein Vertrag ohne vollständige Rechnungsanschrift kann nicht zur Unterschrift versendet werden. Das Mailmodul versendet automatisch eine Eingangsbestätigung mit der vollständigen Konfiguration an den Interessenten. Im lokalen Docker-Setup ist sie in Mailpit unter `http://localhost:8025` sichtbar.

Angemeldete Kunden verwalten ihre normalen Supportanfragen unter `http://localhost:3000/tickets`. Der Nachrichtenverlauf unterstützt formatierten Text und bis zu fünf JPG-, PNG-, GIF-, WebP- oder PDF-Dateien pro Nachricht. Jede Datei ist auf 10 MB begrenzt, wird in MongoDB GridFS gespeichert und nur nach erfolgreicher Ticketberechtigungsprüfung ausgeliefert.

Lead-Tickets der exklusiven Kategorie `lead` („Lead-Anfrage“) enthalten im Mitarbeiter-Panel einen geführten, wiederholbaren Vertriebsprozess. Nach der Kontaktaufnahme pflegt der Vertrieb einen strukturierten Angebotsentwurf mit Produkten, Mengen, Preisen und Laufzeit. Die öffentliche Seite unter `/angebot.html?token=…` erlaubt pro Angebotsrunde eine einmalige Annahme oder Ablehnung. Bei Annahme entsteht idempotent ein eigenständiger Vertragsentwurf. Nach Kundenzuordnung ergänzt der Vertrieb die editierbaren Vertragsbedingungen, prüft das generierte PDF und versendet einen 30 Tage gültigen Signaturlink. Der Anfragende lädt den Vertrag unter `/vertrag.html?token=…` herunter und gibt genau eine unterschriebene PDF bis 10 MB zurück. Anschließend übergibt der Vertrieb das Lead-Ticket an einen Techniker. Dieser plant Datum, Uhrzeit, Zeitzone und Dauer des Onboardings; der Kunde erhält eine gebrandete Terminmail mit `.ics`-Kalendereinladung. Der Dienst `onboarding-reminder` prüft minütlich fällige Termine und erinnert den zugewiesenen Techniker am Termintag genau einmal per Ticketmail. Danach dokumentiert die Technik Planung und Bereitstellung intern und versendet eine 72 Stunden gültige, einmalige Einladung unter `/konto-aktivieren.html?token=…`. Erst die erfolgreiche Passwortvergabe aktiviert beziehungsweise terminiert den Vertrag, übernimmt Tarif und Bandbreite auf den Kunden und schließt das interne Lead-Ticket. In MongoDB liegen ausschließlich SHA-256-Hashes der öffentlichen Tokens; API-Antworten geben auch diese Hashes nicht aus.

Verträge können zusätzlich vollständig ohne Ticket angelegt, als PDF versendet, unterschrieben zurückgegeben und durch Vertrieb oder Plattform-Administration aktiviert werden. Laufende Vereinbarungen werden nicht überschrieben: zusätzliche Abrechnungspositionen entstehen als eigener Nachtrag (`agreementType=addendum`) mit `parentContractId`, Startdatum und eigenem Leistungs-/Preissnapshot. Vertragsfassungen liegen unabhängig vom Ticketsystem im GridFS-Bucket `contract_documents`; bei Lead-Verträgen bleibt parallel ein interner Nachweis im Ticketverlauf erhalten. Kunden sehen Basisverträge und Nachträge gemeinsam im Dashboard und im dedizierten Dokumentenarchiv unter `/dokumente`.

Verträge besitzen eine eigene Vertragsnummer, einen dauerhaft eingefrorenen Positions- und Preissnapshot, Laufzeitdaten, Gegenpartei sowie die Referenz auf Ticket, Angebotsrunde und Dokumente. Die Zustände `draft`, `pending_assignment`, `review`, `awaiting_signature`, `signed`, `onboarding`, `scheduled`, `active`, `terminated`, `expired` und `cancelled` trennen Entwurf, Signatur, Bereitstellung und Bestand. Unterschriebene Fassungen liegen geschützt in GridFS und erscheinen nach der Accountaktivierung im Kundenportal unter „Vertragsunterlagen“. Aktivierte oder beendete Verträge können nicht normal gelöscht beziehungsweise überschrieben werden. Vertragsbearbeitung erhalten Plattform-Administratoren und Mitarbeiter der Abteilung Vertrieb; der zugewiesene Techniker arbeitet über das Lead-Ticket.

Beim Erstellen eines Tickets wählt der Kunde verpflichtend eine der Kategorien `remote_hands`, `sales` oder `other`. Im Portal werden sie als „Remote Hands“, „Vertrieb / Tarif / Upgrade“ und „Sonstiges“ dargestellt. Öffentliche Rack- und Tarifanfragen erhalten automatisch die exklusive Kategorie `lead`; sie kann weder durch Kunden noch für interne Mitarbeitertickets vergeben oder nachträglich geändert werden. Der Lead-Prozess startet ausschließlich für diese Kategorie. Bestehende Lead-Tickets werden beim API-Start automatisch migriert; ältere normale Tickets ohne Kategorie werden kompatibel als `other` behandelt.

Im Mitarbeiter-Panel laufen normale Kundentickets und öffentliche Lead-Tickets in einer gemeinsamen Service-Desk-Queue zusammen. Techniker, Vertriebler und Plattform-Admins können Tickets suchen, filtern, einem internen Bearbeiter zuweisen, beantworten und über die Zustände `open`, `in_progress`, `waiting_customer` und `closed` steuern. Mitarbeiter der Abteilung Vertrieb erhalten zusätzlich die Vertrags- und Kundenverwaltung. Sie können Kunden anlegen, bearbeiten, Datacenter-Standorten zuordnen und ungenutzte Kundendatensätze löschen; Techniker bleiben von diesen Stammdaten ausgeschlossen. Infrastruktur- und Katalogpflege bleiben der Plattform-Administration vorbehalten.

Beim Schließen eines Tickets der Kategorie `remote_hands` ist ein interner Zeitnachweis verpflichtend. Er trennt Vor-Ort-Minuten und Verwaltungsminuten, berechnet die Gesamtzeit und kann den Einsatz mit `remoteHandsBillable: true` zur späteren Kundenabrechnung vormerken. Bearbeiter und Erfassungszeitpunkt werden revisionsnah mitgespeichert; der vollständige `remoteHandsWorkLog` bleibt aus allen Kundenantworten ausgeblendet.

Mitarbeiter können außerdem interne Tickets vom Typ `internal` erstellen. Diese lassen sich optional einem Kunden zuordnen und in die Kategorien `incident`, `remote_hands`, `sales` oder `other` einteilen. Ihre `visibility` bleibt dennoch `internal`: Sie erscheinen niemals im Kundenportal, sind über die Kunden-API nicht direkt abrufbar und lösen weder beim Anlegen noch bei internen Notizen eine Kundenmail aus. Die Kategorie `incident` ist ausschließlich für interne Tickets freigeschaltet.

Antworten interner Rollen sind bei allen Tickettypen standardmäßig interne Notizen (`internal: true`). Erst wenn beim Erstellen einer Nachricht ausdrücklich `sendToCustomer: true` übergeben wird, erscheint sie im Kundenverlauf und löst eine Benachrichtigungsmail aus. Interne Nachrichten, deren Anhänge, Aktivitätszähler, Zeitstempel und Sortierung werden serverseitig vollständig aus der Kundensicht entfernt. Bei Kundentickets verändern interne Notizen außerdem niemals den sichtbaren Ticketstatus.

Die öffentliche Systemstatus-Seite ist unter `http://localhost:3000/status` erreichbar und im Kundenportal ausschließlich im Footer sowie aus einem aktiven Warnbanner verlinkt. Sie zeigt ausschließlich Meldungen mit `isPublic: true`. Im Portal erscheinen nur aktive, kritische Meldungen, für die der angemeldete Kunde betroffen ist oder `affectsAllCustomers: true` gesetzt wurde. Frontend-Aufrufe laufen über den Nginx-Pfad `/api`, sodass keine API-Adresse im HTML fest verdrahtet ist.

Datacenter-Standorte werden als zentrale Ressourcen geführt und über `customers.locationIds` mehreren Kunden zugewiesen. Im Adminbereich ist diese Mehrfachzuweisung direkt beim Anlegen oder Bearbeiten eines Kunden möglich. Rack- und Geräteformulare zeigen anschließend ausschließlich die für den gewählten Kunden freigegebenen Standorte. Eine Zuweisung mit vorhandenen Racks oder Geräten kann nicht entfernt werden.

Kunden besitzen zusätzlich die Referenzen `assignedTechnicianUserId` und `assignedSalesUserId`. Die Mitarbeiterverwaltung prüft dabei strikt die Abteilungen Technik beziehungsweise Vertrieb. Legt ein Vertriebler einen Kunden an, wird er automatisch als kaufmännischer Ansprechpartner übernommen; bei der technischen Onboarding-Übergabe werden Techniker und bearbeitender Vertriebler dauerhaft am Kunden gespeichert. Eine Portal-Einladung wird blockiert, solange nicht beide Ansprechpartner hinterlegt sind. `GET /api/v1/customers/current` liefert unter `contacts.technician` und `contacts.sales` ausschließlich Name, geschäftliche E-Mail und Abteilung. Das Kunden-Dashboard konzentriert sich auf Vertrag, aktuellen Tarif, offene Tickets und diese festen Kontakte; Infrastruktur- und Auslastungskennzahlen bleiben aus der Startansicht entfernt.

Falls die Standardports lokal belegt sind, können sie beim Start überschrieben werden:

```bash
API_PORT=18080 FRONTEND_PORT=13000 docker compose up --build -d
```

Das lokale Mail-Testpostfach ist unter `http://localhost:8025` erreichbar. E-Mails werden im Entwicklungsmodus dort abgefangen und nicht an echte Empfänger zugestellt.

Vor einem produktiven Deployment müssen mindestens `JWT_SECRET` und die Seed-Passwörter in `.env` geändert werden.

## Demo-Zugänge

Nach dem Seed stehen diese lokalen Konten zur Verfügung:

| Rolle | E-Mail | Passwort |
|---|---|---|
| Plattform-Admin | `admin@colomanager.local` | `ChangeMe123!` |
| Technik-Mitarbeiter | `technik@colomanager.local` | `Staff123!` |
| Vertriebs-Mitarbeiter | `vertrieb@colomanager.local` | `Staff123!` |
| Kunden-Admin | `demo@colomanager.local` | `Demo123!` |

## Endpunkte

### Allgemein und Login

- `GET /api/v1/health`
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/session/heartbeat` (aktive Sitzung innerhalb des Inaktivitätsfensters bestätigen)
- `POST /api/v1/auth/logout` (serverseitige Sitzung widerrufen)
- `POST /api/v1/auth/password/forgot` (neutrale Reset-Anfrage, unabhängig davon, ob das Konto existiert)
- `GET /api/v1/auth/password/reset/{token}` (Einmal-Link und Ablaufzeit prüfen)
- `POST /api/v1/auth/password/reset/{token}` (neues Passwort festlegen)

### Eigenes Konto und Zwei-Faktor-Authentifizierung

- `GET /api/v1/account`
- `PATCH /api/v1/account/email` (`email`, `currentPassword`, bei aktiver 2FA zusätzlich `totpCode`)
- `PATCH /api/v1/account/password` (`currentPassword`, `newPassword`, `newPasswordConfirmation`, optional `totpCode`)
- `POST /api/v1/account/2fa/setup` (zehn Minuten gültige Einrichtung mit QR-Provisioning-URI)
- `POST /api/v1/account/2fa/confirm` (Einrichtung mit sechsstelliger `code` bestätigen)
- `DELETE /api/v1/account/2fa` (`currentPassword` und `totpCode`)

Externe Mitarbeiterkonten ändern E-Mail-Adresse, Kennwort und primäre MFA nicht im Colo Manager, sondern im angebundenen Verzeichnis. Eine zusätzlich bereits im Portal konfigurierte TOTP-Prüfung kann weiterhin beim Login verlangt werden; neue lokale 2FA-Setups sind für externe Konten gesperrt. „Passwort vergessen“ antwortet auch für externe Konten neutral und versendet keinen lokalen Reset-Link.

### Mitarbeiter und LDAP / Microsoft Active Directory

- `GET /api/v1/staff-users`
- `POST /api/v1/staff-users`
- `PATCH /api/v1/staff-users/{id}`
- `DELETE /api/v1/staff-users/{id}`
- `GET /api/v1/directory-configurations`
- `POST /api/v1/directory-configurations`
- `PATCH /api/v1/directory-configurations/{id}`
- `POST /api/v1/directory-configurations/{id}/test`
- `DELETE /api/v1/directory-configurations/{id}`

Alle Endpunkte sind ausschließlich für `platform_admin` freigeschaltet. Rollen, Abteilung, Aktivstatus und Ticketrechte bleiben lokale, explizite Zuordnungen; Gruppen aus LDAP oder AD verleihen nie automatisch Berechtigungen. Ein Verzeichniskonto wird vor der ersten Anmeldung bewusst als Mitarbeiter angelegt und seiner Verbindung sowie seinem LDAP-/AD-Benutzernamen zugeordnet. Das Verzeichniskennwort wird nur zum jeweiligen Bind verwendet und niemals gespeichert.

Verbindungen unterstützen generisches LDAP und klassisches Microsoft Active Directory über LDAP, StartTLS oder LDAPS. Für die Benutzersuche kann ein technischer Bind mit Suchbasis und Filter verwendet werden; alternativ ist eine direkte Benutzer-Bind-Vorlage wie `{username}@intern.example` möglich. Das technische Bind-Kennwort wird mit einem aus `JWT_SECRET` abgeleiteten Schlüssel verschlüsselt und weder in Listen- noch Detailantworten zurückgegeben. Für Produktion sollten StartTLS oder LDAPS, ein nur lesbares Servicekonto und ein intern vertrauenswürdiges CA-Zertifikat verwendet werden. Microsoft Entra ID / OpenID Connect ist von dieser LDAP-/AD-Anbindung getrennt und aktuell nicht Bestandteil dieser Implementierung.

### Plattform-Branding

- `GET /api/v1/public/branding` (Unternehmensbezeichnung, Farbe, Logo, Hero-Video und die konfigurierbaren Inhalte für Startseite/Kundenportal einschließlich FAQs)
- `GET /api/v1/public/branding/logo` (aktuell hinterlegte Bilddatei)
- `GET /api/v1/branding` (vollständige Konfiguration für Plattform-Admins)
- `POST /api/v1/branding` als `multipart/form-data` (`companyName`, `primaryColor`, optional `logo` als PNG/JPG/WebP bis 2 MB, `heroVideoUrl` als YouTube-Link oder direkte MP4-/WebM-/OGV-URL sowie `content` als JSON-Objekt mit `landing`, `portal` und `landing.faqs`)
- `DELETE /api/v1/branding/logo` (Logo entfernen, Name und Farbe beibehalten)

Branding und Seiteninhalte liegen zentral in MongoDB; das Logo wird binär in GridFS gespeichert, während für das optionale Hintergrundvideo nur die validierte externe URL hinterlegt wird. Textfelder und bis zu 20 FAQ-Einträge werden serverseitig anhand einer festen Struktur und mit Längenbegrenzungen validiert. Schreibzugriffe sind ausschließlich für `platform_admin` erlaubt. Alle öffentlichen und angemeldeten Frontendseiten beziehen dieselbe Konfiguration über die API.

### Öffentliche Angebote und Anfragen

- `GET /api/v1/public/offers` (aktive Tarife, Bandbreiten, Konfiguratorwerte und eine datensparsame öffentliche Standortliste aus Name, Code, Stadt, Land und optionalen Kartenkoordinaten)
- `POST /api/v1/public/inquiries` (Lead mit `locationId`, Tarif, Rackspace, Strom, Netzwerk, Laufzeit, Kontakt und `billingAddress`)
- `GET /api/v1/public/lead-offers/{token}` (öffentliche, token-geschützte Angebotsansicht)
- `GET /api/v1/public/lead-offers/{token}/document` (zugehöriges Angebots-PDF)
- `POST /api/v1/public/lead-offers/{token}/decision` (`accepted` oder `rejected`, einmalig)
- `GET /api/v1/public/contracts/{token}` (öffentliche Vertragsmetadaten über zeitlich begrenzten Link)
- `GET /api/v1/public/contracts/{token}/document` (zu unterschreibende Vertragsfassung)
- `POST /api/v1/public/contracts/{token}/signed-document` (eine unterschriebene PDF bis 10 MB)
- `GET /api/v1/public/account-invitations/{token}` (einmalige Portal-Einladung)
- `POST /api/v1/public/account-invitations/{token}/activate` (Passwort festlegen und Onboarding abschließen)
- `GET /api/v1/inquiries` (Plattform-Admin)
- `PATCH /api/v1/inquiries/{id}` (Plattform-Admin)
- `DELETE /api/v1/inquiries/{id}` (Plattform-Admin)

### Tickets

- `GET /api/v1/tickets` (Kunden: ausschließlich eigene Tickets; Mitarbeiter/Admin: Gesamtqueue einschließlich Leads; Filter `category`, `type`, `status`, `search`)
- `POST /api/v1/tickets` (normales Kundenticket als JSON oder `multipart/form-data`; Pflichtfelder `category` und `subject`)
- `GET /api/v1/tickets/assignees` (aktive Mitarbeiter und Admins für Zuweisungen)
- `GET /api/v1/tickets/customer-options` (reduzierte Kundenauswahl für interne Mitarbeiter)
- `POST /api/v1/tickets/internal` (internes Mitarbeiterticket, optional mit `customerId` und `assignedToUserId`)
- `GET /api/v1/tickets/{id}` (Ticket und vollständiger Nachrichtenverlauf)
- `POST /api/v1/tickets/{id}/messages` (Antwort als JSON oder `multipart/form-data`)
- `POST /api/v1/tickets/{id}/lead-process/contact` (Kontaktaufnahme eines Leads dokumentieren; Mitarbeiter/Admin)
- `POST /api/v1/tickets/{id}/lead-process/next-action` (`new_offer` oder `close` nach einer Ablehnung; Mitarbeiter/Admin)
- `GET /api/v1/tickets/{id}/lead-offer-draft` (gespeicherten oder vorbefüllten strukturierten Entwurf laden)
- `PUT /api/v1/tickets/{id}/lead-offer-draft` (Produkte, Preise und Vertragswerte speichern)
- `GET /api/v1/tickets/{id}/lead-offer-draft/document` (authentifizierte PDF-Vorschau)
- `POST /api/v1/tickets/{id}/lead-offer` (PDF aus dem gespeicherten Snapshot erzeugen und versenden)
- `POST /api/v1/tickets/{id}/lead-offer/resend` (unveränderte Angebotsrunde durch Vertrieb erneut senden und Entscheidungslink sicher erneuern)
- `POST /api/v1/tickets/{id}/lead-process/onboarding/handoff` (unterschriebenen Lead an Techniker übergeben)
- `PUT /api/v1/tickets/{id}/lead-process/onboarding/appointment` (Onboarding-Termin speichern oder aktualisieren, Kundenmail und iCalendar-Datei versenden)
- `POST /api/v1/tickets/{id}/lead-process/onboarding/invite` (Portal-Einladung durch den zugewiesenen Techniker senden)
- `GET /api/v1/tickets/{id}/attachments/{attachmentId}` (geschützter Dateiabruf)
- `PATCH /api/v1/tickets/{id}` (Kategorie, Status und `assignedToUserId` durch Mitarbeiter/Admin)
- `DELETE /api/v1/tickets/{id}` (Soft Delete durch Plattform-Admin)

Bei Multipart-Anfragen heißen die Textfelder `category`, `subject` und `bodyHtml`; Bilder und PDFs werden als `attachments[]` übertragen. Normale Tickets und Lead-Tickets verwenden dasselbe Datenmodell. `type: lead` und `category: lead` sind dabei fest gekoppelt; alle übrigen Tickettypen dürfen diese Kategorie nicht verwenden. Normale Mitarbeiterantworten an einen Lead dürfen nicht extern freigegeben werden – der Anfragende erhält ausschließlich die kontrollierte Angebotsmail.

### Verträge

- `GET /api/v1/contracts` (Vertragsübersicht mit Filtern `status`, `customerId`, `search`)
- `POST /api/v1/contracts` (manuellen Vertragsentwurf anlegen)
- `GET /api/v1/contracts/{id}` (Vertrags- und Herkunftssnapshot)
- `PATCH /api/v1/contracts/{id}` (Entwurf bearbeiten und einem Kunden zuordnen)
- `GET /api/v1/contracts/{id}/document` (angenommenes Angebots-PDF)
- `GET /api/v1/contracts/{id}/signature-document` (aktuelle Vertragsfassung als PDF-Vorschau)
- `POST /api/v1/contracts/{id}/send-for-signature` (Vertragsfassung erzeugen und Signaturlink senden)
- `GET /api/v1/contracts/{id}/signed-document` (eingereichte unterschriebene PDF intern laden)
- `DELETE /api/v1/contracts/{id}` (ausschließlich noch nicht aktivierte Entwürfe)
- `GET /api/v1/customer/contracts` (eigene unterschriebene und aktive Verträge)
- `GET /api/v1/customer/contracts/{id}/document` (eigene unterschriebene Vertragsfassung)

### Kunden

- `GET /api/v1/customers/current`
- `PATCH /api/v1/customers/current`
- `GET /api/v1/customers`
- `POST /api/v1/customers`
- `GET /api/v1/customers/{id}`
- `PATCH /api/v1/customers/{id}`
- `DELETE /api/v1/customers/{id}`

Bei `POST` und `PATCH` kann `locationIds` als Liste von Standort-IDs übergeben werden. Dadurch lassen sich Standortzuweisungen vollständig API-first verwalten.

### Standorte

- `GET /api/v1/locations`
- `POST /api/v1/locations`
- `GET /api/v1/locations/{id}`
- `PATCH /api/v1/locations/{id}`
- `DELETE /api/v1/locations/{id}`

Standorte unterstützen optional `coordinates` mit `latitude` (-90 bis 90) und `longitude` (-180 bis 180). Diese Position wird auf der öffentlichen Weltkarte verwendet; ohne Koordinaten greift das Frontend auf den Mittelpunkt des Landes zurück.

### Server und Geräte

- `GET /api/v1/devices`
- `POST /api/v1/devices`
- `GET /api/v1/devices/{id}`
- `PATCH /api/v1/devices/{id}`
- `DELETE /api/v1/devices/{id}`

### ISP- und IP-Zuweisungen

- `GET /api/v1/network-assignments/options` (aktive Kunden und deren freigegebene Standorte)
- `GET /api/v1/network-assignments/search?query=…` (globale Technik-/Adminsuche in CIDR, Gateway, DNS, Reverse DNS, ISP und Referenz)
- `GET /api/v1/network-assignments?customerId=…` (Netzwerkdaten eines Kunden; optional `locationId` und `status`)
- `POST /api/v1/network-assignments`
- `GET /api/v1/network-assignments/{id}`
- `PATCH /api/v1/network-assignments/{id}`
- `DELETE /api/v1/network-assignments/{id}`
- `GET /api/v1/customer/network-assignments` (ausschließlich eigene, schreibgeschützte Kundensicht)

Netzwerkzuweisungen enthalten ISP, Provider-Referenz, IPv4-/IPv6-CIDR, Gateway, bis zu vier DNS-Server, optionale VLAN-ID, Verwendung, Reverse DNS, Status und technische Hinweise. Sie müssen einem Standort zugeordnet sein, der dem gewählten Kunden bereits freigegeben wurde. Schreiben dürfen ausschließlich `platform_admin` und Mitarbeiter der Abteilung `Technik`; die Kunden-API filtert bereits in MongoDB fest auf die `customerId` der Sitzung.

### Racks

- `GET /api/v1/racks`
- `POST /api/v1/racks`
- `GET /api/v1/racks/{id}`
- `PATCH /api/v1/racks/{id}`
- `DELETE /api/v1/racks/{id}`

### Tarife

- `GET /api/v1/plans`
- `POST /api/v1/plans`
- `GET /api/v1/plans/{id}`
- `PATCH /api/v1/plans/{id}`
- `DELETE /api/v1/plans/{id}`

Tarife können mit `locationIds` auf bestimmte Datacenter-Standorte begrenzt werden. Eine leere Liste oder ein fehlendes Feld bedeutet „an allen aktiven Standorten verfügbar“. Die Einschränkung wird sowohl im öffentlichen Katalog als auch beim Anlegen einer Anfrage serverseitig durchgesetzt.

### Bandbreitenprofile

- `GET /api/v1/bandwidth-options`
- `POST /api/v1/bandwidth-options`
- `GET /api/v1/bandwidth-options/{id}`
- `PATCH /api/v1/bandwidth-options/{id}`
- `DELETE /api/v1/bandwidth-options/{id}`

### Störungen und Wartungen

#### Mitarbeiter-Endpunkte
- `GET /api/v1/incidents` (Liste aller Störungen)
- `POST /api/v1/incidents` (Störung erstellen)
- `GET /api/v1/incidents/{id}` (Störungsdetails)
- `PATCH /api/v1/incidents/{id}` (Störung aktualisieren)
- `DELETE /api/v1/incidents/{id}` (Störung löschen)
- `GET /api/v1/incidents/{id}/history` (Statushistorie)
- `GET /api/v1/maintenance` (Liste aller Wartungen)
- `POST /api/v1/maintenance` (Wartung erstellen)
- `GET /api/v1/maintenance/{id}` (Wartungsdetails)
- `PATCH /api/v1/maintenance/{id}` (Wartung aktualisieren)
- `DELETE /api/v1/maintenance/{id}` (Wartung löschen)

#### Kunden-Endpunkte (nur eigene Einträge)
- `GET /api/v1/customer/incidents` (Liste der eigenen Störungen)
- `GET /api/v1/customer/incidents/{id}` (Details eigener Störung)
- `GET /api/v1/customer/incidents/{id}/history` (Historie eigener Störung)
- `GET /api/v1/customer/maintenance` (Liste der eigenen Wartungen)
- `GET /api/v1/customer/maintenance/{id}` (Details eigener Wartung)

#### Öffentliche Status-Endpunkte (ohne Login)
- `GET /api/v1/public/status` (Gesamtstatus, aktive Meldungen, Wartungen und Chronologie)
- `GET /api/v1/public/status/system` (kompakter Gesamtstatus)
- `GET /api/v1/public/status/incidents` (aktive öffentliche Störungen)
- `GET /api/v1/public/status/maintenance` (aktive und bevorstehende öffentliche Wartungen)

Störungen und Wartungen werden ausschließlich von `platform_admin` angelegt, bearbeitet oder gelöscht. Kundenrollen verwenden die getrennten, rein lesenden `/customer/...`-Endpunkte.

## Login-Beispiel

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@colomanager.local","password":"Demo123!"}'
```

Geschützte Endpunkte erwarten anschließend den Header:

```text
Authorization: Bearer <accessToken>
```

## Rollen

- `platform_admin`: Zugriff auf alle Kunden und Ressourcen sowie Schreibzugriff auf Racks, Tarife, Bandbreiten und Netzwerkzuweisungen.
- `datacenter_staff` mit Abteilung `Vertrieb`: Zugriff auf Verträge sowie vollständige Kundenstammdatenverwaltung; Standorte und aktiver Produktkatalog sind für Zuordnungen lesbar.
- `datacenter_staff` mit Abteilung `Technik`: gemeinsamer Service Desk, Rackbetrieb und Verwaltung der kundengebundenen ISP-/IP-Zuweisungen.
- `datacenter_staff` anderer Abteilungen: gemeinsamer Service Desk ohne Zugriff auf Kundenstammdaten oder Vertragsverwaltung.
- `customer_admin`: Lesen und Bearbeiten der eigenen Kundenressourcen.
- `customer_user`: Ausschließlich lesender Zugriff auf eigene Ressourcen.

DELETE-Endpunkte verwenden Soft Deletes. Datensätze bleiben für spätere Audit- und Wiederherstellungsfunktionen in MongoDB erhalten.

Der Endpunkt `GET /customers/current` löst die beim Kunden hinterlegten Referenzen auf Tarif und Bandbreite als `subscription` auf. Das Kundenportal kann die aktuell gebuchten Leistungen dadurch ohne fest eingebaute Mockwerte anzeigen.

Alle im Adminbereich verwalteten Ressourcen folgen dem API-First-Prinzip: Anlegen, Bearbeiten und Löschen laufen ausschließlich über die REST-Endpunkte. DELETE bleibt ein Soft Delete. Die Service-Schicht verhindert außerdem ungültige Löschungen, solange eine Ressource noch verwendet wird, beispielsweise ein Tarif mit Kundenzuordnung, ein Rack mit Geräten oder ein Standort mit Racks.

## E-Mail-Modul

Der `NotificationMailService` stellt derzeit diese fachlichen Funktionen bereit:

- Passwort zurücksetzen
- Ticket erstellt
- Ticket aktualisiert
- Allgemeines System- oder Wartungsupdate
- Eingangsbestätigung für öffentliche Anfragen
- Individuelles Lead-Angebot mit Annahme- und Ablehnlink
- Vertrag mit persönlichem Download- und Rückuploadlink
- Interne Benachrichtigung beim Eingang der unterschriebenen Fassung
- Einmalige Einladung zur Aktivierung des Kundenportals

Jede Nachricht enthält eine HTML- und eine Textversion. Dynamische Inhalte werden vor der HTML-Ausgabe escaped. Andere Services müssen nur die passende Methode aufrufen und kennen keine SMTP-Details.

Beispiel:

```php
$notifications->sendTicketCreated(
    email: $user->email,
    name: $user->name,
    ticketNumber: 'CM-1842',
    ticketSubject: 'Zutrittsanmeldung',
    ticketUrl: 'https://portal.example/tickets/CM-1842',
);
```

Eine Testnachricht kann im laufenden Container versendet werden:

```bash
docker compose exec api php bin/test-mail.php
```

Für einen echten SMTP-Anbieter müssen in `.env` mindestens diese Werte gesetzt werden:

```dotenv
MAILER_DSN=smtp://username:password@smtp.example.com:587?encryption=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="COLO MANAGER"
MAIL_REPLY_TO=support@example.com
```

Sonderzeichen in Benutzername und Passwort der DSN müssen URL-kodiert werden.
