<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

/** A piece of SQL with its positional bindings. */
final class Fragment
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {
    }

    /** @param  list<Fragment>  $parts */
    public static function join(array $parts, string $glue): self
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        return new self(
            '('.implode(" {$glue} ", array_map(fn (Fragment $p) => $p->sql, $parts)).')',
            array_merge(...array_map(fn (Fragment $p) => $p->bindings, $parts)),
        );
    }
}
