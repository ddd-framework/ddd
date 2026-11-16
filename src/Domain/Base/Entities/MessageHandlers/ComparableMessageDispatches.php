<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The dispatched messages of one capture as two runs compare them, in dispatch order.
 *
 * @property ComparableMessageDispatch[] $elements
 * @method ComparableMessageDispatch first()
 * @method ComparableMessageDispatch[] getElements()
 */
class ComparableMessageDispatches extends ObjectSet
{
    /**
     * The stored document of the dispatches: one `{messageClass, body}` per message, in dispatch order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $storedDispatches = [];
        foreach ($this->getElements() as $comparableMessageDispatch) {
            $storedDispatches[] = $comparableMessageDispatch->toArray();
        }
        return $storedDispatches;
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
