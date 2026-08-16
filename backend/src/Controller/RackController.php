<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\RackService;

/** Übersetzt Rack-Requests in Aufrufe der fachlichen Service-Schicht. */
final class RackController extends BaseController
{
    public function __construct(private readonly RackService $racks)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->racks->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'customerId'),
            $this->queryString($request, 'locationId'),
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->racks->show($this->auth($request), $request->param('id'))]);
    }

    public function layout(Request $request): Response
    {
        return Response::json(['data' => $this->racks->layout($this->auth($request), $request->param('id'))]);
    }

    public function updateRemoteHandsAccess(Request $request): Response
    {
        return Response::json(['data' => $this->racks->updateRemoteHandsAccess(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function createLayoutDevice(Request $request): Response
    {
        return Response::json(['data' => $this->racks->createLayoutDevice(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )], 201);
    }

    public function updateLayoutDevice(Request $request): Response
    {
        return Response::json(['data' => $this->racks->updateLayoutDevice(
            $this->auth($request),
            $request->param('id'),
            $request->param('deviceId'),
            $request->body,
        )]);
    }

    public function deleteLayoutDevice(Request $request): Response
    {
        $this->racks->deleteLayoutDevice(
            $this->auth($request),
            $request->param('id'),
            $request->param('deviceId'),
        );

        return Response::noContent();
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->racks->create($this->auth($request), $request->body)], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->racks->update($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function delete(Request $request): Response
    {
        $this->racks->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
