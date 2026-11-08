<?php

namespace Abdalla\ReportGenerator\Http\Controllers;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Export\ReportWriter;
use Abdalla\ReportGenerator\Http\Controllers\Reports\RunController;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Whole reports as CSV or Excel, streamed: the file is written while rows
 * are read, so exports of millions of rows use constant memory.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly QueryEngine $engine,
        private readonly ReportWriter $writer,
        private readonly ReportAccess $access,
        private readonly ReportGenerator $generator,
    ) {
    }

    /** A report being built (definition in the request). */
    public function definition(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'definition' => ['required', 'array'],
            'parameters' => ['sometimes', 'array'],
            'format' => ['required', Rule::in(ReportWriter::FORMATS)],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $result = $this->engine->stream($data['definition'], $data['parameters'] ?? [], $request->user());

        return $this->writer->response($data['format'], $data['name'] ?? 'report', $result['columns'], $result['rows']);
    }

    /** A saved report, with the viewer's report filters and sort. */
    public function report(Request $request, Report $report): StreamedResponse
    {
        abort_unless($this->access->canView($report, $request->user()), 403, 'You do not have access to this report.');
        abort_unless($this->generator->allows($request->user(), Ability::ExportReports), 403, 'You cannot export reports.');

        $data = $request->validate(['format' => ['required', Rule::in(ReportWriter::FORMATS)]]);
        $definition = RunController::applyViewerChoices($report->definition, $request);
        $result = $this->engine->stream($definition, (array) $request->input('parameters', []), $request->user());

        return $this->writer->response($data['format'], $report->name, $result['columns'], $result['rows']);
    }
}
