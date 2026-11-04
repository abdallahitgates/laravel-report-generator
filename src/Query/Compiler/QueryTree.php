<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

use Abdalla\ReportGenerator\Query\Planning\JoinNode;

/**
 * The join nodes one SELECT actually uses, with each node's ancestors.
 */
final class QueryTree
{
    /** @var array<string, JoinNode> */
    private array $nodes = [];

    public function __construct(private readonly JoinNode $root)
    {
        $this->nodes[$root->key] = $root;
    }

    public function add(JoinNode $node): void
    {
        foreach ($node->lineage() as $ancestor) {
            $this->nodes[$ancestor->key] ??= $ancestor;
        }
    }

    public function has(JoinNode $node): bool
    {
        return isset($this->nodes[$node->key]);
    }

    public function root(): JoinNode
    {
        return $this->root;
    }

    /** @return list<JoinNode> parents always before their children */
    public function nodes(): array
    {
        $nodes = array_values($this->nodes);
        usort($nodes, fn (JoinNode $a, JoinNode $b) => count($a->path) <=> count($b->path) ?: strcmp($a->key, $b->key));

        return $nodes;
    }

    /**
     * Whether rows of $node repeat in this query's result: some join in the
     * tree multiplies rows, and $node is not on that join's "many" side.
     */
    public function duplicates(JoinNode $node): bool
    {
        return $this->duplicatedBy($node) !== null;
    }

    /** The join that repeats $node's rows, if any. */
    public function duplicatedBy(JoinNode $node): ?JoinNode
    {
        foreach ($this->nodes as $candidate) {
            if ($candidate->fansOut() && ! $candidate->contains($node)) {
                return $candidate;
            }
        }

        return null;
    }
}
