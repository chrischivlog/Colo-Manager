<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\AuthService;
use ColoManager\Support\Validator;

final class AuthController extends BaseController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(Request $request): Response
    {
        Validator::required($request->body, ['email', 'password']);
        Validator::email($request->body, 'email');

        return Response::json(['data' => $this->authService->login(
            (string) $request->body['email'],
            (string) $request->body['password'],
            isset($request->body['totpCode']) ? (string) $request->body['totpCode'] : null,
        )]);
    }

    public function me(Request $request): Response
    {
        return Response::json(['data' => $this->authService->currentUser($this->auth($request))]);
    }

    public function heartbeat(Request $request): Response
    {
        return Response::json(['data' => $this->authService->heartbeat($this->auth($request))]);
    }

    public function logout(Request $request): Response
    {
        return Response::json(['data' => $this->authService->logout($this->auth($request))]);
    }
}
