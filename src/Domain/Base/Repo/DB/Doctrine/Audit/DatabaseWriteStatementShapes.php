<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The statements of one audited call as two runs compare them, in execution order.
 *
 * @property DatabaseWriteStatementShape[] $elements
 * @method DatabaseWriteStatementShape first()
 * @method DatabaseWriteStatementShape[] getElements()
 */
class DatabaseWriteStatementShapes extends ObjectSet
{
    /**
     * The stored document of the statements: one {@see DatabaseWriteStatementShape::toArray()} per statement, in
     * execution order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $storedStatements = [];
        foreach ($this->getElements() as $databaseWriteStatementShape) {
            $storedStatements[] = $databaseWriteStatementShape->toArray();
        }
        return $storedStatements;
    }

    /**
     * The set as the sequence two runs are compared by ({@see \DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison}).
     *
     * @return RecordedSequence
     */
    public function toRecordedSequence(): RecordedSequence
    {
        return RecordedSequence::fromStoredDocuments($this->toArray());
    }
}
