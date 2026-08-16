<?php

declare(strict_types=1);

namespace ColoManager;

use ColoManager\Auth\AuthMiddleware;
use ColoManager\Auth\JwtService;
use ColoManager\Controller\AuthController;
use ColoManager\Controller\AccountController;
use ColoManager\Controller\BrandingController;
use ColoManager\Controller\CatalogController;
use ColoManager\Controller\ContractController;
use ColoManager\Controller\CustomerController;
use ColoManager\Controller\CustomerIncidentController;
use ColoManager\Controller\CustomerMaintenanceController;
use ColoManager\Controller\DeviceController;
use ColoManager\Controller\DirectoryConfigurationController;
use ColoManager\Controller\IncidentController;
use ColoManager\Controller\LocationController;
use ColoManager\Controller\LeadOfferController;
use ColoManager\Controller\LeadFulfillmentController;
use ColoManager\Controller\MaintenanceController;
use ColoManager\Controller\NetworkAssignmentController;
use ColoManager\Controller\PasswordResetController;
use ColoManager\Controller\PublicOfferController;
use ColoManager\Controller\PublicStatusController;
use ColoManager\Controller\RackController;
use ColoManager\Controller\TicketController;
use ColoManager\Controller\StaffUserController;
use ColoManager\Database\MongoConnection;
use ColoManager\Event\IncidentMarkedCritical;
use ColoManager\Http\ApiException;
use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Http\Router;
use ColoManager\Mail\MailFactory;
use ColoManager\Repository\CustomerRepository;
use ColoManager\Repository\ContractRepository;
use ColoManager\Repository\ContractDocumentRepository;
use ColoManager\Repository\BandwidthOptionRepository;
use ColoManager\Repository\BrandingAssetRepository;
use ColoManager\Repository\BrandingRepository;
use ColoManager\Repository\DeviceRepository;
use ColoManager\Repository\DirectoryConfigurationRepository;
use ColoManager\Repository\IncidentHistoryRepository;
use ColoManager\Repository\IncidentRepository;
use ColoManager\Repository\InquiryRepository;
use ColoManager\Repository\LocationRepository;
use ColoManager\Repository\MaintenanceRepository;
use ColoManager\Repository\NetworkAssignmentRepository;
use ColoManager\Repository\PlanRepository;
use ColoManager\Repository\RackRepository;
use ColoManager\Repository\TicketAttachmentRepository;
use ColoManager\Repository\TicketMessageRepository;
use ColoManager\Repository\TicketRepository;
use ColoManager\Repository\UserRepository;
use ColoManager\Repository\SessionRepository;
use ColoManager\Security\TotpService;
use ColoManager\Security\DirectoryAuthenticator;
use ColoManager\Security\SecretCipher;
use ColoManager\Service\AccountService;
use ColoManager\Service\AuthService;
use ColoManager\Service\BrandingService;
use ColoManager\Service\CatalogService;
use ColoManager\Service\ContractService;
use ColoManager\Service\CustomerService;
use ColoManager\Service\DeviceService;
use ColoManager\Service\DirectoryConfigurationService;
use ColoManager\Service\EventDispatcherService;
use ColoManager\Service\IncidentService;
use ColoManager\Service\LocationService;
use ColoManager\Service\LeadOfferService;
use ColoManager\Service\LeadFulfillmentService;
use ColoManager\Service\MaintenanceService;
use ColoManager\Service\NetworkAssignmentService;
use ColoManager\Service\PasswordResetService;
use ColoManager\Service\PublicOfferService;
use ColoManager\Service\PublicStatusService;
use ColoManager\Service\RackService;
use ColoManager\Service\TicketService;
use ColoManager\Service\StaffUserService;
use ColoManager\Support\TicketHtmlSanitizer;
use ColoManager\Support\ContractPdfGenerator;
use ColoManager\Support\OfferPdfGenerator;
use ColoManager\Support\IcalendarGenerator;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

/**
 * Composition Root der Anwendung: Hier werden Abhängigkeiten verdrahtet,
 * Routen registriert und alle Fehler in ein einheitliches JSON-Format übersetzt.
 */
final class Application
{
    private readonly Config $config;
    private readonly MongoConnection $mongo;
    private readonly Router $router;
    private readonly AuthMiddleware $authMiddleware;

    public function __construct()
    {
        $this->config = Config::fromEnvironment();
        $this->mongo = new MongoConnection($this->config);
        $database = $this->mongo->database();

        $users = new UserRepository($database);
        $directoryConfigurations = new DirectoryConfigurationRepository($database);
        $customers = new CustomerRepository($database);
        $locations = new LocationRepository($database);
        $devices = new DeviceRepository($database);
        $racks = new RackRepository($database);
        $plans = new PlanRepository($database);
        $bandwidthOptions = new BandwidthOptionRepository($database);
        $branding = new BrandingRepository($database);
        $brandingAssets = new BrandingAssetRepository($database);
        $inquiries = new InquiryRepository($database);
        $incidents = new IncidentRepository($database);
        $incidentHistory = new IncidentHistoryRepository($database);
        $maintenance = new MaintenanceRepository($database);
        $networkAssignments = new NetworkAssignmentRepository($database);
        $tickets = new TicketRepository($database);
        $ticketMessages = new TicketMessageRepository($database);
        $ticketAttachments = new TicketAttachmentRepository($database);
        $contracts = new ContractRepository($database);
        $contractDocuments = new ContractDocumentRepository($database);
        $sessions = new SessionRepository($database);

        // Indizes für neue Collections sicherstellen
        $users->ensureIndexes();
        $directoryConfigurations->ensureIndexes();
        $incidents->ensureIndexes();
        $incidentHistory->ensureIndexes();
        $maintenance->ensureIndexes();
        $networkAssignments->ensureIndexes();
        $tickets->ensureIndexes();
        $ticketMessages->ensureIndexes();
        $contracts->ensureIndexes();
        $sessions->ensureIndexes();

        $jwt = new JwtService($this->config);
        $totp = new TotpService($this->config);
        $secretCipher = new SecretCipher($this->config);
        $directoryAuthenticator = new DirectoryAuthenticator($secretCipher);
        $this->authMiddleware = new AuthMiddleware($jwt, $sessions, $this->config);

        // Event Dispatcher (ohne Logger für jetzt)
        $eventDispatcher = new EventDispatcherService();

        $authController = new AuthController(new AuthService($users, $jwt, $sessions, $totp, $this->config, $directoryConfigurations, $directoryAuthenticator));
        $accountController = new AccountController(new AccountService($users, $sessions, $totp, $jwt, $this->config));
        $staffUserController = new StaffUserController(new StaffUserService($users, $directoryConfigurations, $sessions));
        $directoryConfigurationController = new DirectoryConfigurationController(new DirectoryConfigurationService(
            $directoryConfigurations,
            $users,
            $directoryAuthenticator,
            $secretCipher,
        ));
        $customerController = new CustomerController(new CustomerService($customers, $plans, $bandwidthOptions, $locations, $racks, $devices, $contracts, $users));
        $locationController = new LocationController(new LocationService($locations, $customers, $racks, $devices, $users, $plans));
        $deviceController = new DeviceController(new DeviceService($devices, $locations, $customers, $racks));
        $rackController = new RackController(new RackService($racks, $locations, $customers, $devices, $users));
        $networkAssignmentController = new NetworkAssignmentController(new NetworkAssignmentService($networkAssignments, $customers, $locations, $users));
        $catalogController = new CatalogController(new CatalogService($plans, $bandwidthOptions, $customers, $locations));
        $brandingService = new BrandingService($branding, $brandingAssets);
        $brandingController = new BrandingController($brandingService);
        // Pro Request wird ein aktueller Branding-Snapshot erzeugt. Änderungen
        // an Logo, Name oder Primärfarbe wirken damit sofort auf neue
        // Dokumente, E-Mails und den sichtbaren Absendernamen.
        $documentBranding = $brandingService->documentBranding($this->config->appUrl);
        $notifications = MailFactory::notifications($this->config, $documentBranding);
        $passwordResetController = new PasswordResetController(new PasswordResetService(
            $users,
            $notifications,
            $this->config->frontendUrl,
        ));
        $ticketService = new TicketService(
            $tickets,
            $ticketMessages,
            $ticketAttachments,
            $customers,
            $users,
            $racks,
            $devices,
            $notifications,
            new TicketHtmlSanitizer(),
            $this->config->frontendUrl,
        );
        $ticketController = new TicketController($ticketService);
        $contractService = new ContractService($contracts, $customers, $users, $contractDocuments, $ticketAttachments, $tickets, $ticketMessages);
        $contractController = new ContractController($contractService);
        $leadFulfillmentController = new LeadFulfillmentController(new LeadFulfillmentService(
            $contracts,
            $contractDocuments,
            $tickets,
            $ticketMessages,
            $ticketAttachments,
            $users,
            $customers,
            $contractService,
            new ContractPdfGenerator($documentBranding),
            new IcalendarGenerator(),
            $notifications,
            $this->config->frontendUrl,
        ));
        $leadOfferController = new LeadOfferController(new LeadOfferService(
            $tickets,
            $ticketMessages,
            $ticketAttachments,
            $inquiries,
            $users,
            $contractService,
            new OfferPdfGenerator($documentBranding),
            $notifications,
            $this->config->frontendUrl,
        ));
        $publicOfferController = new PublicOfferController(new PublicOfferService(
            $plans,
            $bandwidthOptions,
            $locations,
            $inquiries,
            $ticketService,
            $notifications,
            $this->config->frontendUrl,
        ));
        
        // Neue Controller für Störungen und Wartungen
        $incidentService = new IncidentService($incidents, $incidentHistory, $customers, $racks, $locations, $devices, $eventDispatcher);
        $incidentController = new IncidentController($incidentService);
        $customerIncidentController = new CustomerIncidentController($incidentService);
        
        $maintenanceService = new MaintenanceService($maintenance, $customers, $racks, $locations, $devices);
        $maintenanceController = new MaintenanceController($maintenanceService);
        $customerMaintenanceController = new CustomerMaintenanceController($maintenanceService);
        
        // Öffentliche Status-Seite
        $publicStatusService = new PublicStatusService($incidents, $maintenance);
        $publicStatusController = new PublicStatusController($publicStatusService);

        $this->router = new Router();
        $this->registerRoutes(
            $authController, 
            $customerController, 
            $locationController, 
            $deviceController, 
            $rackController, 
            $catalogController, 
            $publicOfferController,
            $publicStatusController,
            $incidentController,
            $customerIncidentController,
            $maintenanceController,
            $customerMaintenanceController,
            $ticketController,
            $leadOfferController,
            $contractController,
            $leadFulfillmentController,
            $passwordResetController,
            $accountController,
            $brandingController,
            $networkAssignmentController,
            $staffUserController,
            $directoryConfigurationController,
        );
    }

    /** Entry-Point für Apache; fängt auch fehlerhaftes JSON beim Request-Aufbau ab. */
    public function run(): never
    {
        try {
            $response = $this->handle(Request::fromGlobals());
        } catch (Throwable $exception) {
            $response = $this->errorResponse($exception);
        }

        $this->withCors($response)->send();
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                return Response::noContent();
            }

            $resolved = $this->router->resolve($request);
            $route = $resolved['route'];
            $request->setRouteParams($resolved['params']);

            if ($route->authenticated) {
                $this->authMiddleware->authenticate($request, $route->roles);
            }

            return ($route->handler)($request);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function registerRoutes(
        AuthController $auth,
        CustomerController $customers,
        LocationController $locations,
        DeviceController $devices,
        RackController $racks,
        CatalogController $catalog,
        PublicOfferController $publicOffers,
        PublicStatusController $publicStatus,
        IncidentController $incidents,
        CustomerIncidentController $customerIncidents,
        MaintenanceController $maintenance,
        CustomerMaintenanceController $customerMaintenance,
        TicketController $tickets,
        LeadOfferController $leadOffers,
        ContractController $contracts,
        LeadFulfillmentController $leadFulfillment,
        PasswordResetController $passwordReset,
        AccountController $account,
        BrandingController $branding,
        NetworkAssignmentController $networkAssignments,
        StaffUserController $staffUsers,
        DirectoryConfigurationController $directoryConfigurations,
    ): void {
        $this->router->add('GET', '/api/v1/health', fn (): Response => Response::json([
            'data' => [
                'status' => $this->mongo->ping() ? 'ok' : 'degraded',
                'service' => 'colo-manager-api',
                'timestamp' => gmdate(DATE_ATOM),
            ],
        ]));

        // ========================================================================
        // Öffentliche Status-Seite (keine Authentifizierung erforderlich)
        // ========================================================================
        $this->router->add('GET', '/api/v1/public/status', $publicStatus->index(...));
        $this->router->add('GET', '/api/v1/public/status/incidents', $publicStatus->incidents(...));
        $this->router->add('GET', '/api/v1/public/status/maintenance', $publicStatus->maintenance(...));
        $this->router->add('GET', '/api/v1/public/status/system', $publicStatus->status(...));

        // Das aktive White-Label-Branding muss bereits auf Login- und
        // Angebotsseiten verfügbar sein. Änderungen bleiben Admins vorbehalten.
        $this->router->add('GET', '/api/v1/public/branding', $branding->show(...));
        $this->router->add('GET', '/api/v1/public/branding/logo', $branding->logo(...));

        // Authentifizierung
        $this->router->add('POST', '/api/v1/auth/login', $auth->login(...));
        $this->router->add('GET', '/api/v1/auth/me', $auth->me(...), true);
        $this->router->add('POST', '/api/v1/auth/session/heartbeat', $auth->heartbeat(...), true);
        $this->router->add('POST', '/api/v1/auth/logout', $auth->logout(...), true);
        $this->router->add('POST', '/api/v1/auth/password/forgot', $passwordReset->request(...));
        $this->router->add('GET', '/api/v1/auth/password/reset/{token}', $passwordReset->show(...));
        $this->router->add('POST', '/api/v1/auth/password/reset/{token}', $passwordReset->reset(...));

        // Jeder angemeldete Benutzer verwaltet sein eigenes Konto. Sensible
        // Änderungen werden im Service erneut mit Passwort und optional 2FA
        // bestätigt; Rollenrechte sind hier absichtlich nicht erforderlich.
        $this->router->add('GET', '/api/v1/account', $account->show(...), true);
        $this->router->add('PATCH', '/api/v1/account/email', $account->changeEmail(...), true);
        $this->router->add('PATCH', '/api/v1/account/password', $account->changePassword(...), true);
        $this->router->add('POST', '/api/v1/account/2fa/setup', $account->startTwoFactorSetup(...), true);
        $this->router->add('POST', '/api/v1/account/2fa/confirm', $account->confirmTwoFactor(...), true);
        $this->router->add('DELETE', '/api/v1/account/2fa', $account->disableTwoFactor(...), true);
        $this->router->add('GET', '/api/v1/branding', $branding->show(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/branding', $branding->update(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/branding/logo', $branding->removeLogo(...), true, ['platform_admin']);

        // Interne Benutzerkonten und ihre Anmeldequellen werden ausschließlich
        // von Plattform-Administratoren verwaltet. LDAP-/AD-Kennwörter werden
        // weder über die API ausgegeben noch im lokalen Benutzerkonto gespeichert.
        $this->router->add('GET', '/api/v1/staff-users', $staffUsers->index(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/staff-users', $staffUsers->create(...), true, ['platform_admin']);
        $this->router->add('PATCH', '/api/v1/staff-users/{id}', $staffUsers->update(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/staff-users/{id}', $staffUsers->delete(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/directory-configurations', $directoryConfigurations->index(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/directory-configurations', $directoryConfigurations->create(...), true, ['platform_admin']);
        $this->router->add('PATCH', '/api/v1/directory-configurations/{id}', $directoryConfigurations->update(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/directory-configurations/{id}/test', $directoryConfigurations->test(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/directory-configurations/{id}', $directoryConfigurations->delete(...), true, ['platform_admin']);

        // ISP- und IP-Zuweisungen: Techniker und Plattform-Admins verwalten
        // die Daten; Kunden erhalten ausschließlich ihre eigene Lesesicht.
        $this->router->add('GET', '/api/v1/network-assignments/options', $networkAssignments->options(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/network-assignments/search', $networkAssignments->search(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/network-assignments', $networkAssignments->index(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/network-assignments', $networkAssignments->create(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/network-assignments/{id}', $networkAssignments->show(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('PATCH', '/api/v1/network-assignments/{id}', $networkAssignments->update(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('DELETE', '/api/v1/network-assignments/{id}', $networkAssignments->delete(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/customer/network-assignments', $networkAssignments->customerIndex(...), true, ['customer_admin']);

        // Öffentliche Angebotsseite: nur aktive Katalogdaten und das Erstellen
        // einer Anfrage sind ohne Login erlaubt.
        $this->router->add('GET', '/api/v1/public/offers', $publicOffers->index(...));
        $this->router->add('POST', '/api/v1/public/inquiries', $publicOffers->createInquiry(...));
        // Angebotslinks sind absichtlich öffentlich, aber nur über ein starkes,
        // zufälliges Einmal-Token erreichbar. Der Token selbst liegt nie in MongoDB.
        $this->router->add('GET', '/api/v1/public/lead-offers/{token}', $leadOffers->showPublic(...));
        $this->router->add('GET', '/api/v1/public/lead-offers/{token}/document', $leadOffers->document(...));
        $this->router->add('POST', '/api/v1/public/lead-offers/{token}/decision', $leadOffers->decide(...));
        // Der Vertrags- und Aktivierungsprozess bleibt vor dem ersten Login über
        // starke, zeitlich begrenzte Einmal-Links erreichbar.
        $this->router->add('GET', '/api/v1/public/contracts/{token}', $leadFulfillment->publicContract(...));
        $this->router->add('GET', '/api/v1/public/contracts/{token}/document', $leadFulfillment->publicContractDocument(...));
        $this->router->add('POST', '/api/v1/public/contracts/{token}/signed-document', $leadFulfillment->uploadSignedContract(...));
        $this->router->add('GET', '/api/v1/public/account-invitations/{token}', $leadFulfillment->publicInvitation(...));
        $this->router->add('POST', '/api/v1/public/account-invitations/{token}/activate', $leadFulfillment->activateAccount(...));
        $this->router->add('GET', '/api/v1/inquiries', $publicOffers->inquiries(...), true, ['platform_admin']);
        $this->router->add('PATCH', '/api/v1/inquiries/{id}', $publicOffers->updateInquiry(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/inquiries/{id}', $publicOffers->deleteInquiry(...), true, ['platform_admin']);

        // Einheitliches Ticketsystem: Kunden sehen ausschließlich ihre eigenen
        // Tickets. Interne Mitarbeiter und Plattform-Admins arbeiten über
        // dieselben Endpunkte in der Gesamtqueue einschließlich aller Leads.
        $this->router->add('GET', '/api/v1/tickets', $tickets->index(...), true);
        $this->router->add('POST', '/api/v1/tickets', $tickets->create(...), true, ['customer_admin']);
        $this->router->add('GET', '/api/v1/tickets/assignees', $tickets->assignees(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/tickets/customer-options', $tickets->customerOptions(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/internal', $tickets->createInternal(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/tickets/{id}', $tickets->show(...), true);
        $this->router->add('POST', '/api/v1/tickets/{id}/messages', $tickets->addMessage(...), true);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-process/contact', $leadOffers->markContacted(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-process/next-action', $leadOffers->chooseNextAction(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/tickets/{id}/lead-offer-draft', $leadOffers->draft(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('PUT', '/api/v1/tickets/{id}/lead-offer-draft', $leadOffers->saveDraft(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/tickets/{id}/lead-offer-draft/document', $leadOffers->previewDraft(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-offer', $leadOffers->sendOffer(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-offer/resend', $leadOffers->resendOffer(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-process/onboarding/handoff', $leadFulfillment->handoffOnboarding(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('PUT', '/api/v1/tickets/{id}/lead-process/onboarding/appointment', $leadFulfillment->scheduleOnboardingAppointment(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/tickets/{id}/lead-process/onboarding/invite', $leadFulfillment->sendAccountInvitation(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/tickets/{id}/attachments/{attachmentId}', $tickets->attachment(...), true);
        $this->router->add('PATCH', '/api/v1/tickets/{id}', $tickets->update(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('DELETE', '/api/v1/tickets/{id}', $tickets->delete(...), true, ['platform_admin']);

        // Verträge bleiben ein eigenständiges kaufmännisches Aggregat. Die
        // Service-Schicht begrenzt Mitarbeiterzugriff zusätzlich auf Vertrieb.
        $this->router->add('GET', '/api/v1/contracts', $contracts->index(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/contracts', $contracts->create(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/contracts/{id}', $contracts->show(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('PATCH', '/api/v1/contracts/{id}', $contracts->update(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/contracts/{id}/activate', $contracts->activate(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/contracts/{id}/terminate', $contracts->terminate(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/contracts/{id}/document', $contracts->document(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/contracts/{id}/signature-document', $leadFulfillment->previewContract(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/contracts/{id}/send-for-signature', $leadFulfillment->sendContract(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/contracts/{id}/signed-document', $leadFulfillment->signedDocument(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('DELETE', '/api/v1/contracts/{id}', $contracts->delete(...), true, ['platform_admin', 'datacenter_staff']);

        // Angemeldete Kunden sehen nur die ihnen zugeordneten, unterschriebenen
        // Vertragsfassungen; Entwürfe und interne Prozessdaten bleiben verborgen.
        $this->router->add('GET', '/api/v1/customer/contracts', $contracts->customerIndex(...), true, ['customer_admin']);
        $this->router->add('GET', '/api/v1/customer/contracts/{id}/document', $contracts->customerSignedDocument(...), true, ['customer_admin']);
        $this->router->add('POST', '/api/v1/customer/contracts/{id}/termination-request', $contracts->customerTermination(...), true, ['customer_admin']);

        // Kunden: Plattform-Administration und Vertrieb verwalten den gesamten
        // Kundenbestand. Die Service-Schicht schließt andere Mitarbeiter aus.
        $this->router->add('GET', '/api/v1/customers/current', $customers->current(...), true);
        $this->router->add('PATCH', '/api/v1/customers/current', function (Request $request) use ($customers): Response {
            $authContext = $request->attribute('auth');
            if (!$authContext instanceof \ColoManager\Auth\AuthContext || $authContext->customerId === null) {
                throw new ApiException(403, 'Dem Benutzer ist kein Kunde zugeordnet.', 'forbidden');
            }
            $request->setRouteParams(['id' => $authContext->customerId]);
            return $customers->update($request);
        }, true);
        $this->router->add('GET', '/api/v1/customers', $customers->index(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('POST', '/api/v1/customers', $customers->create(...), true, ['platform_admin', 'datacenter_staff']);
        $this->router->add('GET', '/api/v1/customers/{id}', $customers->show(...), true);
        $this->router->add('PATCH', '/api/v1/customers/{id}', $customers->update(...), true);
        $this->router->add('DELETE', '/api/v1/customers/{id}', $customers->delete(...), true, ['platform_admin', 'datacenter_staff']);

        // Standorte
        $this->router->add('GET', '/api/v1/locations', $locations->index(...), true);
        $this->router->add('POST', '/api/v1/locations', $locations->create(...), true, ['platform_admin', 'customer_admin']);
        $this->router->add('GET', '/api/v1/locations/{id}', $locations->show(...), true);
        $this->router->add('PATCH', '/api/v1/locations/{id}', $locations->update(...), true, ['platform_admin', 'customer_admin']);
        $this->router->add('DELETE', '/api/v1/locations/{id}', $locations->delete(...), true, ['platform_admin', 'customer_admin']);

        // Server und weitere Geräte teilen sich eine Ressource und werden über "type" unterschieden.
        $this->router->add('GET', '/api/v1/devices', $devices->index(...), true);
        $this->router->add('POST', '/api/v1/devices', $devices->create(...), true, ['platform_admin', 'customer_admin']);
        $this->router->add('GET', '/api/v1/devices/{id}', $devices->show(...), true);
        $this->router->add('PATCH', '/api/v1/devices/{id}', $devices->update(...), true, ['platform_admin', 'customer_admin']);
        $this->router->add('DELETE', '/api/v1/devices/{id}', $devices->delete(...), true, ['platform_admin', 'customer_admin']);

        // Rack-Stammdaten werden zentral gepflegt. Die Belegung ist dagegen
        // ein eigener API-First-Workflow für Kunden und freigegebene Techniker.
        $this->router->add('GET', '/api/v1/racks', $racks->index(...), true);
        $this->router->add('POST', '/api/v1/racks', $racks->create(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/racks/{id}/layout', $racks->layout(...), true);
        $this->router->add('PATCH', '/api/v1/racks/{id}/remote-hands-access', $racks->updateRemoteHandsAccess(...), true, ['customer_admin']);
        $this->router->add('POST', '/api/v1/racks/{id}/layout/devices', $racks->createLayoutDevice(...), true, ['platform_admin', 'datacenter_staff', 'customer_admin']);
        $this->router->add('PATCH', '/api/v1/racks/{id}/layout/devices/{deviceId}', $racks->updateLayoutDevice(...), true, ['platform_admin', 'datacenter_staff', 'customer_admin']);
        $this->router->add('DELETE', '/api/v1/racks/{id}/layout/devices/{deviceId}', $racks->deleteLayoutDevice(...), true, ['platform_admin', 'datacenter_staff', 'customer_admin']);
        $this->router->add('GET', '/api/v1/racks/{id}', $racks->show(...), true);
        $this->router->add('PATCH', '/api/v1/racks/{id}', $racks->update(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/racks/{id}', $racks->delete(...), true, ['platform_admin']);

        // Der aktive Katalog ist für Kunden sichtbar; alle Schreibzugriffe bleiben im Adminbereich.
        $this->router->add('GET', '/api/v1/plans', $catalog->plans(...), true);
        $this->router->add('POST', '/api/v1/plans', $catalog->createPlan(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/plans/{id}', $catalog->plan(...), true);
        $this->router->add('PATCH', '/api/v1/plans/{id}', $catalog->updatePlan(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/plans/{id}', $catalog->deletePlan(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/bandwidth-options', $catalog->bandwidthOptions(...), true);
        $this->router->add('POST', '/api/v1/bandwidth-options', $catalog->createBandwidthOption(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/bandwidth-options/{id}', $catalog->bandwidthOption(...), true);
        $this->router->add('PATCH', '/api/v1/bandwidth-options/{id}', $catalog->updateBandwidthOption(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/bandwidth-options/{id}', $catalog->deleteBandwidthOption(...), true, ['platform_admin']);

        // ========================================================================
        // EPIC E04: Störungen und Wartungen
        // ========================================================================

        // Störungen - Mitarbeiter-Endpunkte (CRUD)
        $this->router->add('GET', '/api/v1/incidents', $incidents->index(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/incidents', $incidents->create(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/incidents/{id}', $incidents->show(...), true, ['platform_admin']);
        $this->router->add('PATCH', '/api/v1/incidents/{id}', $incidents->update(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/incidents/{id}', $incidents->delete(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/incidents/{id}/history', $incidents->history(...), true, ['platform_admin']);

        // Störungen - Kunden-Endpunkte (nur lesend, nur eigene)
        $this->router->add('GET', '/api/v1/customer/incidents', $customerIncidents->index(...), true);
        $this->router->add('GET', '/api/v1/customer/incidents/{id}', $customerIncidents->show(...), true);
        $this->router->add('GET', '/api/v1/customer/incidents/{id}/history', $customerIncidents->history(...), true);

        // Wartungen - Mitarbeiter-Endpunkte (CRUD)
        $this->router->add('GET', '/api/v1/maintenance', $maintenance->index(...), true, ['platform_admin']);
        $this->router->add('POST', '/api/v1/maintenance', $maintenance->create(...), true, ['platform_admin']);
        $this->router->add('GET', '/api/v1/maintenance/{id}', $maintenance->show(...), true, ['platform_admin']);
        $this->router->add('PATCH', '/api/v1/maintenance/{id}', $maintenance->update(...), true, ['platform_admin']);
        $this->router->add('DELETE', '/api/v1/maintenance/{id}', $maintenance->delete(...), true, ['platform_admin']);

        // Wartungen - Kunden-Endpunkte (nur lesend, nur eigene)
        $this->router->add('GET', '/api/v1/customer/maintenance', $customerMaintenance->index(...), true);
        $this->router->add('GET', '/api/v1/customer/maintenance/{id}', $customerMaintenance->show(...), true);
    }

    private function errorResponse(Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            return Response::json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ]], $exception->status);
        }

        if ($exception instanceof BulkWriteException && $exception->getCode() === 11000) {
            return Response::json(['error' => [
                'code' => 'duplicate_resource',
                'message' => 'Ein Datensatz mit diesem eindeutigen Wert existiert bereits.',
                'details' => [],
            ]], 409);
        }

        $details = $this->config->debug ? [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ] : [];

        return Response::json(['error' => [
            'code' => 'internal_error',
            'message' => 'Bei der Verarbeitung ist ein interner Fehler aufgetreten.',
            'details' => $details,
        ]], 500);
    }

    private function withCors(Response $response): Response
    {
        return new Response($response->status, $response->data, $response->headers + [
            'Access-Control-Allow-Origin' => $this->config->corsAllowedOrigin,
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Vary' => 'Origin',
        ]);
    }
}
