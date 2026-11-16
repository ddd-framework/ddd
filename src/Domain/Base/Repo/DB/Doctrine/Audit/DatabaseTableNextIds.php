<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The next ids of the tables of a schema, one element per table, in catalog order.
 *
 * @property DatabaseTableNextId[] $elements
 * @method DatabaseTableNextId first()
 * @method DatabaseTableNextId[] getElements()
 */
class DatabaseTableNextIds extends ObjectSet
{
    /** The id column a table with several auto-increment columns is read through, when it has one of this name. */
    public const string PREFERRED_ID_COLUMN_NAME = 'id';

    /**
     * The set from the rows of the catalog read ({@see DatabaseWriteStatementAudit::buildNextIdsOfTablesSql()}),
     * wrapped right here: `TABLE_NAME`, `AUTO_INCREMENT` (the next id, null when unknown) and, when read, `COLUMN_NAME`.
     * A table with several identity or serial columns (PostgreSQL) keeps the column named `id`, else the first in the
     * rows' order (the read orders by column position).
     *
     * @param array<int, array<string, mixed>> $catalogRows The rows as the connection fetched them
     * @return static
     */
    public static function fromCatalogRows(array $catalogRows): static
    {
        $databaseTableNextIds = new static();
        foreach ($catalogRows as $catalogRow) {
            $tableName = (string)$catalogRow['TABLE_NAME'];
            $idColumnName = isset($catalogRow['COLUMN_NAME']) ? (string)$catalogRow['COLUMN_NAME'] : null;
            $nextId = isset($catalogRow['AUTO_INCREMENT']) ? (int)$catalogRow['AUTO_INCREMENT'] : null;
            $existingTableNextId = $databaseTableNextIds->findByTableName($tableName);
            if ($existingTableNextId !== null) {
                if ($idColumnName === self::PREFERRED_ID_COLUMN_NAME && $existingTableNextId->idColumnName !== self::PREFERRED_ID_COLUMN_NAME) {
                    $existingTableNextId->idColumnName = $idColumnName;
                    $existingTableNextId->nextId = $nextId;
                }
                continue;
            }
            $databaseTableNextId = DatabaseTableNextId::forTable($tableName, $idColumnName, $nextId);
            $databaseTableNextIds->add($databaseTableNextId);
        }
        return $databaseTableNextIds;
    }

    /**
     * @param string $tableName
     * @return DatabaseTableNextId|null
     */
    public function findByTableName(string $tableName): ?DatabaseTableNextId
    {
        /** @var DatabaseTableNextId|null $databaseTableNextId */
        $databaseTableNextId = $this->getByUniqueKey(DatabaseTableNextId::buildUniqueKeyOfTable($tableName));
        return $databaseTableNextId;
    }
}
