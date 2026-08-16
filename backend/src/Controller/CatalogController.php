<?php

declare(strict_types=1);

namespace ColoManager\Controller;

use ColoManager\Http\Request;
use ColoManager\Http\Response;
use ColoManager\Service\CatalogService;

/** Gemeinsamer HTTP-Controller für Tarife und Bandbreitenprofile. */
final class CatalogController extends BaseController
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function plans(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->catalog->listPlans($this->auth($request), $page, $limit, $this->queryString($request, 'status'))]);
    }

    public function plan(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->showPlan($this->auth($request), $request->param('id'))]);
    }

    public function createPlan(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->createPlan($this->auth($request), $request->body)], 201);
    }

    public function updatePlan(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->updatePlan($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function deletePlan(Request $request): Response
    {
        $this->catalog->deletePlan($this->auth($request), $request->param('id'));
        return Response::noContent();
    }

    public function bandwidthOptions(Request $request): Response
    {
        [$page, $limit] = $this->pagination($request);
        return Response::json(['data' => $this->catalog->listBandwidthOptions($this->auth($request), $page, $limit, $this->queryString($request, 'status'))]);
    }

    public function bandwidthOption(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->showBandwidthOption($this->auth($request), $request->param('id'))]);
    }

    public function createBandwidthOption(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->createBandwidthOption($this->auth($request), $request->body)], 201);
    }

    public function updateBandwidthOption(Request $request): Response
    {
        return Response::json(['data' => $this->catalog->updateBandwidthOption($this->auth($request), $request->param('id'), $request->body)]);
    }

    public function deleteBandwidthOption(Request $request): Response
    {
        $this->catalog->deleteBandwidthOption($this->auth($request), $request->param('id'));
        return Response::noContent();
    }
}
