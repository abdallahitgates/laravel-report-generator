<?php

namespace Abdalla\ReportGenerator;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Abdalla\ReportGenerator\Contracts\ReportSource;
use Abdalla\ReportGenerator\Contracts\RowPolicy;
use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Sources\SourceRegistry;

/**
 * The host application's entry point (via the ReportGenerator facade):
 * register row policies, report sources and authorization here.
 */
class ReportGenerator
{
    public const VERSION = '0.1.0-dev';

    /** @var (Closure(?Authenticatable, string, array): bool)|null */
    private ?Closure $authorizer = null;

    public function __construct(
        private readonly PolicyRegistry $policies,
        private readonly SourceRegistry $sources,
        private readonly Gate $gate,
    ) {
    }

    /**
     * Restrict the rows of a dataset for the current user.
     *
     * @param  Closure(\Illuminate\Database\Query\Builder, Security\PolicyContext): void|class-string<RowPolicy>|RowPolicy  $policy
     */
    public function policy(string $dataset, Closure|string|RowPolicy $policy): static
    {
        $this->policies->register($dataset, $policy);

        return $this;
    }

    /**
     * Datasets anyone allowed to build reports may read in full, such as
     * lookup tables (products, lines). Everything else needs a row policy
     * unless the user has the view-all ability.
     *
     * @param  list<string>  $datasets
     */
    public function unrestricted(array $datasets): static
    {
        $this->policies->markUnrestricted($datasets);

        return $this;
    }

    /** @param  class-string<ReportSource>|ReportSource  $source */
    public function source(string|ReportSource $source): static
    {
        $this->sources->register($source);

        return $this;
    }

    /**
     * Replace the Gate-based check with your own.
     *
     * @param  Closure(?Authenticatable, string, array): bool  $callback
     */
    public function authorizeUsing(Closure $callback): static
    {
        $this->authorizer = $callback;

        return $this;
    }

    public function allows(?Authenticatable $user, Ability|string $ability, array $arguments = []): bool
    {
        $ability = $ability instanceof Ability ? $ability->value : $ability;

        if ($this->authorizer !== null) {
            return (bool) ($this->authorizer)($user, $ability, $arguments);
        }

        if ($user !== null && $this->gate->has($ability)) {
            return $this->gate->forUser($user)->allows($ability, $arguments);
        }

        return (bool) config('report-generator.authorization.default', false);
    }

    public function policies(): PolicyRegistry
    {
        return $this->policies;
    }

    public function sources(): SourceRegistry
    {
        return $this->sources;
    }
}
