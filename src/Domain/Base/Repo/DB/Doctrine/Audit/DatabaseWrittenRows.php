<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The rows of one INSERT or REPLACE, in the order of its `VALUES` list.
 *
 * @property DatabaseWrittenRow[] $elements
 * @method DatabaseWrittenRow first()
 * @method DatabaseWrittenRow[] getElements()
 */
class DatabaseWrittenRows extends ObjectSet
{
    /**
     * The stored document of the rows: one column → value map per row, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $storedRows = [];
        foreach ($this->getElements() as $databaseWrittenRow) {
            $storedRows[] = $databaseWrittenRow->toArray();
        }
        return $storedRows;
    }

    /**
     * Every row normalised as the run declares it.
     *
     * @param DatabaseWriteStatementNormalization $databaseWriteStatementNormalization
     * @param string|null $table
     * @return void
     */
    public function applyNormalization(DatabaseWriteStatementNormalization $databaseWriteStatementNormalization, ?string $table): void
    {
        foreach ($this->getElements() as $databaseWrittenRow) {
            $databaseWrittenRow->applyNormalization($databaseWriteStatementNormalization, $table);
        }
    }
}
