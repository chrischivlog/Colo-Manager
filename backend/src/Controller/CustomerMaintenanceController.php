<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\MaintenanceService;

/**
 * Controller für Kunden-spezifische Wartungsendpunkte.
 * Kunden können nur ihre eigenen Wartungen sehen.
 */
final class CustomerMaintenanceController extends BaseController
{
    public function __construct(private readonly MaintenanceService $maintenance)
    {
    }

    /**
     * Liste aller Wartungen, die den angemeldeten Kunden betreffen
     */
    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->maintenance->listForCustomer(
            $this->auth($request),
            $page,
            $limit,
        )]);
    }

    /**
     * Details einer bestimmten Wartung, die den angemeldeten Kunden betrifft
     */
    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->maintenance->show(
            $this->auth($request),
            $request->param('id'),
        )]);
    }
}
