<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\DirectoryConfigurationService;

final class DirectoryConfigurationController extends BaseController
{
    public function __construct(private readonly DirectoryConfigurationService $directories) {}
    public function index(Request $request): Response { return Response::json(['data' => $this->directories->list($this->auth($request))]); }
    public function create(Request $request): Response { return Response::json(['data' => $this->directories->create($this->auth($request), $request->body)], 201); }
    public function update(Request $request): Response { return Response::json(['data' => $this->directories->update($this->auth($request), $request->param('id'), $request->body)]); }
    public function test(Request $request): Response { return Response::json(['data' => $this->directories->test($this->auth($request), $request->param('id'))]); }
    public function delete(Request $request): Response { $this->directories->delete($this->auth($request), $request->param('id')); return Response::noContent(); }
}
