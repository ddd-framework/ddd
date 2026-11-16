<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures;

use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueue;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;

/**
 * A queue that runs a message by recording it instead of dispatching it through a bus: what ran in which order and
 * phase, a child message dispatched while one runs, a failing message, and a worker-state reset inside a job.
 */
class ProbeRecordedRunMessageQueue extends RecordedRunMessageQueue
{
    /** @var int[] The item ids in the order they ran */
    public array $ranItemIds = [];

    /** @var array<int, int|null> Item id → the phase while it ran */
    public array $phaseOrdinalsWhileRunning = [];

    /** @var array<int, int> Item id → the item id of the child it dispatches while it runs */
    public array $childItemIdsByItemId = [];

    /** @var int[] The item ids whose run throws */
    public array $failingItemIds = [];

    /** Whether each job runs the worker-state reset of the capture, as a consumed job does */
    public bool $resetsWorkerStateDuringJob = false;

    /** The active capture right after the reset inside the last job */
    public ?MessageDispatchCapture $captureAfterResetDuringJob = null;

    protected function runQueuedEnvelope(Envelope $envelope): void
    {
        /** @var RecordedRunProbeMessage $recordedRunProbeMessage */
        $recordedRunProbeMessage = $envelope->getMessage();
        $this->ranItemIds[] = $recordedRunProbeMessage->itemId;
        $this->phaseOrdinalsWhileRunning[$recordedRunProbeMessage->itemId] = $this->currentPhaseOrdinal;
        if ($this->resetsWorkerStateDuringJob) {
            RecordedRunMessageQueue::resetDispatchCaptureForNewJob();
            $this->captureAfterResetDuringJob = MessageDispatchCapture::$activeCapture;
        }
        if (isset($this->childItemIdsByItemId[$recordedRunProbeMessage->itemId])) {
            CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage($this->childItemIdsByItemId[$recordedRunProbeMessage->itemId])));
        }
        if (in_array($recordedRunProbeMessage->itemId, $this->failingItemIds, true)) {
            throw new RuntimeException('child failed');
        }
    }
}
