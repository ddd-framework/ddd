<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MariaDB's rendering of the DQL function `JSON_FIELD_TEXT(expr, key)`: the text value of one top-level key of a JSON
 * column, the same as {@see \DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect::jsonFieldText()} writes in raw SQL.
 * PostgreSQL renders the same name with {@see JsonFieldText}, so one DQL condition on a JSON key runs on both engines.
 * Registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect::configureOrm()}.
 *
 * DQL: `JSON_FIELD_TEXT(expr, 'key')`
 * SQL: `JSON_UNQUOTE(JSON_EXTRACT(expr, CONCAT('$."', 'key', '"')))`
 *
 * An absent key answers NULL; a JSON null answers the text `null` (as the raw-SQL form).
 */
class MariaDbJsonFieldText extends FunctionNode
{
    protected mixed $expression;

    protected mixed $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);
        $keySql = $this->key->dispatch($sqlWalker);

        return "JSON_UNQUOTE(JSON_EXTRACT($expressionSql, CONCAT('$.\"', $keySql, '\"')))";
    }
}
