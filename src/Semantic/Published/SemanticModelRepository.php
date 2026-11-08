<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Topics\TopicModelBuilder;
use Abdalla\ReportGenerator\Topics\TopicRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Hands the engine the model built from the host's topics. Field
 * visibility depends on the user, so the model is built per user and
 * kept for the rest of the request.
 */
final class SemanticModelRepository
{
    /** @var array<string, SemanticModel> */
    private array $built = [];

    public function __construct(
        private readonly TopicRegistry $topics,
        private readonly TopicModelBuilder $builder,
        private readonly ReportGenerator $generator,
    ) {
    }

    public function current(?Authenticatable $user = null): SemanticModel
    {
        if ($this->topics->isEmpty()) {
            throw new QueryException('NO_TOPICS', 'No report topics are registered yet. Add Topic classes (php artisan report-generator:make-topic) to the topics folder.');
        }

        $key = $user ? get_class($user).':'.$user->getAuthIdentifier() : 'guest';

        return $this->built[$key] ??= $this->builder->build(
            $this->topics,
            $user,
            fn (?Authenticatable $u) => $this->generator->rolesOf($u),
        );
    }

    public function forget(): void
    {
        $this->built = [];
        $this->topics->forget();
    }
}
