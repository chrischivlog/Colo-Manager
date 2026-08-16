<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\BrandingService;

/** HTTP-Endpunkte für öffentliches Lesen und administratives Ändern des Brandings. */
final class BrandingController extends BaseController
{
    public function __construct(private readonly BrandingService $branding)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->branding->show()]);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->branding->update($this->auth($request), $request->body, $request->files)]);
    }

    public function removeLogo(Request $request): Response
    {
        return Response::json(['data' => $this->branding->removeLogo($this->auth($request))]);
    }

    public function logo(Request $request): Response
    {
        $logo = $this->branding->logo();
        return Response::binary($logo['content'], $logo['mimeType'], $logo['name']);
    }

}
