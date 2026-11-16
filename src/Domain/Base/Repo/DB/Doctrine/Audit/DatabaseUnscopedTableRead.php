<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A table whose created ids were read WITHOUT a scope (it has none of the scope columns): how many ids the read
 * returned and how many were allocated since the mark — on a shared database the two differ when other runs wrote too.
 */
class DatabaseUnscopedTableRead extends ValueObject
{
    /** @var string The table */
    public string $tableName = '';

    /** @var int How many ids the read returned */
    public int $readIdCount = 0;

    /** @var int How many ids the table allocated since the mark */
    public int $allocatedIdCount = 0;

    /**
     * @param string $tableName
     * @param int $readIdCount
     * @param int $allocatedIdCount
     * @return static
     */
    public static function forTable(string $tableName, int $readIdCount, int $allocatedIdCount): static
    {
        $databaseUnscopedTableRead = new static();
        $databaseUnscopedTableRead->tableName = $tableName;
        $databaseUnscopedTableRead->readIdCount = $readIdCount;
        $databaseUnscopedTableRead->allocatedIdCount = $allocatedIdCount;
        return $databaseUnscopedTableRead;
    }

    /**
     * One element per table.
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->tableName);
    }
}
