<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One placeholder of an SQL text outside quoted strings: a positional `?` or a named `:name`, with where it stands.
 */
class DatabaseStatementPlaceholder extends ValueObject
{
    /** @var int The byte offset of the placeholder in the SQL text */
    public int $offset = 0;

    /** @var int The byte length of the placeholder (1 for `?`) */
    public int $length = 1;

    /** @var string|null The name of a named placeholder (without the colon); null for a positional one */
    public ?string $name = null;

    /**
     * @param int $offset
     * @param int $length
     * @param string|null $name
     * @return static
     */
    public static function at(int $offset, int $length, ?string $name): static
    {
        $databaseStatementPlaceholder = new static();
        $databaseStatementPlaceholder->offset = $offset;
        $databaseStatementPlaceholder->length = $length;
        $databaseStatementPlaceholder->name = $name;
        return $databaseStatementPlaceholder;
    }
}
