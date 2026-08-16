<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Auth\AuthContext;
use ColoManager\Http\ApiException;
use ColoManager\Http\Request;

abstract class BaseController
{
    protected function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw new ApiException(401, 'Die Anfrage ist nicht authentifiziert.', 'authentication_required');
        }
        return $auth;
    }

    /** @return array{0: int, 1: int} */
    protected function pagination(Request $request): array
    {
        // Ein hartes Maximum schützt MongoDB vor versehentlich sehr großen
        // Listenabfragen und hält die API-Antworten vorhersehbar.
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 25)));

        return [$page, $limit];
    }

    protected function queryString(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
