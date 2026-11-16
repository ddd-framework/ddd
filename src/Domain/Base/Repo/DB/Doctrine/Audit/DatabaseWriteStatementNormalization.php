<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\Comparisons\ComparisonIdOrdinals;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;

/**
 * What the statement audit may normalise when two runs of one call are compared — everything else is compared raw.
 * All of it is DECLARED by the caller; nothing is guessed:
 *
 * - **minted ids**: `table.column` → id family; only ids above the family's high-water mark (the highest id of its
 *   table at call start, {@see ComparisonIdOrdinals}) become ordinals, a pre-existing id stays raw;
 * - **now-moments of sites without a pinned clock**: a value of a time-named column (`created`, `updated`, `modified`,
 *   `…At`, `…Time`, `…Timestamp`) that lies inside the wall-clock window reads `<clock>` — a moment the pinned clock
 *   answered stays raw. The rule is {@see RecordedRunWallClockNormalization} ({@see $wallClockNormalization}), the
 *   same one the dispatch capture applies; it also applies to the keys of a JSON value written into a column or bound
 *   into an SQL expression (a `decidedAt` inside a `JSON_MERGE_PATCH` payload), together with the declared
 *   derived-moment and duration keys;
 * - **derived moments**: a moment COMPUTED from the wall clock (now + a TTL, a next firing) lies outside the window,
 *   so the window cannot see it. The caller lists such columns (`table.column`) in {@see $derivedMomentColumns}; any
 *   non-null value there reads `<derived-clock>`. A derived moment in a column not on the list is compared raw and
 *   shows as a difference until the column is declared.
 *
 * One object per normalised run (it holds the ordinals of that run).
 */
class DatabaseWriteStatementNormalization
{
    public const string CLOCK_PLACEHOLDER = RecordedRunWallClockNormalization::CLOCK_PLACEHOLDER;
    public const string DERIVED_CLOCK_PLACEHOLDER = RecordedRunWallClockNormalization::DERIVED_CLOCK_PLACEHOLDER;

    /** A column whose NAME says it holds a moment — the only columns the clock window applies to. */
    public const string TIME_COLUMN_NAME_PATTERN = RecordedRunWallClockNormalization::TIME_NAME_PATTERN;

    /** @var array<string, string> `table.column` → id family */
    public array $idFieldFamilies = [];

    /** The ordinals of the ids minted during the call, per id family. */
    public ComparisonIdOrdinals $comparisonIdOrdinals;

    /** The wall-clock rule: the window, the pinned moments, the declared derived-moment and duration keys. */
    public RecordedRunWallClockNormalization $wallClockNormalization;

    /** @var string[] `table.column` of the columns that hold moments derived from the wall clock */
    public array $derivedMomentColumns = [];

    public function __construct()
    {
        $this->comparisonIdOrdinals = new ComparisonIdOrdinals();
        $this->wallClockNormalization = new RecordedRunWallClockNormalization();
    }

    /**
     * @param array<string, string> $idFieldFamilies `table.column` → id family
     * @param array<string, int> $highWaterMarksByFamily Id family → the highest id of its table at call start
     * @param RecordedRunClockWindow|null $clockWindow The wall-clock window of the call
     * @param array<int, string|int> $pinnedMoments
     * @param string[] $derivedMomentColumns `table.column`
     * @param RecordedRunWallClockNormalization|null $wallClockNormalization The run's wall-clock rule; when given it
     *        replaces $clockWindow and $pinnedMoments
     * @return static
     */
    public static function declare(array $idFieldFamilies = [], array $highWaterMarksByFamily = [], ?RecordedRunClockWindow $clockWindow = null, array $pinnedMoments = [], array $derivedMomentColumns = [], ?RecordedRunWallClockNormalization $wallClockNormalization = null): static
    {
        $databaseWriteStatementNormalization = new static();
        $databaseWriteStatementNormalization->idFieldFamilies = $idFieldFamilies;
        $databaseWriteStatementNormalization->comparisonIdOrdinals = ComparisonIdOrdinals::withHighWaterMarks($highWaterMarksByFamily);
        $databaseWriteStatementNormalization->wallClockNormalization = $wallClockNormalization ?? RecordedRunWallClockNormalization::declare($clockWindow, $pinnedMoments);
        $databaseWriteStatementNormalization->derivedMomentColumns = $derivedMomentColumns;
        return $databaseWriteStatementNormalization;
    }

    /**
     * One written or compared value as two runs compare it.
     *
     * @param string|null $table
     * @param string|null $column
     * @param mixed $value
     * @return mixed
     */
    public function normalizeValue(?string $table, ?string $column, mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(fn (mixed $listValue): mixed => $this->normalizeValue($table, $column, $listValue), $value);
        }
        if (is_array($value)) {
            // a JSON object written into a column: its keys follow the wall-clock rule
            return $this->wallClockNormalization->normalizeStructure($value);
        }
        $qualifiedColumn = $table !== null && $column !== null ? $table . '.' . $column : null;
        if ($qualifiedColumn !== null && isset($this->idFieldFamilies[$qualifiedColumn]) && (is_int($value) || is_string($value))) {
            return $this->comparisonIdOrdinals->normalizeId($this->idFieldFamilies[$qualifiedColumn], $value);
        }
        if ($qualifiedColumn !== null && $value !== null && in_array($qualifiedColumn, $this->derivedMomentColumns, true)) {
            return self::DERIVED_CLOCK_PLACEHOLDER;
        }
        return $this->wallClockNormalization->normalizeValueUnderName($column, $value);
    }

    /**
     * A bound parameter before the statement is parsed: a JSON payload in it (bound into an SQL expression such as
     * `JSON_MERGE_PATCH(…, :payload)`, where no column name reaches its keys) gets the wall-clock rule on its keys.
     *
     * @param mixed $parameterValue
     * @return mixed
     */
    public function normalizeBoundParameter(mixed $parameterValue): mixed
    {
        return is_string($parameterValue) ? $this->wallClockNormalization->normalizeJsonText($parameterValue) : $parameterValue;
    }
}
