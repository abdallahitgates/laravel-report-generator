<?php

use Abdalla\ReportGenerator\Query\Formula\Evaluator;
use Abdalla\ReportGenerator\Query\Formula\FormulaException;
use Abdalla\ReportGenerator\Query\Formula\Parser;

function evaluate(string $formula, array $row = []): mixed
{
    return (new Evaluator(fn (string $ref) => $row[$ref] ?? null))->evaluate(Parser::parse($formula));
}

it('does arithmetic like the SQL it mirrors', function () {
    expect(evaluate('1 + 2 * 3'))->toBe(7)
        ->and(evaluate('(a - b) / b', ['a' => 15, 'b' => 10]))->toBe(0.5)
        ->and(evaluate('7 % 4'))->toBe(3.0)
        ->and(evaluate('-a', ['a' => 2]))->toBe(-2);
});

it('gives an empty value for empties and division by zero', function () {
    expect(evaluate('a + 1', ['a' => null]))->toBeNull()
        ->and(evaluate('a / 0', ['a' => 5]))->toBeNull()
        ->and(evaluate('SAFE_DIVIDE(a, b)', ['a' => 1, 'b' => 0]))->toBeNull()
        ->and(evaluate('COALESCE(a, b, 3)', ['a' => null, 'b' => null]))->toBe(3)
        ->and(evaluate('NULLIF(a, 0)', ['a' => 0]))->toBeNull()
        ->and(evaluate('a > 1', ['a' => null]))->toBeNull();
});

it('supports conditions and functions', function () {
    expect(evaluate("IF(visits >= 10, 'busy', 'quiet')", ['visits' => 12]))->toBe('busy')
        ->and(evaluate('ROUND(a, 1)', ['a' => 2.345]))->toBe(2.3)
        ->and(evaluate('ABS(a)', ['a' => -4]))->toBe(4)
        ->and(evaluate('GREATEST(a, b, 3)', ['a' => 1, 'b' => 9]))->toBe(9)
        ->and(evaluate('LEAST(a, b)', ['a' => 1, 'b' => 9]))->toBe(1)
        ->and(evaluate("CONCAT(UPPER(n), '-', LOWER(m))", ['n' => 'ab', 'm' => 'CD']))->toBe('AB-cd')
        ->and(evaluate('a > 1 AND b < 2', ['a' => 2, 'b' => 1]))->toBeTrue()
        ->and(evaluate('a > 1 OR b < 2', ['a' => 0, 'b' => 5]))->toBeFalse()
        ->and(evaluate('NOT a = 1', ['a' => 2]))->toBeTrue();
});

it('rejects unknown functions and text in arithmetic', function () {
    expect(fn () => evaluate('NOPE(1)'))->toThrow(FormulaException::class, 'Unknown function NOPE')
        ->and(fn () => evaluate("a * 2", ['a' => 'x']))->toThrow(FormulaException::class);
});
