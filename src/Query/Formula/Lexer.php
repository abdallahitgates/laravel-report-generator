<?php

namespace Abdalla\ReportGenerator\Query\Formula;

final class Lexer
{
    /** @return list<Token> */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                preg_match('/\G\d*\.?\d+(?:[eE][+-]?\d+)?|\G\d+\.?/', $source, $m, 0, $i);
                $tokens[] = new Token(Token::NUMBER, $m[0], $i);
                $i += strlen($m[0]);
                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $i);
                $tokens[] = new Token(Token::IDENT, $m[0], $i);
                $i += strlen($m[0]);
                continue;
            }

            if ($char === "'" || $char === '"') {
                $end = strpos($source, $char, $i + 1);
                if ($end === false) {
                    throw new FormulaException('A text value is missing its closing quote.', $i);
                }
                $tokens[] = new Token(Token::STRING, substr($source, $i + 1, $end - $i - 1), $i);
                $i = $end + 1;
                continue;
            }

            foreach (['<=', '>=', '!=', '<>', '=', '<', '>', '+', '-', '*', '/', '%'] as $op) {
                if (substr_compare($source, $op, $i, strlen($op)) === 0) {
                    $tokens[] = new Token(Token::OP, $op, $i);
                    $i += strlen($op);
                    continue 2;
                }
            }

            if (in_array($char, ['(', ')', ','], true)) {
                $tokens[] = new Token($char, $char, $i);
                $i++;
                continue;
            }

            throw new FormulaException("Unexpected character \"{$char}\".", $i);
        }

        $tokens[] = new Token(Token::END, '', $length);

        return $tokens;
    }
}
