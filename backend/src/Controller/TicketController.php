<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\TicketService;

/** REST-Controller für Ticketlisten, Verläufe, Antworten und Bildanhänge. */
final class TicketController extends BaseController
{
    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->tickets->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'type'),
            $this->queryString($request, 'status'),
            $this->queryString($request, 'category'),
            $this->queryString($request, 'search'),
        )]);
    }

    public function assignees(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->assignees($this->auth($request))]);
    }

    public function customerOptions(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->customerOptions($this->auth($request))]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->create(
            $this->auth($request),
            $request->body,
            $request->files['attachments'] ?? [],
        )], 201);
    }

    public function createInternal(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->createInternal(
            $this->auth($request),
            $request->body,
            $request->files['attachments'] ?? [],
        )], 201);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->detail($this->auth($request), $request->param('id'))]);
    }

    public function addMessage(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->addMessage(
            $this->auth($request),
            $request->param('id'),
            $request->body,
            $request->files['attachments'] ?? [],
        )], 201);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->tickets->update($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function delete(Request $request): Response
    {
        $this->tickets->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }

    public function attachment(Request $request): Response
    {
        $attachment = $this->tickets->downloadAttachment(
            $this->auth($request),
            $request->param('id'),
            $request->param('attachmentId'),
        );
        return Response::binary($attachment['content'], $attachment['mimeType'], $attachment['name']);
    }
}
