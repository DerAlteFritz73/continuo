<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: MATCH_AGAINST(col1, col2, ..., :query) → MATCH(col1, col2, ...) AGAINST (:query IN BOOLEAN MODE)
 *
 * All arguments except the last are treated as columns; the last is the search query.
 * The columns must belong to the same FULLTEXT index.
 */
class MatchAgainstFunction extends FunctionNode
{
    /** @var Node[] */
    private array $columns = [];
    private Node $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $args = [$parser->ArithmeticPrimary()];

        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $args[] = $parser->ArithmeticPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);

        $this->query   = array_pop($args);
        $this->columns = $args;
    }

    public function getSql(SqlWalker $walker): string
    {
        $cols = implode(', ', array_map(fn(Node $c) => $c->dispatch($walker), $this->columns));
        $q    = $this->query->dispatch($walker);

        return "MATCH($cols) AGAINST ($q IN BOOLEAN MODE)";
    }
}
