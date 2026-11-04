<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;

/**
 * Datasets as nodes, confirmed relationships as edges walkable both ways.
 */
final class JoinGraph
{
    /** @var array<string, list<Step>> */
    private array $adjacent = [];

    public function __construct(private readonly SemanticModel $model)
    {
        foreach ($model->relationships as $relationship) {
            $this->adjacent[$relationship->from][] = new Step($relationship, true);
            if ($relationship->from !== $relationship->to || $relationship->fromColumn !== $relationship->toColumn) {
                $this->adjacent[$relationship->to][] = new Step($relationship, false);
            }
        }
    }

    /** @return list<Step> */
    public function stepsFrom(string $dataset): array
    {
        return $this->adjacent[$dataset] ?? [];
    }

    /**
     * Every shortest walk from $from to $to (no dataset visited twice),
     * at most $limit of them.
     *
     * @return list<list<Step>>
     */
    public function shortestPaths(string $from, string $to, int $maxDepth = 6, int $limit = 12): array
    {
        if ($from === $to) {
            return [[]];
        }

        $paths = [[[], [$from => true], $from]];
        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $next = [];
            $found = [];
            foreach ($paths as [$steps, $visited, $at]) {
                foreach ($this->stepsFrom($at) as $step) {
                    $target = $step->target();
                    if (isset($visited[$target])) {
                        continue;
                    }
                    $walk = [...$steps, $step];
                    if ($target === $to) {
                        $found[] = $walk;
                        if (count($found) >= $limit) {
                            return $found;
                        }
                        continue;
                    }
                    $next[] = [$walk, $visited + [$target => true], $target];
                }
            }
            if ($found !== []) {
                return $found;
            }
            // Bound the frontier so a dense schema cannot blow up the search.
            $paths = array_slice($next, 0, 20000);
            if ($paths === []) {
                break;
            }
        }

        return [];
    }

    /**
     * Every dataset reachable from $root within $maxDepth hops, with up to
     * $perTarget shortest paths each. One breadth-first pass, so it stays
     * fast on schemas with hundreds of tables.
     *
     * @return array<string, list<list<Step>>>  dataset => paths
     */
    public function reachable(string $root, int $maxDepth = 3, int $perTarget = 3): array
    {
        $found = [$root => [[]]];
        $frontier = [$root];

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; $depth++) {
            $layer = [];
            foreach ($frontier as $at) {
                foreach ($found[$at] as $path) {
                    if (count($path) !== $depth - 1) {
                        continue;
                    }
                    $visited = [$root => true];
                    foreach ($path as $step) {
                        $visited[$step->target()] = true;
                    }
                    foreach ($this->stepsFrom($at) as $step) {
                        $target = $step->target();
                        if (isset($visited[$target]) || (isset($found[$target]) && ! isset($layer[$target]))) {
                            continue; // already reached by a shorter path
                        }
                        if (count($layer[$target] ?? []) < $perTarget) {
                            $layer[$target][] = [...$path, $step];
                        }
                    }
                }
            }
            foreach ($layer as $target => $paths) {
                $found[$target] = $paths;
            }
            $frontier = array_keys($layer);
        }

        unset($found[$root]);

        return $found;
    }

    /**
     * Follow explicit relationship ids from $from. Positive ids walk a
     * relationship forward, negative ones backward.
     *
     * @param  list<int>  $ids
     * @return list<Step>|null  null when the ids do not form a walk from $from
     */
    public function walk(string $from, array $ids): ?array
    {
        $steps = [];
        $at = $from;
        foreach ($ids as $signed) {
            $relationship = $this->model->relationship(abs($signed));
            if ($relationship === null) {
                return null;
            }
            $step = new Step($relationship, $signed > 0);
            if ($step->source() !== $at) {
                return null;
            }
            $steps[] = $step;
            $at = $step->target();
        }

        return $steps;
    }

    /** "Visits → Users (user_id) → Lines (line_id)" */
    public function describe(string $root, array $steps): string
    {
        $parts = [$this->model->dataset($root)?->label ?? $root];
        foreach ($steps as $step) {
            $label = $this->model->dataset($step->target())?->label ?? $step->target();
            $via = $step->forward ? $step->sourceColumn() : $step->targetColumn();
            $parts[] = "{$label} ({$via})";
        }

        return implode(' → ', $parts);
    }
}
