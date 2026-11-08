<?php

namespace Abdalla\ReportGenerator\Query\Formula;

use Closure;

/**
 * Evaluates a formula in PHP with the same rules as the SQL it would
 * compile to: empty values make the result empty, dividing by zero gives
 * an empty value rather than an error, comparisons with empty are empty.
 * Used for combined reports, whose rows come from several reports.
 */
final class Evaluator
{
    /** @param  Closure(string): mixed  $resolve  field name -> value */
    public function __construct(private readonly Closure $resolve)
    {
    }

    public function evaluate(Node $node): mixed
    {
        return match ($node->kind) {
            'number' => is_numeric($node->value) ? $node->value + 0 : throw new FormulaException("\"{$node->value}\" is not a number."),
            'string' => $node->value,
            'bool' => $node->value === 'TRUE',
            'null' => null,
            'ref' => ($this->resolve)($node->value),
            'unary' => $this->unary($node),
            'binary' => $this->binary($node),
            'call' => $this->call($node),
            default => throw new FormulaException("Unsupported formula part: {$node->kind}."),
        };
    }

    private function unary(Node $node): mixed
    {
        $value = $this->evaluate($node->children[0]);
        if ($value === null) {
            return null;
        }

        return $node->value === 'NOT' ? ! $value : -$this->number($value);
    }

    private function binary(Node $node): mixed
    {
        $op = $node->value;
        if ($op === 'AND' || $op === 'OR') {
            $left = $this->evaluate($node->children[0]);
            $right = $this->evaluate($node->children[1]);
            if ($op === 'AND') {
                return ($left === false || $right === false) ? false : (($left === null || $right === null) ? null : true);
            }

            return ($left === true || $right === true || ($left && $left !== null) || ($right && $right !== null)) ? true : (($left === null || $right === null) ? null : false);
        }

        $left = $this->evaluate($node->children[0]);
        $right = $this->evaluate($node->children[1]);
        if ($left === null || $right === null) {
            return null;
        }

        return match ($op) {
            '+' => $this->number($left) + $this->number($right),
            '-' => $this->number($left) - $this->number($right),
            '*' => $this->number($left) * $this->number($right),
            '/' => $this->number($right) == 0 ? null : $this->number($left) / $this->number($right),
            '%' => $this->number($right) == 0 ? null : fmod($this->number($left), $this->number($right)),
            '=' => $this->compare($left, $right) === 0,
            '<>' => $this->compare($left, $right) !== 0,
            '<' => $this->compare($left, $right) < 0,
            '<=' => $this->compare($left, $right) <= 0,
            '>' => $this->compare($left, $right) > 0,
            '>=' => $this->compare($left, $right) >= 0,
            default => throw new FormulaException("Unsupported operator {$op}."),
        };
    }

    private function call(Node $node): mixed
    {
        $name = $node->value;
        if (! isset(Transcriber::FUNCTIONS[$name])) {
            throw new FormulaException("Unknown function {$name}. Available: ".implode(', ', array_keys(Transcriber::FUNCTIONS)).'.');
        }
        [$min, $max] = Transcriber::FUNCTIONS[$name];
        $count = count($node->children);
        if ($count < $min || ($max !== null && $count > $max)) {
            throw new FormulaException("{$name} takes ".($max === $min ? $min : "{$min} or more")." arguments, got {$count}.");
        }

        if ($name === 'IF') {
            return $this->evaluate($node->children[0]) ? $this->evaluate($node->children[1]) : $this->evaluate($node->children[2]);
        }
        $args = array_map(fn (Node $n) => $this->evaluate($n), $node->children);

        return match ($name) {
            'NULLIF' => $args[0] !== null && $args[1] !== null && $this->compare($args[0], $args[1]) === 0 ? null : $args[0],
            'COALESCE' => array_values(array_filter($args, fn ($a) => $a !== null))[0] ?? null,
            'ROUND' => $args[0] === null ? null : round($this->number($args[0]), (int) ($args[1] ?? 0)),
            'ABS' => $args[0] === null ? null : abs($this->number($args[0])),
            'GREATEST' => in_array(null, $args, true) ? null : max(array_map(fn ($a) => $this->number($a), $args)),
            'LEAST' => in_array(null, $args, true) ? null : min(array_map(fn ($a) => $this->number($a), $args)),
            'LOWER' => $args[0] === null ? null : mb_strtolower((string) $args[0]),
            'UPPER' => $args[0] === null ? null : mb_strtoupper((string) $args[0]),
            'CONCAT' => implode('', array_map(fn ($a) => (string) $a, $args)),
            'SAFE_DIVIDE' => $args[0] === null || $args[1] === null || $this->number($args[1]) == 0 ? null : $this->number($args[0]) / $this->number($args[1]),
        };
    }

    private function number(mixed $value): int|float
    {
        if (is_bool($value)) {
            return (int) $value;
        }
        if (! is_numeric($value)) {
            throw new FormulaException('"'.(is_scalar($value) ? $value : gettype($value)).'" is not a number.');
        }

        return $value + 0;
    }

    private function compare(mixed $left, mixed $right): int
    {
        return is_numeric($left) && is_numeric($right) ? ($left + 0) <=> ($right + 0) : strcmp((string) $left, (string) $right);
    }
}
