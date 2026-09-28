<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MySQL's JSON_CONTAINS(target, candidate) in DQL: `JSON_CONTAINS(p.themes, :value) = 1`, where
 * :value is a JSON document (a JSON-encoded string for "array contains this string").
 */
final class JsonContains extends FunctionNode
{
    private Node $target;
    private Node $candidate;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->target = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->candidate = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf('JSON_CONTAINS(%s, %s)', $this->target->dispatch($sqlWalker), $this->candidate->dispatch($sqlWalker));
    }
}
