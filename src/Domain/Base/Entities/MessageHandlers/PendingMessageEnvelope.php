<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\Messenger\Envelope;

/**
 * A message a {@see RecordedRunMessageQueue} captured and has not run yet: its dispatch position, its envelope and the
 * phase of the run it was dispatched in.
 */
class PendingMessageEnvelope extends ValueObject
{
    /** @var int The message's position in the queue's captured dispatches */
    public int $position = 0;

    /** @var Envelope The captured envelope */
    public Envelope $envelope;

    /** @var int|null The phase ordinal of the run when the message was dispatched */
    public ?int $phaseOrdinal = null;

    /**
     * @param int $position
     * @param Envelope $envelope
     * @param int|null $phaseOrdinal
     * @return static
     */
    public static function atPosition(int $position, Envelope $envelope, ?int $phaseOrdinal): static
    {
        $pendingMessageEnvelope = new static();
        $pendingMessageEnvelope->position = $position;
        $pendingMessageEnvelope->envelope = $envelope;
        $pendingMessageEnvelope->phaseOrdinal = $phaseOrdinal;
        return $pendingMessageEnvelope;
    }
}
