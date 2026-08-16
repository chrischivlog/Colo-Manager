<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\ContractService;

/** REST-Schnittstelle für Vertragsentwürfe und aktive Verträge. */
final class ContractController extends BaseController
{
    public function __construct(private readonly ContractService $contracts)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->contracts->list(
            $this->auth($request),
            $page,
            $limit,
            $this->queryString($request, 'status'),
            $this->queryString($request, 'customerId'),
            $this->queryString($request, 'search'),
        )]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->create($this->auth($request), $request->body)], 201);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->show($this->auth($request), $request->param('id'))]);
    }

    public function update(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->update($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function activate(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->activate($this->auth($request), $request->param('id'))]);
    }

    public function terminate(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->terminate($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function document(Request $request): Response
    {
        $document = $this->contracts->document($this->auth($request), $request->param('id'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function customerIndex(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->contracts->listForCustomer($this->auth($request), $page, $limit)]);
    }

    public function customerSignedDocument(Request $request): Response
    {
        $document = $this->contracts->signedDocumentForCustomer($this->auth($request), $request->param('id'));
        return Response::binary($document['content'], $document['mimeType'], $document['name']);
    }

    public function customerTermination(Request $request): Response
    {
        return Response::json(['data' => $this->contracts->requestTermination($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function delete(Request $request): Response
    {
        $this->contracts->delete($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
