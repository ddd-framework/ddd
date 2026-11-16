<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures;

use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueueHostInterface;

/** A host that records what the queue told it. */
class RecordingRecordedRunMessageQueueHost implements RecordedRunMessageQueueHostInterface
{
    public int $switchesReinstalledCount = 0;

    /** @var array<int, int|null> The phases told, in order */
    public array $appliedPhaseOrdinals = [];

    public int $stateRestoredCount = 0;

    public function reinstallSwitchesAfterWorkerStateReset(): void
    {
        $this->switchesReinstalledCount++;
    }

    public function applyPhaseOrdinal(?int $phaseOrdinal): void
    {
        $this->appliedPhaseOrdinals[] = $phaseOrdinal;
    }

    public function restoreStateAfterQueuedMessage(): void
    {
        $this->stateRestoredCount++;
    }
}
