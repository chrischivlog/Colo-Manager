<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\PublicStatusService;

/**
 * Controller für die öffentliche Status-Seite.
 * Zeigt Störungen und Wartungen an, die für die Öffentlichkeit sichtbar sind.
 */
final class PublicStatusController
{
    public function __construct(private readonly PublicStatusService $statusService)
    {
    }

    /**
     * Gibt den aktuellen Systemstatus zurück (für Status-Badge)
     */
    public function status(Request $request): Response
    {
        return Response::json(['data' => $this->statusService->getSystemStatus()]);
    }

    /**
     * Liste aller für die Öffentlichkeit sichtbaren Störungen
     */
    public function incidents(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->statusService->getPublicIncidents($page, $limit)]);
    }

    /**
     * Liste aller für die Öffentlichkeit sichtbaren Wartungen
     */
    public function maintenance(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->statusService->getPublicMaintenance($page, $limit)]);
    }

    /**
     * Kombinierte Status-Seite mit Störungen und Wartungen
     */
    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->statusService->getCombinedStatus()]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function pagination(Request $request): array
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 25)));
        return [$page, $limit];
    }
}
