<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\IncidentService;

final class IncidentController extends BaseController
{
    public function __construct(private readonly IncidentService $incidents)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->incidents->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'status'),
            $this->queryString($request, 'priority'),
            $this->queryBool($request, 'critical'),
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->show(
            $this->auth($request),
            $request->param('id'),
        )]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->create(
            $this->auth($request),
            $request->body,
        )], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->update(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function delete(Request $request): Response
    {
        $this->incidents->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }

    /**
     * Gibt die Historie einer Störung zurück
     */
    public function history(Request $request): Response
    {
        return Response::json(['data' => $this->incidents->history(
            $this->auth($request),
            $request->param('id'),
        )]);
    }

    /**
     * Hilfsmethode zum Parsen von Boolean-Query-Parametern
     */
    protected function queryBool(Request $request, string $name): ?bool
    {
        $value = $request->query[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = strtolower($value);
            if ($value === 'true' || $value === '1') {
                return true;
            }
            if ($value === 'false' || $value === '0') {
                return false;
            }
        }
        return null;
    }
}
