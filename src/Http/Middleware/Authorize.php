<?php

namespace Abdalla\ReportGenerator\Http\Middleware;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\ReportGenerator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: report-generator.can:manageModel
 */
final class Authorize
{
    public function __construct(private readonly ReportGenerator $generator)
    {
    }

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $resolved = Ability::fromKey($ability);

        if (! $this->generator->allows($request->user(), $resolved)) {
            abort(403, 'You do not have permission to do this in the report generator.');
        }

        return $next($request);
    }
}
