<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\PasswordResetService;
use ColoManager\Support\Validator;

final class PasswordResetController
{
    public function __construct(private readonly PasswordResetService $passwordReset)
    {
    }

    public function request(Request $request): Response
    {
        Validator::required($request->body, ['email']);
        Validator::email($request->body, 'email');
        return Response::json(['data' => $this->passwordReset->request((string) $request->body['email'])], 202);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->passwordReset->inspect($request->param('token'))]);
    }

    public function reset(Request $request): Response
    {
        Validator::required($request->body, ['password', 'passwordConfirmation']);
        return Response::json(['data' => $this->passwordReset->reset($request->param('token'), $request->body)]);
    }
}
