<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\LeadOfferService;

/** HTTP-Schnittstelle für Checkliste, Angebotsversand und öffentliche Entscheidung. */
final class LeadOfferController extends BaseController
{
    public function __construct(private readonly LeadOfferService $offers)
    {
    }

    public function markContacted(Request $request): Response
    {
        return Response::json(['data' => $this->offers->markContacted($this->auth($request), $request->param('id'))]);
    }

    public function sendOffer(Request $request): Response
    {
        return Response::json(['data' => $this->offers->sendOffer(
            $this->auth($request),
            $request->param('id'),
        )], 201);
    }

    public function resendOffer(Request $request): Response
    {
        return Response::json(['data' => $this->offers->resendOffer(
            $this->auth($request),
            $request->param('id'),
        )]);
    }

    public function draft(Request $request): Response
    {
        return Response::json(['data' => $this->offers->draft($this->auth($request), $request->param('id'))]);
    }

    public function saveDraft(Request $request): Response
    {
        return Response::json(['data' => $this->offers->saveDraft($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function previewDraft(Request $request): Response
    {
        $document = $this->offers->previewDraft($this->auth($request), $request->param('id'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function chooseNextAction(Request $request): Response
    {
        return Response::json(['data' => $this->offers->chooseNextAction(
            $this->auth($request),
            $request->param('id'),
            (string) ($request->body['action'] ?? ''),
        )]);
    }

    public function showPublic(Request $request): Response
    {
        return Response::json(['data' => $this->offers->publicOffer($request->param('token'))]);
    }

    public function decide(Request $request): Response
    {
        return Response::json(['data' => $this->offers->decide(
            $request->param('token'),
            (string) ($request->body['decision'] ?? ''),
        )]);
    }

    public function document(Request $request): Response
    {
        $document = $this->offers->publicDocument($request->param('token'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }
}
