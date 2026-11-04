<?php

namespace Abdalla\ReportGenerator\Query\Formula;

final class Token
{
    public const NUMBER = 'number';
    public const STRING = 'string';
    public const IDENT = 'ident';
    public const OP = 'op';
    public const LPAREN = '(';
    public const RPAREN = ')';
    public const COMMA = ',';
    public const END = 'end';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $position,
    ) {
    }
}
