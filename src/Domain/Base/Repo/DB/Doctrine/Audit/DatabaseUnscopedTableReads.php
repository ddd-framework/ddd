<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tables of one created-id read that were read without a scope, in read order.
 *
 * @property DatabaseUnscopedTableRead[] $elements
 * @method DatabaseUnscopedTableRead first()
 * @method DatabaseUnscopedTableRead[] getElements()
 */
class DatabaseUnscopedTableReads extends ObjectSet
{
    /**
     * The stored document: table → `{read, allocated}`.
     *
     * @return array<string, array<string, int>>
     */
    public function toArray(): array
    {
        $storedUnscopedTables = [];
        foreach ($this->getElements() as $databaseUnscopedTableRead) {
            $storedUnscopedTables[$databaseUnscopedTableRead->tableName] = [
                'read' => $databaseUnscopedTableRead->readIdCount,
                'allocated' => $databaseUnscopedTableRead->allocatedIdCount,
            ];
        }
        return $storedUnscopedTables;
    }
}
