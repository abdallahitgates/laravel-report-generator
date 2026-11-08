<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Topics;

use Abdalla\ReportGenerator\Query\QueryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Every topic with its fields and relations, for the admin Topics page (read-only: topics live in code). */
class TopicController extends Controller
{
    public function __invoke(Request $request, QueryEngine $engine): JsonResponse
    {
        return new JsonResponse($engine->topicsOverview($request->user()));
    }
}
