<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\StaffUserService;

final class StaffUserController extends BaseController
{
    public function __construct(private readonly StaffUserService $staffUsers) {}
    public function index(Request $request): Response { return Response::json(['data' => $this->staffUsers->list($this->auth($request))]); }
    public function create(Request $request): Response { return Response::json(['data' => $this->staffUsers->create($this->auth($request), $request->body)], 201); }
    public function update(Request $request): Response { return Response::json(['data' => $this->staffUsers->update($this->auth($request), $request->param('id'), $request->body)]); }
    public function delete(Request $request): Response { $this->staffUsers->delete($this->auth($request), $request->param('id')); return Response::noContent(); }
}
