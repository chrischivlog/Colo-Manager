<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\LeadFulfillmentService;

/** HTTP-Schnittstelle für Vertragssignatur, Onboarding und Account-Einladung. */
final class LeadFulfillmentController extends BaseController
{
    public function __construct(private readonly LeadFulfillmentService $fulfillment)
    {
    }

    public function previewContract(Request $request): Response
    {
        $document = $this->fulfillment->previewContract($this->auth($request), $request->param('id'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function sendContract(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->sendContract($this->auth($request), $request->param('id'))], 201);
    }

    public function signedDocument(Request $request): Response
    {
        $document = $this->fulfillment->signedDocument($this->auth($request), $request->param('id'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function publicContract(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->publicContract($request->param('token'))]);
    }

    public function publicContractDocument(Request $request): Response
    {
        $document = $this->fulfillment->publicContractDocument($request->param('token'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function uploadSignedContract(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->uploadSignedContract(
            $request->param('token'),
            $request->files['signedContract'] ?? [],
        )]);
    }

    public function handoffOnboarding(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->handoffOnboarding(
            $this->auth($request),
            $request->param('id'),
            (string) ($request->body['assignedToUserId'] ?? ''),
        )]);
    }

    public function sendAccountInvitation(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->sendAccountInvitation($this->auth($request), $request->param('id'))], 201);
    }

    public function scheduleOnboardingAppointment(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->scheduleOnboardingAppointment(
            $this->auth($request),
            $request->param('id'),
            $request->body,
        )]);
    }

    public function publicInvitation(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->publicInvitation($request->param('token'))]);
    }

    public function activateAccount(Request $request): Response
    {
        return Response::json(['data' => $this->fulfillment->activateAccount($request->param('token'), $request->body)]);
    }
}
