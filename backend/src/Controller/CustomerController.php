<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\CustomerService;

final class CustomerController extends BaseController
{
    public function __construct(private readonly CustomerService $customers)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->customers->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'search'),
        )]);
    }

    public function current(Request $request): Response
    {
        return Response::json(['data' => $this->customers->current($this->auth($request))]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->customers->show($this->auth($request), $request->param('id'))]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->customers->create($this->auth($request), $request->body)], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->customers->update(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function delete(Request $request): Response
    {
        $this->customers->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
