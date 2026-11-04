<?php

use Abdalla\ReportGenerator\Query\Compiler\Dialects\MySqlDialect;
use Abdalla\ReportGenerator\Query\Compiler\Dialects\PostgresDialect;
use Abdalla\ReportGenerator\Query\Compiler\Dialects\SqliteDialect;
use Abdalla\ReportGenerator\Query\Formula\FormulaException;
use Abdalla\ReportGenerator\Query\Formula\Parser;
use Abdalla\ReportGenerator\Query\Formula\Transcriber;

function sqlOf(string $formula, $dialect = null): string
{
    $transcriber = new Transcriber($dialect ?? new MySqlDialect(), fn (string $name) => "`{$name}`");

    return $transcriber->sql(Parser::parse($formula));
}

it('respects precedence', function () {
    expect(sqlOf('a + b * c'))->toBe('((`a`) + ((`b`) * (`c`)))')
        ->and(sqlOf('(a + b) * c'))->toBe('(((`a`) + (`b`)) * (`c`))')
        ->and(sqlOf('-a + 2'))->toBe('((-(`a`)) + 2)')
        ->and(sqlOf('a > 1 AND NOT b = 2 OR c'))->toBe('((((`a`) > 1) AND (NOT ((`b`) = 2))) OR (`c`))');
});

it('never divides by zero or truncates integers', function () {
    expect(sqlOf('a / b'))->toBe('((`a`) * 1.0 / NULLIF((`b`), 0))')
        ->and(sqlOf('SAFE_DIVIDE(a, b)'))->toBe('((`a`) * 1.0 / NULLIF((`b`), 0))');
});

it('translates functions per database', function () {
    expect(sqlOf("IF(a > 1, 'big', 'small')"))->toBe("(CASE WHEN ((`a`) > 1) THEN 'big' ELSE 'small' END)")
        ->and(sqlOf('GREATEST(a, b)', new SqliteDialect()))->toBe('MAX((`a`), (`b`))')
        ->and(sqlOf("CONCAT(a, ' ', b)", new SqliteDialect()))->toBe("(COALESCE((`a`), '') || COALESCE(' ', '') || COALESCE((`b`), ''))")
        ->and(sqlOf('TRUE', new PostgresDialect()))->toBe('TRUE')
        ->and(sqlOf('TRUE'))->toBe('1')
        ->and(sqlOf('round(a, 1)'))->toBe('ROUND((`a`), 1)');
});

it('lists the fields a formula uses', function () {
    expect(Parser::parse('IF(done > 0, done / visits, NULL)')->references())->toBe(['done', 'visits']);
});

it('rejects what it cannot make safe', function (string $formula, string $message) {
    expect(fn () => sqlOf($formula))->toThrow(FormulaException::class, $message);
})->with([
    ['SLEEP(5)', 'Unknown function SLEEP'],
    ["a = 'x''; DROP TABLE users; --'", 'Unexpected'],
    ["'it\\'s'", 'missing its closing quote'],
    ["'back\\slash'", 'Text in a formula'],
    ['ROUND()', 'ROUND takes 1 to 2 arguments'],
    ['a +', 'ends too early'],
    ['(a + b', 'Missing closing parenthesis'],
    ['a ; b', 'Unexpected character ";"'],
]);
