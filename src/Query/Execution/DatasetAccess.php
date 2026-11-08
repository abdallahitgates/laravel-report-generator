<?php

namespace Abdalla\ReportGenerator\Query\Execution;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which topics a user may report on.
 *
 * Users with view-all: every topic. Everyone else: a topic's own
 * permission must pass, and its rows must be protected by something:
 * the row scope (topics with an employee or division column), a policy,
 * or the topic being public lookup data. A topic with none of these is
 * hidden rather than leaked.
 */
final class DatasetAccess
{
    private ?bool $viewAll = null;

    public function __construct(
        private readonly ReportGenerator $generator,
        private readonly PolicyRegistry $policies,
        private readonly ?Authenticatable $user,
        private readonly SemanticModel $model,
    ) {
    }

    public function viewAll(): bool
    {
        return $this->viewAll ??= $this->generator->allows($this->user, Ability::ViewAllReports);
    }

    public function allows(string $dataset): bool
    {
        $published = $this->model->dataset($dataset);
        if ($published === null) {
            return false;
        }
        if ($this->viewAll()) {
            return true;
        }
        if ($published->permission !== null && ! $this->generator->allows($this->user, $published->permission)) {
            return false;
        }
        if (! config('report-generator.security.require_row_policies', true)) {
            return true;
        }

        return $published->public
            || ($published->isScoped() && $this->policies->hasScope())
            || $this->policies->has($dataset)
            || $this->policies->isUnrestricted($dataset);
    }

    public function asserter(): \Closure
    {
        return function (string $dataset) {
            if (! $this->allows($dataset)) {
                $label = $this->model->dataset($dataset)?->label ?? $dataset;
                throw new QueryException('DATASET_FORBIDDEN', "You do not have access to {$label}.", status: 403, details: ['dataset' => $dataset]);
            }
        };
    }
}
