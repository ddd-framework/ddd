<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The id the next row of a table will get, as the database's catalog says (MariaDB's `AUTO_INCREMENT`, the last value
 * of a PostgreSQL identity or serial sequence plus one), with the table's id column.
 */
class DatabaseTableNextId extends ValueObject
{
    /** @var string The table */
    public string $tableName = '';

    /** @var string|null The auto-increment (identity, serial) column; null when the catalog read did not name it */
    public ?string $idColumnName = null;

    /**
     * @var int|null The next id; null while unknown — a PostgreSQL sequence that was never called (or was set with
     *      `setval(…, false)`) has no last value, and the next id is then read from the table's highest id
     */
    public ?int $nextId = null;

    /**
     * @param string $tableName
     * @param string|null $idColumnName
     * @param int|null $nextId
     * @return static
     */
    public static function forTable(string $tableName, ?string $idColumnName, ?int $nextId): static
    {
        $databaseTableNextId = new static();
        $databaseTableNextId->tableName = $tableName;
        $databaseTableNextId->idColumnName = $idColumnName;
        $databaseTableNextId->nextId = $nextId;
        return $databaseTableNextId;
    }

    /**
     * One element per table, also for a subclass ({@see buildUniqueKeyOfTable()}).
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfTable($this->tableName);
    }

    /**
     * The element key of a table, built from this declaring class so that the lookup
     * ({@see DatabaseTableNextIds::findByTableName()}) finds the element of a subclass too.
     *
     * @param string $tableName
     * @return string
     */
    public static function buildUniqueKeyOfTable(string $tableName): string
    {
        return self::class . '_' . $tableName;
    }
}
