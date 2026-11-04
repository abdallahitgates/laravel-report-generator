<?php

namespace Abdalla\ReportGenerator\Sources;

use Illuminate\Contracts\Container\Container;
use Abdalla\ReportGenerator\Contracts\ReportSource;

final class SourceRegistry
{
    /** @var list<class-string<ReportSource>|ReportSource> */
    private array $pending = [];

    /** @var array<string, ReportSource>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Sources are resolved lazily, so registering one in a service provider
     * costs nothing on requests that never touch reports.
     *
     * @param  class-string<ReportSource>|ReportSource  $source
     */
    public function register(string|ReportSource $source): void
    {
        if (is_string($source) && ! is_subclass_of($source, ReportSource::class)) {
            throw new \InvalidArgumentException("[{$source}] must implement ".ReportSource::class.'.');
        }

        $this->pending[] = $source;
        $this->resolved = null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ReportSource
    {
        return $this->all()[$key]
            ?? throw new \OutOfBoundsException("No report source registered with key [{$key}].");
    }

    /** @return array<string, ReportSource> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->pending as $source) {
            $instance = is_string($source) ? $this->container->make($source) : $source;
            $key = $instance->key();

            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException(
                    "Report source key [{$key}] from [".$instance::class.'] must be lowercase letters, digits and underscores.'
                );
            }

            if (isset($resolved[$key])) {
                throw new \LogicException(
                    "Report source key [{$key}] is registered twice ([".$resolved[$key]::class.'] and ['.$instance::class.']).'
                );
            }

            $resolved[$key] = $instance;
        }

        return $this->resolved = $resolved;
    }
}
