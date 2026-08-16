<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\NetworkAssignmentService;

/** REST-Endpunkte für ISP- und IP-Zuweisungen. */
final class NetworkAssignmentController extends BaseController
{
    public function __construct(private readonly NetworkAssignmentService $assignments)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->assignments->listInternal(
            $this->auth($request), $page, $limit,
            $this->queryString($request, 'customerId'),
            $this->queryString($request, 'locationId'),
            $this->queryString($request, 'status'),
        )]);
    }

    public function customerIndex(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->assignments->listCustomer(
            $this->auth($request), $page, $limit,
            $this->queryString($request, 'locationId'),
            $this->queryString($request, 'status'),
        )]);
    }

    public function options(Request $request): Response
    {
        return Response::json(['data' => $this->assignments->options($this->auth($request), $this->queryString($request, 'customerId'))]);
    }

    public function search(Request $request): Response
    {
        return Response::json(['data' => $this->assignments->searchInternal(
            $this->auth($request),
            $this->queryString($request, 'query') ?? '',
        )]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->assignments->show($this->auth($request), $request->param('id'))]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->assignments->create($this->auth($request), $request->body)], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->assignments->update($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function delete(Request $request): Response
    {
        $this->assignments->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
