<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Semantic\Published\PublishedDataset;

/**
 * A dataset instance in the join tree. The same dataset reached by two
 * different paths (the visit's rep and the rep's manager, both users) is
 * two nodes with two aliases.
 */
final class JoinNode
{
    /** @param  list<int>  $path  signed relationship ids from the root */
    public function __construct(
        public readonly string $key,
        public readonly string $alias,
        public readonly PublishedDataset $dataset,
        public readonly array $path,
        public readonly ?JoinNode $parent = null,
        public readonly ?Step $step = null,
    ) {
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    /** Whether joining this node multiplies the rows of its parent. */
    public function fansOut(): bool
    {
        return $this->step?->fansOut() ?? false;
    }

    /** True if $node is this node or one of its descendants. */
    public function contains(JoinNode $node): bool
    {
        for ($at = $node; $at !== null; $at = $at->parent) {
            if ($at === $this) {
                return true;
            }
        }

        return false;
    }

    /** @return list<JoinNode> root first, this node last */
    public function lineage(): array
    {
        $chain = [];
        for ($at = $this; $at !== null; $at = $at->parent) {
            array_unshift($chain, $at);
        }

        return $chain;
    }

    public static function keyFor(array $path): string
    {
        return $path === [] ? 'root' : implode('.', $path);
    }
}
