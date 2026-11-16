<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Base\Messages\ProcessCLIMessage;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * The worker path of a recorded run: while the queue is the active capture, every message the run dispatches is KEPT
 * (never sent to a broker) and then run in THIS process by {@see runPendingMessages()}: first in, first out, each as a
 * RECEIVED message through the bus — the application's middleware (the fresh worker state of every consumed job
 * included) and the handler exactly as a worker runs it — and a message dispatched while one runs joins the end of the
 * queue. So a run whose work continues in workers is recorded and replayed in one process, in a fixed order.
 *
 * The object that owns the recorded run and its switches implements {@see RecordedRunMessageQueueHostInterface} and
 * is set on {@see $host}: the queue tells it the phase of the run of each message, asks it to restore its state after
 * each message, and — because the fresh worker state of each job clears the process switches the host set — asks it
 * to set them again at the end of the reset ({@see reinstallHooksOfRunningQueueAfterWorkerStateReset()}).
 */
class RecordedRunMessageQueue extends MessageDispatchCapture
{
    /** More messages than this in one run is a loop, not a run. */
    public const int MAX_MESSAGES_PER_RUN = 2000;

    /** What running a message did, per dispatch position. */
    public const string OUTCOME_HANDLED = 'handled';
    public const string OUTCOME_NOT_RUN = 'not run: no AppMessage handler';

    /** The outcome of a message the queue has not run (yet). */
    public const string OUTCOME_PENDING = 'pending';

    /** The queue whose messages run in this process right now; null in every normal run. */
    public static ?RecordedRunMessageQueue $runningQueue = null;

    /** The object that owns the recorded run and its switches; null: the queue tells nobody. */
    public ?RecordedRunMessageQueueHostInterface $host = null;

    /**
     * The phase of the run at this moment (set by the host, e.g. one per input it drives): a captured message carries
     * it, and while the message runs it is the phase again — what a message does belongs to the phase that dispatched it.
     */
    public ?int $currentPhaseOrdinal = null;

    /** @var array<int, string> Per dispatch position: what running the message did */
    public array $outcomesByPosition = [];

    /** How many messages this queue has run. */
    public int $runMessageCount = 0;

    /** @var PendingMessageEnvelopes The captured messages not run yet, in dispatch order */
    protected PendingMessageEnvelopes $pendingMessageEnvelopes;

    public function __construct()
    {
        parent::__construct();
        $this->pendingMessageEnvelopes = new PendingMessageEnvelopes();
    }

    /**
     * @param Envelope $envelope
     * @return void
     */
    public function captureEnvelope(Envelope $envelope): void
    {
        parent::captureEnvelope($envelope);
        $pendingMessageEnvelope = PendingMessageEnvelope::atPosition($this->capturedDispatches->count() - 1, $envelope, $this->currentPhaseOrdinal);
        $this->pendingMessageEnvelopes->add($pendingMessageEnvelope);
    }

    /**
     * @return bool Whether captured messages wait to be run
     */
    public function hasPendingMessages(): bool
    {
        return $this->pendingMessageEnvelopes->count() > 0;
    }

    /**
     * Runs every pending message, first in first out, until none is left (messages dispatched meanwhile included),
     * with this queue capturing. A failing message is recorded with its error and the next one runs — a worker's retry
     * is not simulated. How one message runs is {@see runQueuedEnvelope()}. Afterwards — also on a throw — the running
     * queue and the active capture of before are put back first, then the phase of before (which the host is told).
     *
     * @return int How many messages ran in this call
     * @throws RuntimeException When the run exceeds {@see MAX_MESSAGES_PER_RUN}
     */
    public function runPendingMessages(): int
    {
        $queueRunningBefore = self::$runningQueue;
        $captureActiveBefore = MessageDispatchCapture::$activeCapture;
        $phaseOrdinalBefore = $this->currentPhaseOrdinal;
        self::$runningQueue = $this;
        MessageDispatchCapture::$activeCapture = $this;
        $messagesRun = 0;
        try {
            while (($pendingMessageEnvelope = $this->pendingMessageEnvelopes->takeOldest()) !== null) {
                if ($this->runPendingMessage($pendingMessageEnvelope)) {
                    $messagesRun++;
                }
            }
        } finally {
            self::$runningQueue = $queueRunningBefore;
            MessageDispatchCapture::$activeCapture = $captureActiveBefore;
            $this->switchPhaseOrdinal($phaseOrdinalBefore);
        }
        return $messagesRun;
    }

    /**
     * Runs one pending message in the phase that dispatched it and records what running it did.
     *
     * @param PendingMessageEnvelope $pendingMessageEnvelope
     * @return bool Whether the message ran (false: it has no AppMessage handler)
     * @throws RuntimeException When the run exceeds {@see MAX_MESSAGES_PER_RUN}
     */
    protected function runPendingMessage(PendingMessageEnvelope $pendingMessageEnvelope): bool
    {
        $this->switchPhaseOrdinal($pendingMessageEnvelope->phaseOrdinal);
        if (++$this->runMessageCount > self::MAX_MESSAGES_PER_RUN) {
            throw new RuntimeException('recorded run: more than ' . self::MAX_MESSAGES_PER_RUN . ' dispatched messages — a dispatch loop');
        }
        if (!static::hasAppMessageHandler($pendingMessageEnvelope->envelope)) {
            $this->outcomesByPosition[$pendingMessageEnvelope->position] = self::OUTCOME_NOT_RUN;
            return false;
        }
        try {
            $this->runQueuedEnvelope($pendingMessageEnvelope->envelope);
            $this->outcomesByPosition[$pendingMessageEnvelope->position] = self::OUTCOME_HANDLED;
        } catch (Throwable $throwable) {
            $this->outcomesByPosition[$pendingMessageEnvelope->position] = 'failed: ' . $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 300);
        } finally {
            // the job's reset may have replaced the capture; the queue keeps capturing between its messages
            MessageDispatchCapture::$activeCapture = $this;
            $this->host?->restoreStateAfterQueuedMessage();
        }
        return true;
    }

    /**
     * How one queued message runs: as a received message through the bus ({@see runEnvelopeAsReceivedMessage()}). The
     * method a subclass overrides to run it another way.
     *
     * @param Envelope $envelope
     * @return void
     */
    protected function runQueuedEnvelope(Envelope $envelope): void
    {
        static::runEnvelopeAsReceivedMessage($envelope);
    }

    /**
     * Sets the phase of the run at this moment and tells the host ({@see RecordedRunMessageQueueHostInterface::applyPhaseOrdinal()}).
     *
     * @param int|null $phaseOrdinal
     * @return void
     */
    public function switchPhaseOrdinal(?int $phaseOrdinal): void
    {
        $this->currentPhaseOrdinal = $phaseOrdinal;
        $this->host?->applyPhaseOrdinal($phaseOrdinal);
    }

    /**
     * The dispatched messages with what running each did ({@see OUTCOME_PENDING} for one not run yet), in dispatch
     * order.
     *
     * @return CapturedMessageDispatches
     */
    public function buildDispatchedMessagesWithOutcomes(): CapturedMessageDispatches
    {
        $dispatchedMessagesWithOutcomes = new CapturedMessageDispatches();
        foreach ($this->capturedDispatches->getElements() as $position => $capturedMessageDispatch) {
            $dispatchedMessageWithOutcome = CapturedMessageDispatch::forMessageClass(
                $capturedMessageDispatch->messageClass,
                $capturedMessageDispatch->body,
                $this->outcomesByPosition[$position] ?? self::OUTCOME_PENDING
            );
            $dispatchedMessagesWithOutcomes->add($dispatchedMessageWithOutcome);
        }
        return $dispatchedMessagesWithOutcomes;
    }

    /**
     * One message as a worker consumes it: through the default bus with a {@see ReceivedStamp} of the handler's own
     * transport — the same path as `app:process-cli-message`.
     *
     * @param Envelope $envelope
     * @return void
     */
    public static function runEnvelopeAsReceivedMessage(Envelope $envelope): void
    {
        $message = $envelope->getMessage();
        if (!$message instanceof AppMessage || !static::hasAppMessageHandler($envelope)) {
            return;
        }
        /** @var MessageBusInterface $messageBus */
        $messageBus = DDDService::instance()->getService('messenger.default_bus');
        $messageBus->dispatch(new Envelope($message, [new ReceivedStamp(ProcessCLIMessage::receivedFromTransportNameFor($message::$messageHandler))]));
    }

    /**
     * Whether the envelope carries an AppMessage that names its handler — the only kind this queue can run.
     *
     * @param Envelope $envelope
     * @return bool
     */
    public static function hasAppMessageHandler(Envelope $envelope): bool
    {
        $message = $envelope->getMessage();
        return $message instanceof AppMessage && isset($message::$messageHandler) && $message::$messageHandler !== '';
    }

    /**
     * Called last by the worker-state reset of a consumed job: while a queue runs its messages, the reset keeps the
     * queue capturing and lets the host set its switches again. No-op in every normal run.
     *
     * @return void
     */
    public static function reinstallHooksOfRunningQueueAfterWorkerStateReset(): void
    {
        $runningQueue = self::$runningQueue;
        if ($runningQueue === null) {
            return;
        }
        MessageDispatchCapture::$activeCapture = $runningQueue;
        $runningQueue->host?->reinstallSwitchesAfterWorkerStateReset();
    }

    /**
     * The worker-state reset of the dispatch capture, for an application's
     * {@see \DDD\Symfony\Messenger\WorkerStateResetterInterface}: a capture belongs to one recorded run in a console process,
     * never to a worker job, so it is cleared — and, while a queue runs its messages in this process, the queue is made
     * the active capture again and its host sets its switches again. Call it as the LAST step of the application's
     * reset, after every other process static was put back: the host's switches must survive the rest of the reset.
     *
     * @return void
     */
    public static function resetDispatchCaptureForNewJob(): void
    {
        MessageDispatchCapture::$activeCapture = null;
        static::reinstallHooksOfRunningQueueAfterWorkerStateReset();
    }
}
