<?php

namespace Abdalla\ReportGenerator\Query\Execution;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which datasets a user may read. See config report-generator.security.
 */
final class DatasetAccess
{
    private ?bool $viewAll = null;

    public function __construct(
        private readonly ReportGenerator $generator,
        private readonly PolicyRegistry $policies,
        private readonly ?Authenticatable $user,
    ) {
    }

    public function allows(string $dataset): bool
    {
        if (! config('report-generator.security.require_row_policies', true)) {
            return true;
        }

        $this->viewAll ??= $this->generator->allows($this->user, Ability::ViewAllReports);

        return $this->viewAll || $this->policies->has($dataset) || $this->policies->isUnrestricted($dataset);
    }

    public function asserter(SemanticModel $model): \Closure
    {
        return function (string $dataset) use ($model) {
            if (! $this->allows($dataset)) {
                $label = $model->dataset($dataset)?->label ?? $dataset;
                throw new QueryException('DATASET_FORBIDDEN', "You do not have access to {$label}.", status: 403, details: ['dataset' => $dataset]);
            }
        };
    }
}
