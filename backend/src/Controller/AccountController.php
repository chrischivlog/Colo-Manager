<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\AccountService;

/** REST-Endpunkte für die Selbstverwaltung des angemeldeten Kontos. */
final class AccountController extends BaseController
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->show($this->auth($request))]);
    }

    public function changeEmail(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->changeEmail($this->auth($request), $request->body)]);
    }

    public function changePassword(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->changePassword($this->auth($request), $request->body)]);
    }

    public function startTwoFactorSetup(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->startTwoFactorSetup($this->auth($request), $request->body)]);
    }

    public function confirmTwoFactor(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->confirmTwoFactor($this->auth($request), $request->body)]);
    }

    public function disableTwoFactor(Request $request): Response
    {
        return Response::json(['data' => $this->accounts->disableTwoFactor($this->auth($request), $request->body)]);
    }
}
