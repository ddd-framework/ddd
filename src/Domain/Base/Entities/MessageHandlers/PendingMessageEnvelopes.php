<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The messages a {@see RecordedRunMessageQueue} has not run yet, first in first out.
 *
 * @property PendingMessageEnvelope[] $elements
 * @method PendingMessageEnvelope first()
 * @method PendingMessageEnvelope[] getElements()
 */
class PendingMessageEnvelopes extends ObjectSet
{
    /**
     * Removes the oldest pending message from the set and returns it.
     *
     * @return PendingMessageEnvelope|null Null when none is pending
     */
    public function takeOldest(): ?PendingMessageEnvelope
    {
        $oldestPendingMessageEnvelope = $this->first();
        if ($oldestPendingMessageEnvelope === null) {
            return null;
        }
        $this->remove($oldestPendingMessageEnvelope);
        return $oldestPendingMessageEnvelope;
    }
}
