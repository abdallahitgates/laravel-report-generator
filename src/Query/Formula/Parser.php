<?php

namespace Abdalla\ReportGenerator\Query\Formula;

/**
 * Recursive-descent parser for report formulas:
 *
 *   visits / doctors
 *   IF(amount > 1000, 'large', 'small')
 *   ROUND(SAFE_DIVIDE(done_visits, visits) * 100, 1)
 *
 * Precedence, lowest first: OR, AND, NOT, comparison, + -, * / %, unary minus.
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens;
    private int $at = 0;

    public static function parse(string $source): Node
    {
        $parser = new self();
        $parser->tokens = Lexer::tokenize($source);
        $node = $parser->or();
        if ($parser->peek()->type !== Token::END) {
            throw new FormulaException("Unexpected \"{$parser->peek()->value}\".", $parser->peek()->position);
        }

        return $node;
    }

    private function or(): Node
    {
        $node = $this->and();
        while ($this->keyword('OR')) {
            $node = new Node('binary', 'OR', [$node, $this->and()]);
        }

        return $node;
    }

    private function and(): Node
    {
        $node = $this->not();
        while ($this->keyword('AND')) {
            $node = new Node('binary', 'AND', [$node, $this->not()]);
        }

        return $node;
    }

    private function not(): Node
    {
        if ($this->keyword('NOT')) {
            return new Node('unary', 'NOT', [$this->not()]);
        }

        return $this->comparison();
    }

    private function comparison(): Node
    {
        $node = $this->additive();
        $token = $this->peek();
        if ($token->type === Token::OP && in_array($token->value, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            $this->at++;
            $node = new Node('binary', $token->value === '!=' ? '<>' : $token->value, [$node, $this->additive()]);
        }

        return $node;
    }

    private function additive(): Node
    {
        $node = $this->multiplicative();
        while (($t = $this->peek())->type === Token::OP && in_array($t->value, ['+', '-'], true)) {
            $this->at++;
            $node = new Node('binary', $t->value, [$node, $this->multiplicative()]);
        }

        return $node;
    }

    private function multiplicative(): Node
    {
        $node = $this->unary();
        while (($t = $this->peek())->type === Token::OP && in_array($t->value, ['*', '/', '%'], true)) {
            $this->at++;
            $node = new Node('binary', $t->value, [$node, $this->unary()]);
        }

        return $node;
    }

    private function unary(): Node
    {
        $t = $this->peek();
        if ($t->type === Token::OP && $t->value === '-') {
            $this->at++;

            return new Node('unary', '-', [$this->unary()]);
        }

        return $this->primary();
    }

    private function primary(): Node
    {
        $t = $this->next();

        switch ($t->type) {
            case Token::NUMBER:
                return new Node('number', $t->value);
            case Token::STRING:
                return new Node('string', $t->value);
            case Token::LPAREN:
                $node = $this->or();
                $this->expect(Token::RPAREN, 'Missing closing parenthesis.');

                return $node;
            case Token::IDENT:
                $upper = strtoupper($t->value);
                if (in_array($upper, ['TRUE', 'FALSE'], true)) {
                    return new Node('bool', $upper);
                }
                if ($upper === 'NULL') {
                    return new Node('null');
                }
                if ($this->peek()->type === Token::LPAREN) {
                    $this->at++;
                    $args = [];
                    if ($this->peek()->type !== Token::RPAREN) {
                        do {
                            $args[] = $this->or();
                        } while ($this->accept(Token::COMMA));
                    }
                    $this->expect(Token::RPAREN, "Missing closing parenthesis after the arguments of {$t->value}.");

                    return new Node('call', $upper, $args);
                }

                return new Node('ref', $t->value);
            case Token::END:
                throw new FormulaException('The formula ends too early.', $t->position);
            default:
                throw new FormulaException("Unexpected \"{$t->value}\".", $t->position);
        }
    }

    private function keyword(string $word): bool
    {
        $t = $this->peek();
        if ($t->type === Token::IDENT && strtoupper($t->value) === $word) {
            $this->at++;

            return true;
        }

        return false;
    }

    private function accept(string $type): bool
    {
        if ($this->peek()->type === $type) {
            $this->at++;

            return true;
        }

        return false;
    }

    private function expect(string $type, string $message): void
    {
        if (! $this->accept($type)) {
            throw new FormulaException($message, $this->peek()->position);
        }
    }

    private function peek(): Token
    {
        return $this->tokens[$this->at];
    }

    private function next(): Token
    {
        return $this->tokens[$this->at++];
    }
}
