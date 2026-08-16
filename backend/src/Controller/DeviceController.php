<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\DeviceService;

final class DeviceController extends BaseController
{
    public function __construct(private readonly DeviceService $devices)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->devices->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'customerId'),
            $this->queryString($request, 'locationId'),
            $this->queryString($request, 'type'),
            $this->queryString($request, 'status'),
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->devices->show($this->auth($request), $request->param('id'))]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->devices->create($this->auth($request), $request->body)], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->devices->update(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function delete(Request $request): Response
    {
        $this->devices->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
