<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\LocationService;

final class LocationController extends BaseController
{
    public function __construct(private readonly LocationService $locations)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->locations->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'customerId'),
            $this->queryString($request, 'status'),
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->locations->show($this->auth($request), $request->param('id'))]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->locations->create($this->auth($request), $request->body)], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->locations->update(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function delete(Request $request): Response
    {
        $this->locations->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
