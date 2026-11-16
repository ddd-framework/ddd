<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;

/**
 * One column the ids a run created are read through: a table that has this column is read only for the rows whose
 * value in it is one of {@see $values}. With {@see $alsoSelectsRowsById} the values are row ids as well, so a row whose
 * own id is one of them is read too — a column that points to the top row of a tree, where the top row carries no
 * value in that column itself.
 */
class DatabaseRowScopeColumn extends ValueObject
{
    /** @var string The column name */
    public string $columnName = '';

    /** @var int[] The run's values of the column */
    public array $values = [];

    /** @var bool Whether the values also select the rows with one of them as their own id */
    public bool $alsoSelectsRowsById = false;

    /**
     * @param string $columnName
     * @param int[] $values
     * @param bool $alsoSelectsRowsById
     * @return static
     */
    public static function withValues(string $columnName, array $values, bool $alsoSelectsRowsById = false): static
    {
        $databaseRowScopeColumn = new static();
        $databaseRowScopeColumn->columnName = $columnName;
        $databaseRowScopeColumn->values = array_values(array_map('intval', $values));
        $databaseRowScopeColumn->alsoSelectsRowsById = $alsoSelectsRowsById;
        return $databaseRowScopeColumn;
    }

    /**
     * Whether the column has values to read through.
     *
     * @return bool
     */
    public function hasValues(): bool
    {
        return $this->values !== [];
    }

    /**
     * The condition this column adds to the read of a table's ids, with a leading ` AND `.
     *
     * @param SqlDialect $sqlDialect The database of the read
     * @param string $quotedIdColumn The table's id column, quoted for that database
     * @return string
     */
    public function buildConditionSql(SqlDialect $sqlDialect, string $quotedIdColumn): string
    {
        $valueList = implode(',', $this->values);
        return ' AND (' . $sqlDialect->quote($this->columnName) . ' IN (' . $valueList . ')'
            . ($this->alsoSelectsRowsById ? ' OR ' . $quotedIdColumn . ' IN (' . $valueList . ')' : '') . ')';
    }

    /**
     * One element per column.
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->columnName);
    }
}
