<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One statement that writes, as two runs compare it ({@see DatabaseWriteStatement::buildComparableShape()}): the
 * kind, the table, the written columns and rows (INSERT / REPLACE), the assignments (UPDATE), the condition with its
 * bound values (UPDATE / DELETE) — or, for a statement the parser does not understand, its raw SQL and parameters.
 * {@see $layout} says which of these the statement has; {@see toArray()} is the stored document of a comparison.
 */
class DatabaseWriteStatementShape extends ValueObject
{
    /** INSERT / REPLACE: kind, table, columns, rows, the conflict clause */
    public const string LAYOUT_ROWS = 'ROWS';

    /** UPDATE: kind, table, assignments, condition, condition values */
    public const string LAYOUT_ASSIGNMENTS = 'ASSIGNMENTS';

    /** DELETE: kind, table, condition, condition values */
    public const string LAYOUT_CONDITION = 'CONDITION';

    /** ROLLBACK TO SAVEPOINT: the kind only */
    public const string LAYOUT_KIND_ONLY = 'KIND_ONLY';

    /** A statement the parser does not understand: kind, raw SQL, raw parameters */
    public const string LAYOUT_UNPARSED = 'UNPARSED';

    /** @var string Which parts the statement has, one of the LAYOUT_ constants */
    public string $layout = self::LAYOUT_UNPARSED;

    /** @var string The statement kind ({@see DatabaseWriteStatement::KIND_INSERT} …) */
    public string $kind = '';

    /** @var string|null The written table */
    public ?string $table = null;

    /** @var string[] The written columns of an INSERT / REPLACE, in order */
    public array $columns = [];

    /** @var DatabaseWrittenRows The rows of an INSERT / REPLACE */
    public DatabaseWrittenRows $rows;

    /**
     * @var string The stored key of the conflict clause of an INSERT, named after the clause of the statement's
     *      database (`onDuplicateKeyUpdate` on MariaDB, `onConflict` on PostgreSQL)
     */
    public string $conflictClauseKey = '';

    /** @var mixed What follows the conflict clause, bound (`SQL:<text>`); null without one. Read through {@see getConflictClauseValue()} */
    protected mixed $conflictClauseValue = null;

    /**
     * @var array<string, mixed> The assignments of an UPDATE: column → its bound value or `SQL:<expression>`. Read
     *      through {@see toArray()} or {@see getAssignedValueOfColumn()}
     */
    protected array $assignedValuesByColumn = [];

    /** @var string The condition of an UPDATE / DELETE, whitespace collapsed ('' without one) */
    public string $condition = '';

    /** @var DatabaseConditionValues The bound values of the condition, in placeholder order */
    public DatabaseConditionValues $conditionValues;

    /** @var string The raw SQL of a statement the parser does not understand */
    public string $unparsedSql = '';

    /** @var array<int|string, mixed> The raw bound parameters of a statement the parser does not understand */
    public array $unparsedParameters = [];

    public function __construct()
    {
        $this->rows = new DatabaseWrittenRows();
        $this->conditionValues = new DatabaseConditionValues();
        parent::__construct();
    }

    /**
     * @param mixed $conflictClauseValue What follows the conflict clause, bound
     * @return void
     */
    public function setConflictClauseValue(mixed $conflictClauseValue): void
    {
        $this->conflictClauseValue = $conflictClauseValue;
    }

    /**
     * @return mixed What follows the conflict clause of an INSERT, bound (`SQL:<text>`); null without one
     */
    public function getConflictClauseValue(): mixed
    {
        return $this->conflictClauseValue;
    }

    /**
     * Sets the value an UPDATE assigns to a column.
     *
     * @param string $column
     * @param mixed $assignedValue The bound value or `SQL:<expression>`
     * @return void
     */
    public function assignValueToColumn(string $column, mixed $assignedValue): void
    {
        $this->assignedValuesByColumn[$column] = $assignedValue;
    }

    /**
     * @param string $column
     * @return mixed The value an UPDATE assigns to the column; null when it assigns none
     */
    public function getAssignedValueOfColumn(string $column): mixed
    {
        return $this->assignedValuesByColumn[$column] ?? null;
    }

    /**
     * Every row value, assignment and condition value normalised as the run declares it, under the statement's table.
     *
     * @param DatabaseWriteStatementNormalization $databaseWriteStatementNormalization
     * @return void
     */
    public function applyNormalization(DatabaseWriteStatementNormalization $databaseWriteStatementNormalization): void
    {
        $this->rows->applyNormalization($databaseWriteStatementNormalization, $this->table);
        foreach ($this->assignedValuesByColumn as $column => $assignedValue) {
            $this->assignedValuesByColumn[$column] = $databaseWriteStatementNormalization->normalizeValue($this->table, (string)$column, $assignedValue);
        }
        foreach ($this->conditionValues->getElements() as $databaseConditionValue) {
            $databaseConditionValue->value = $databaseWriteStatementNormalization->normalizeValue($this->table, $databaseConditionValue->column, $databaseConditionValue->value);
        }
    }

    /**
     * The stored document of the statement, the keys in this order per layout:
     * ROWS `kind, table, columns, rows, <conflict clause key>`; ASSIGNMENTS `kind, table, set, where, whereValues`;
     * CONDITION `kind, table, where, whereValues`; KIND_ONLY `kind`; UNPARSED `kind, unparsedSql, parameters`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->layout) {
            self::LAYOUT_ROWS => [
                'kind' => $this->kind,
                'table' => $this->table,
                'columns' => $this->columns,
                'rows' => $this->rows->toArray(),
                $this->conflictClauseKey => $this->conflictClauseValue,
            ],
            self::LAYOUT_ASSIGNMENTS => [
                'kind' => $this->kind,
                'table' => $this->table,
                'set' => $this->assignedValuesByColumn,
                'where' => $this->condition,
                'whereValues' => $this->conditionValues->toArray(),
            ],
            self::LAYOUT_CONDITION => [
                'kind' => $this->kind,
                'table' => $this->table,
                'where' => $this->condition,
                'whereValues' => $this->conditionValues->toArray(),
            ],
            self::LAYOUT_KIND_ONLY => ['kind' => $this->kind],
            default => ['kind' => $this->kind, 'unparsedSql' => $this->unparsedSql, 'parameters' => $this->unparsedParameters],
        };
    }
}
