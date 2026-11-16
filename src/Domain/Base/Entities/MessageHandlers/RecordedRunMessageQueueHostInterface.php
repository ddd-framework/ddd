<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

/**
 * The object that owns a recorded run and its process switches (e.g. a console command that records or replays a run)
 * and drives its {@see RecordedRunMessageQueue}: what the queue tells it while the captured messages run in the same
 * process. Set on {@see RecordedRunMessageQueue::$host}; without one the queue tells nobody.
 */
interface RecordedRunMessageQueueHostInterface
{
    /**
     * Called after the worker-state reset of a consumed message while the queue runs its messages (the reset cleared
     * the process switches the host had set: caches off, recording hooks …): the host sets them again.
     *
     * @return void
     */
    public function reinstallSwitchesAfterWorkerStateReset(): void;

    /**
     * Called whenever the queue changes the phase of the run (before and after each message it runs): the host
     * passes it on to whatever records per phase.
     *
     * @param int|null $phaseOrdinal
     * @return void
     */
    public function applyPhaseOrdinal(?int $phaseOrdinal): void;

    /**
     * Called after each message the queue ran, also after a failed one: the host restores what the message's fresh
     * worker state replaced (its authenticated account, a process grant …).
     *
     * @return void
     */
    public function restoreStateAfterQueuedMessage(): void;
}
