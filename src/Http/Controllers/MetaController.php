<?php

namespace Abdalla\ReportGenerator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Abdalla\ReportGenerator\Contracts\ReportSource;
use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\ReportGenerator;

/**
 * Bootstrap payload for the Vue package: what this user may do and what
 * the server is configured to allow, so the UI never has to guess.
 */
class MetaController extends Controller
{
    public function __invoke(Request $request, ReportGenerator $generator): JsonResponse
    {
        $user = $request->user();

        $abilities = [];
        foreach (Ability::cases() as $ability) {
            $abilities[$ability->key()] = $generator->allows($user, $ability);
        }

        return new JsonResponse([
            'version' => ReportGenerator::VERSION,
            'abilities' => $abilities,
            'sources' => array_values(array_map(
                fn (ReportSource $source) => ['key' => $source->key(), 'label' => $source->label()],
                $generator->sources()->all(),
            )),
            'limits' => [
                'preview_page_size' => (int) config('report-generator.limits.preview_page_size'),
                'preview_timeout_ms' => (int) config('report-generator.limits.preview_timeout_ms'),
            ],
        ]);
    }
}
