<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\PublicOfferService;

/** Öffentliche Endpunkte für Angebotskatalog und unverbindliche Anfragen. */
final class PublicOfferController extends BaseController
{
    public function __construct(private readonly PublicOfferService $offers)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->offers->offers()]);
    }

    public function createInquiry(Request $request): Response
    {
        return Response::json(['data' => $this->offers->createInquiry($request->body)], 201);
    }

    public function inquiries(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->offers->listInquiries(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'status'),
        )]);
    }

    public function updateInquiry(Request $request): Response
    {
        return Response::json(['data' => $this->offers->updateInquiry($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function deleteInquiry(Request $request): Response
    {
        $this->offers->deleteInquiry($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
