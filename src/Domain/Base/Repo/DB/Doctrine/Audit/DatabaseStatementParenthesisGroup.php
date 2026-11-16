<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The text inside one parenthesis group of an SQL text (one row of a `VALUES` list) and the offset right after its
 * closing parenthesis.
 */
class DatabaseStatementParenthesisGroup extends ValueObject
{
    /** @var string The text between the parentheses */
    public string $content = '';

    /** @var int The byte offset right after the closing parenthesis */
    public int $offsetAfterGroup = 0;

    /**
     * @param string $content
     * @param int $offsetAfterGroup
     * @return static
     */
    public static function withContent(string $content, int $offsetAfterGroup): static
    {
        $databaseStatementParenthesisGroup = new static();
        $databaseStatementParenthesisGroup->content = $content;
        $databaseStatementParenthesisGroup->offsetAfterGroup = $offsetAfterGroup;
        return $databaseStatementParenthesisGroup;
    }
}
