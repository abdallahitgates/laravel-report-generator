<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Semantic\Published\PublishedRelationship;

/**
 * One hop along a relationship. Forward walks from the referencing table to
 * the referenced one (visits -> doctors); backward walks the other way
 * (doctors -> visits). Written as the relationship id, negative when backward.
 */
final class Step
{
    public function __construct(
        public readonly PublishedRelationship $relationship,
        public readonly bool $forward,
    ) {
    }

    public function signedId(): int
    {
        return $this->forward ? $this->relationship->id : -$this->relationship->id;
    }

    public function source(): string
    {
        return $this->forward ? $this->relationship->from : $this->relationship->to;
    }

    public function target(): string
    {
        return $this->forward ? $this->relationship->to : $this->relationship->from;
    }

    public function sourceColumn(): string
    {
        return $this->forward ? $this->relationship->fromColumn : $this->relationship->toColumn;
    }

    public function targetColumn(): string
    {
        return $this->forward ? $this->relationship->toColumn : $this->relationship->fromColumn;
    }

    /** Whether one source row can match many target rows. */
    public function fansOut(): bool
    {
        $cardinality = $this->forward ? $this->relationship->cardinality : $this->relationship->cardinality->inverse();

        return $cardinality->fansOut();
    }
}
