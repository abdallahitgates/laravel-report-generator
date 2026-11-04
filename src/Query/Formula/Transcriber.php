<?php

namespace Abdalla\ReportGenerator\Query\Formula;

use Abdalla\ReportGenerator\Query\Compiler\Dialect;
use Closure;

/**
 * Formula AST -> SQL for one dialect. Field names are resolved to their
 * already-compiled SQL by the caller; nothing from the formula text reaches
 * SQL except validated numbers and text from a safe character set.
 */
final class Transcriber
{
    /** name => [min args, max args or null for unlimited] */
    public const FUNCTIONS = [
        'IF' => [3, 3], 'NULLIF' => [2, 2], 'COALESCE' => [1, null], 'ROUND' => [1, 2], 'ABS' => [1, 1],
        'GREATEST' => [2, null], 'LEAST' => [2, null], 'LOWER' => [1, 1], 'UPPER' => [1, 1],
        'CONCAT' => [2, null], 'SAFE_DIVIDE' => [2, 2],
    ];

    /** @param  Closure(string): string  $resolve  field name -> SQL expression */
    public function __construct(
        private readonly Dialect $dialect,
        private readonly Closure $resolve,
    ) {
    }

    public function sql(Node $node): string
    {
        return match ($node->kind) {
            'number' => $this->number($node->value),
            'string' => $this->string($node->value),
            'bool' => $this->dialect->booleanLiteral($node->value === 'TRUE'),
            'null' => 'NULL',
            'ref' => '('.($this->resolve)($node->value).')',
            'unary' => $node->value === 'NOT'
                ? '(NOT '.$this->sql($node->children[0]).')'
                : '(-'.$this->sql($node->children[0]).')',
            'binary' => $this->binary($node),
            'call' => $this->call($node),
        };
    }

    private function binary(Node $node): string
    {
        [$left, $right] = array_map(fn (Node $n) => $this->sql($n), $node->children);

        return match ($node->value) {
            // Never an integer division, never a division-by-zero error: x / 0 is empty.
            '/' => "({$left} * 1.0 / NULLIF({$right}, 0))",
            '%' => "({$left} % NULLIF({$right}, 0))",
            default => "({$left} {$node->value} {$right})",
        };
    }

    private function call(Node $node): string
    {
        $name = $node->value;
        if (! isset(self::FUNCTIONS[$name])) {
            throw new FormulaException("Unknown function {$name}. Available: ".implode(', ', array_keys(self::FUNCTIONS)).'.');
        }

        [$min, $max] = self::FUNCTIONS[$name];
        $count = count($node->children);
        if ($count < $min || ($max !== null && $count > $max)) {
            $expected = $max === $min ? $min : ($max === null ? "at least {$min}" : "{$min} to {$max}");
            throw new FormulaException("{$name} takes {$expected} arguments, got {$count}.");
        }

        $args = array_map(fn (Node $n) => $this->sql($n), $node->children);

        return match ($name) {
            'IF' => "(CASE WHEN {$args[0]} THEN {$args[1]} ELSE {$args[2]} END)",
            'SAFE_DIVIDE' => "({$args[0]} * 1.0 / NULLIF({$args[1]}, 0))",
            'CONCAT' => $this->dialect->concat($args),
            'GREATEST', 'LEAST' => $this->dialect->greatestLeast($name, $args),
            'ROUND' => count($args) === 2 ? "ROUND({$args[0]}, {$args[1]})" : "ROUND({$args[0]})",
            default => $name.'('.implode(', ', $args).')',
        };
    }

    private function number(string $value): string
    {
        if (! is_numeric($value)) {
            throw new FormulaException("\"{$value}\" is not a number.");
        }

        return $value;
    }

    private function string(string $value): string
    {
        // Text in formulas is for labels like 'High'; quotes and backslashes are not allowed,
        // so the literal can be inlined without escaping rules that differ per database.
        if (strlen($value) > 200 || ! preg_match('/^[\p{L}\p{N} _\-.,:\/%#()+&@]*$/u', $value)) {
            throw new FormulaException('Text in a formula can only contain letters, digits, spaces and . , : / % # ( ) + & @ - _ (at most 200 characters).');
        }

        return "'{$value}'";
    }
}
