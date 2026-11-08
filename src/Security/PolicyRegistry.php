<?php

namespace Abdalla\ReportGenerator\Security;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Abdalla\ReportGenerator\Contracts\RowPolicy;
use Abdalla\ReportGenerator\Contracts\RowScope;

final class PolicyRegistry
{
    /** @var array<string, list<Closure|class-string<RowPolicy>|RowPolicy>> */
    private array $policies = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param  Closure(Builder, PolicyContext): void|class-string<RowPolicy>|RowPolicy  $policy
     */
    public function register(string $dataset, Closure|string|RowPolicy $policy): void
    {
        if (is_string($policy) && ! is_subclass_of($policy, RowPolicy::class)) {
            throw new \InvalidArgumentException("[{$policy}] must implement ".RowPolicy::class.'.');
        }

        $this->policies[$dataset][] = $policy;
    }

    /** @var array<string, true> */
    private array $unrestricted = [];

    private RowScope|Closure|null $scope = null;

    /** @param  RowScope|Closure(Builder, ScopeContext): void  $scope */
    public function setScope(RowScope|Closure $scope): void
    {
        $this->scope = $scope;
    }

    public function hasScope(): bool
    {
        return $this->scope !== null;
    }

    /**
     * Applies the row scope in its own nested where, so an OR inside it can
     * never widen what the rest of the query allows.
     */
    public function applyScope(Builder $query, ScopeContext $context): void
    {
        if ($this->scope === null || ! $context->dataset->isScoped()) {
            return;
        }

        $scope = $this->scope;
        $query->where(function (Builder $nested) use ($scope, $context) {
            $scope instanceof RowScope ? $scope->apply($nested, $context) : $scope($nested, $context);
        });
    }

    public function has(string $dataset): bool
    {
        return ! empty($this->policies[$dataset]);
    }

    /** @param  list<string>  $datasets */
    public function markUnrestricted(array $datasets): void
    {
        foreach ($datasets as $dataset) {
            $this->unrestricted[$dataset] = true;
        }
    }

    public function isUnrestricted(string $dataset): bool
    {
        return isset($this->unrestricted[$dataset])
            || in_array($dataset, (array) config('report-generator.security.unrestricted_datasets', []), true);
    }

    /** Fingerprint of what is registered, so cached results never cross a policy change. */
    public function fingerprint(): string
    {
        return md5(json_encode([array_map('count', $this->policies), array_keys($this->unrestricted), $this->scope !== null]));
    }

    /** @return list<string> */
    public function datasets(): array
    {
        return array_keys($this->policies);
    }

    /**
     * Apply every policy registered for the context's dataset. Each policy is
     * wrapped in its own nested where so an OR inside one policy cannot widen
     * the rows another policy (or the report's own filters) allow.
     */
    public function apply(Builder $query, PolicyContext $context): void
    {
        foreach ($this->policies[$context->dataset] ?? [] as $policy) {
            $query->where(function (Builder $nested) use ($policy, $context) {
                $this->resolve($policy)($nested, $context);
            });
        }
    }

    /** @return callable(Builder, PolicyContext): void */
    private function resolve(Closure|string|RowPolicy $policy): callable
    {
        if ($policy instanceof Closure) {
            return $policy;
        }

        $instance = is_string($policy) ? $this->container->make($policy) : $policy;

        return fn (Builder $query, PolicyContext $context) => $instance->apply($query, $context);
    }
}
