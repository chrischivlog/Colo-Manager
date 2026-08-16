<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\MaintenanceService;

final class MaintenanceController extends BaseController
{
    public function __construct(private readonly MaintenanceService $maintenance)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->maintenance->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'status'),
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->maintenance->show(
            $this->auth($request),
            $request->param('id'),
        )]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->maintenance->create(
            $this->auth($request),
            $request->body,
        )], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->maintenance->update(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function delete(Request $request): Response
    {
        $this->maintenance->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
