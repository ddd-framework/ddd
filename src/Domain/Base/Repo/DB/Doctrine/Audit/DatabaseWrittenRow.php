<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One row an INSERT or REPLACE writes: the value of each written column, in column order. A value is the bound value,
 * `SQL:<expression>` for an SQL expression, or the decoded JSON object written into a JSON column.
 */
class DatabaseWrittenRow extends ValueObject
{
    /** @var array<string, mixed> Column → its value; read through {@see toArray()} or {@see getValueOfColumn()} */
    protected array $valuesByColumn = [];

    /**
     * @param array<string, mixed> $valuesByColumn Column → its value, as the parser read it (wrapped right here)
     * @return static
     */
    public static function withValues(array $valuesByColumn): static
    {
        $databaseWrittenRow = new static();
        $databaseWrittenRow->valuesByColumn = $valuesByColumn;
        return $databaseWrittenRow;
    }

    /**
     * @param string $column
     * @return mixed The value written into the column; null when the row does not write it
     */
    public function getValueOfColumn(string $column): mixed
    {
        return $this->valuesByColumn[$column] ?? null;
    }

    /**
     * Every value normalised as the run declares it, under its table and column.
     *
     * @param DatabaseWriteStatementNormalization $databaseWriteStatementNormalization
     * @param string|null $table
     * @return void
     */
    public function applyNormalization(DatabaseWriteStatementNormalization $databaseWriteStatementNormalization, ?string $table): void
    {
        foreach ($this->valuesByColumn as $column => $value) {
            $this->valuesByColumn[$column] = $databaseWriteStatementNormalization->normalizeValue($table, (string)$column, $value);
        }
    }

    /**
     * The stored document of the row: column → value, in column order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->valuesByColumn;
    }
}
