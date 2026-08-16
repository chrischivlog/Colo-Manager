<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\IncidentService;

/**
 * Controller für Kunden-spezifische Störungsendpunkte.
 * Kunden können nur ihre eigenen Störungen sehen.
 */
final class CustomerIncidentController extends BaseController
{
    public function __construct(private readonly IncidentService $incidents)
    {
    }

    /**
     * Liste aller Störungen, die den angemeldeten Kunden betreffen
     */
    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->incidents->listForCustomer(
            $this->auth($request),
            $page,
            $limit,
        )]);
    }

    /**
     * Details einer bestimmten Störung, die den angemeldeten Kunden betrifft
     */
    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->show(
            $this->auth($request),
            $request->param('id'),
        )]);
    }

    /**
     * Historie einer bestimmten Störung, die den angemeldeten Kunden betrifft
     */
    public function history(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->history(
            $this->auth($request),
            $request->param('id'),
        )]);
    }
}
