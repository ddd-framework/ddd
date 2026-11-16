<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The columns the ids a run created are read through, in priority order: a table is read through the FIRST of them it
 * has (and that has values).
 *
 * @property DatabaseRowScopeColumn[] $elements
 * @method DatabaseRowScopeColumn first()
 * @method DatabaseRowScopeColumn[] getElements()
 */
class DatabaseRowScopeColumns extends ObjectSet
{
    /**
     * The set from a column → values map in priority order (the map is wrapped right here).
     *
     * @param array<string, int[]> $valuesByColumn Column → the run's values, in priority order
     * @param string[] $columnNamesAlsoSelectingRowsById The columns whose values also select rows by their own id
     * @return static
     */
    public static function fromValuesByColumn(array $valuesByColumn, array $columnNamesAlsoSelectingRowsById = []): static
    {
        $databaseRowScopeColumns = new static();
        foreach ($valuesByColumn as $columnName => $values) {
            $databaseRowScopeColumn = DatabaseRowScopeColumn::withValues(
                (string)$columnName,
                $values,
                in_array((string)$columnName, $columnNamesAlsoSelectingRowsById, true)
            );
            $databaseRowScopeColumns->add($databaseRowScopeColumn);
        }
        return $databaseRowScopeColumns;
    }

    /**
     * The first column, in priority order, that has values and is one of the table's columns.
     *
     * @param string[] $columnNamesOfTable
     * @return DatabaseRowScopeColumn|null Null: the table is read without a scope
     */
    public function findFirstColumnAmong(array $columnNamesOfTable): ?DatabaseRowScopeColumn
    {
        foreach ($this->getElements() as $databaseRowScopeColumn) {
            if ($databaseRowScopeColumn->hasValues() && in_array($databaseRowScopeColumn->columnName, $columnNamesOfTable, true)) {
                return $databaseRowScopeColumn;
            }
        }
        return null;
    }
}
