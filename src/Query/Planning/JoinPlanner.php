<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Closure;

/**
 * Picks the join path for each dataset a report touches and gives every
 * distinct path its own node (alias) in the join tree.
 *
 * Without an explicit path the shortest one wins. Ties go, in order, to the
 * path that does not multiply rows, then to the one sharing the most joins
 * with paths already chosen for this report. A tie after that is reported
 * as AMBIGUOUS_PATH with the options, so the author picks.
 */
final class JoinPlanner
{
    /** @var array<string, JoinNode> */
    private array $nodes = [];
    private int $nextAlias = 1;
    private JoinNode $root;

    /** @param  Closure(string): void  $assertAllowed  throws when the user may not read a dataset */
    public function __construct(
        private readonly SemanticModel $model,
        private readonly JoinGraph $graph,
        string $root,
        private readonly Closure $assertAllowed,
    ) {
        $dataset = $model->dataset($root)
            ?? throw new QueryException('UNKNOWN_DATASET', "There is no dataset \"{$root}\" in the published data model.", 'root');
        ($this->assertAllowed)($root);
        $this->root = $this->nodes['root'] = new JoinNode('root', 't0', $dataset, []);
    }

    public function root(): JoinNode
    {
        return $this->root;
    }

    public function graph(): JoinGraph
    {
        return $this->graph;
    }

    /** @param  list<int>|null  $explicit */
    public function nodeFor(string $dataset, ?array $explicit, string $location): JoinNode
    {
        if ($this->model->dataset($dataset) === null) {
            throw new QueryException('UNKNOWN_DATASET', "There is no dataset \"{$dataset}\" in the published data model.", $location);
        }

        $steps = $explicit !== null
            ? $this->explicit($dataset, $explicit, $location)
            : $this->choose($dataset, $location);

        return $this->materialize($steps);
    }

    /** @param  list<Step>  $steps */
    public function materialize(array $steps, ?JoinNode $from = null): JoinNode
    {
        $node = $from ?? $this->root;
        foreach ($steps as $step) {
            $path = [...$node->path, $step->signedId()];
            $key = JoinNode::keyFor($path);
            if (! isset($this->nodes[$key])) {
                ($this->assertAllowed)($step->target());
                $this->nodes[$key] = new JoinNode($key, 't'.$this->nextAlias++, $this->model->dataset($step->target()), $path, $node, $step);
            }
            $node = $this->nodes[$key];
        }

        return $node;
    }

    /** @return list<Step> */
    private function explicit(string $dataset, array $ids, string $location): array
    {
        $steps = $this->graph->walk($this->root->dataset->key, $ids);
        $end = $steps === null ? null : ($steps === [] ? $this->root->dataset->key : end($steps)->target());

        if ($end !== $dataset) {
            throw new QueryException('INVALID_PATH', "The chosen path does not lead from {$this->root->dataset->label} to {$this->model->dataset($dataset)->label}.", $location);
        }

        return $steps;
    }

    /** @return list<Step> */
    private function choose(string $dataset, string $location): array
    {
        $root = $this->root->dataset->key;
        if ($dataset === $root) {
            return [];
        }

        $candidates = $this->graph->shortestPaths($root, $dataset);
        if ($candidates === []) {
            $from = $this->root->dataset->label;
            $to = $this->model->dataset($dataset)->label;
            throw new QueryException('NO_PATH', "{$to} is not connected to {$from}. Confirm a relationship between them in Model Studio, or start the report from another dataset.", $location);
        }

        if (count($candidates) > 1) {
            $safe = array_values(array_filter($candidates, fn (array $p) => ! $this->anyFanOut($p)));
            if (count($safe) >= 1) {
                $candidates = $safe;
            }
        }

        if (count($candidates) > 1) {
            $scores = array_map(fn (array $p) => $this->sharedSteps($p), $candidates);
            $best = max($scores);
            $top = array_values(array_filter($candidates, fn ($p, $i) => $scores[$i] === $best, ARRAY_FILTER_USE_BOTH));
            if (count($top) === 1) {
                $candidates = $top;
            }
        }

        if (count($candidates) > 1) {
            $label = $this->model->dataset($dataset)->label;
            throw new QueryException(
                'AMBIGUOUS_PATH',
                "{$label} can be reached from {$this->root->dataset->label} in more than one way. Choose which one this report should use.",
                $location,
                ['options' => array_map(fn (array $p) => [
                    'path' => array_map(fn (Step $s) => $s->signedId(), $p),
                    'description' => $this->graph->describe($root, $p),
                    'multiplies_rows' => $this->anyFanOut($p),
                ], $candidates)],
            );
        }

        return $candidates[0];
    }

    /** @param  list<Step>  $steps */
    private function anyFanOut(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->fansOut()) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Step>  $steps */
    private function sharedSteps(array $steps): int
    {
        $path = [];
        $shared = 0;
        foreach ($steps as $step) {
            $path[] = $step->signedId();
            if (! isset($this->nodes[JoinNode::keyFor($path)])) {
                break;
            }
            $shared++;
        }

        return $shared;
    }
}
