<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueue;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\CaptureMiddlewareDispatcher;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\ProbeRecordedRunMessageQueue;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\RecordedRunProbeMessage;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\RecordingRecordedRunMessageQueueHost;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;

/**
 * The worker path of a recorded run: the dispatched messages are kept, never sent, and run in this process first in
 * first out — a message dispatched while another runs joins the end — with what each run did recorded; the
 * worker-state reset of a job keeps the running queue capturing and asks the host to set its switches again.
 */
class RecordedRunMessageQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        MessageDispatchCapture::$activeCapture = null;
        RecordedRunMessageQueue::$runningQueue = null;
        parent::tearDown();
    }

    public function testTheQueueRunsItsMessagesInDispatchOrderIncludingTheOnesDispatchedMeanwhile(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        $recordingHost = new RecordingRecordedRunMessageQueueHost();
        $probeRecordedRunMessageQueue->host = $recordingHost;
        // the first message starts a child: it joins the END of the queue, after the message dispatched before it
        $probeRecordedRunMessageQueue->childItemIdsByItemId = [1 => 11];
        $probeRecordedRunMessageQueue->failingItemIds = [11];
        MessageDispatchCapture::$activeCapture = $probeRecordedRunMessageQueue;
        self::assertFalse(CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage(1))), 'a captured message is never sent');
        self::assertFalse(CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage(2))));
        self::assertTrue($probeRecordedRunMessageQueue->hasPendingMessages());

        $messagesRun = $probeRecordedRunMessageQueue->runPendingMessages();

        self::assertSame(3, $messagesRun);
        self::assertSame([1, 2, 11], $probeRecordedRunMessageQueue->ranItemIds);
        self::assertSame(3, $recordingHost->stateRestoredCount, 'the host restores its state after each message, a failed one included');
        self::assertFalse($probeRecordedRunMessageQueue->hasPendingMessages());
        $dispatchedMessages = $probeRecordedRunMessageQueue->buildDispatchedMessagesWithOutcomes()->getElements();
        self::assertSame(['handled', 'handled'], [$dispatchedMessages[0]->outcome, $dispatchedMessages[1]->outcome]);
        self::assertStringStartsWith('failed: RuntimeException: child failed', $dispatchedMessages[2]->outcome);
        self::assertSame($probeRecordedRunMessageQueue, MessageDispatchCapture::$activeCapture, 'the capture that was active before the run is active again');
        self::assertNull(RecordedRunMessageQueue::$runningQueue);
    }

    public function testTheWorkerStateResetKeepsTheRunningQueueCapturingAndAsksTheHostForItsSwitches(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        $recordingHost = new RecordingRecordedRunMessageQueueHost();
        $probeRecordedRunMessageQueue->host = $recordingHost;
        $probeRecordedRunMessageQueue->resetsWorkerStateDuringJob = true;
        MessageDispatchCapture::$activeCapture = $probeRecordedRunMessageQueue;
        CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage(1)));

        RecordedRunMessageQueue::resetDispatchCaptureForNewJob();
        self::assertNull(MessageDispatchCapture::$activeCapture, 'outside a run of the queue the reset clears the capture');
        self::assertSame(0, $recordingHost->switchesReinstalledCount, 'outside a run of the queue the reset asks for nothing');

        $probeRecordedRunMessageQueue->runPendingMessages();

        self::assertSame($probeRecordedRunMessageQueue, $probeRecordedRunMessageQueue->captureAfterResetDuringJob);
        self::assertSame(1, $recordingHost->switchesReinstalledCount);
    }

    public function testAMessageWithoutAnAppMessageHandlerIsRecordedAsNotRun(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        MessageDispatchCapture::$activeCapture = $probeRecordedRunMessageQueue;
        CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new stdClass()));

        self::assertSame(0, $probeRecordedRunMessageQueue->runPendingMessages());
        self::assertSame([], $probeRecordedRunMessageQueue->ranItemIds);
        self::assertSame(RecordedRunMessageQueue::OUTCOME_NOT_RUN, $probeRecordedRunMessageQueue->buildDispatchedMessagesWithOutcomes()->first()->outcome);
    }

    public function testAMessageRunsInThePhaseThatDispatchedIt(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        $recordingHost = new RecordingRecordedRunMessageQueueHost();
        $probeRecordedRunMessageQueue->host = $recordingHost;
        MessageDispatchCapture::$activeCapture = $probeRecordedRunMessageQueue;
        $probeRecordedRunMessageQueue->switchPhaseOrdinal(3);
        CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage(1)));
        $probeRecordedRunMessageQueue->switchPhaseOrdinal(4);

        $probeRecordedRunMessageQueue->runPendingMessages();

        self::assertSame(3, $probeRecordedRunMessageQueue->phaseOrdinalsWhileRunning[1], 'the message dispatched in phase 3 runs as phase 3');
        self::assertSame(4, $probeRecordedRunMessageQueue->currentPhaseOrdinal, 'the host phase is back after the run');
        self::assertSame([3, 4, 3, 4], $recordingHost->appliedPhaseOrdinals);
    }

    public function testTheStaticsAndThePhaseAreBackAfterAQueuedMessageThrows(): void
    {
        $outerCapture = new MessageDispatchCapture();
        $outerQueue = new ProbeRecordedRunMessageQueue();
        RecordedRunMessageQueue::$runningQueue = $outerQueue;
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        $recordingHost = new RecordingRecordedRunMessageQueueHost();
        $probeRecordedRunMessageQueue->host = $recordingHost;
        $probeRecordedRunMessageQueue->failingItemIds = [1];
        $probeRecordedRunMessageQueue->switchPhaseOrdinal(2);
        $probeRecordedRunMessageQueue->captureEnvelope(new Envelope(new RecordedRunProbeMessage(1)));
        $probeRecordedRunMessageQueue->switchPhaseOrdinal(5);
        MessageDispatchCapture::$activeCapture = $outerCapture;

        self::assertSame(1, $probeRecordedRunMessageQueue->runPendingMessages());

        self::assertSame($outerCapture, MessageDispatchCapture::$activeCapture);
        self::assertSame($outerQueue, RecordedRunMessageQueue::$runningQueue);
        self::assertSame(5, $probeRecordedRunMessageQueue->currentPhaseOrdinal);
        self::assertSame([2, 5, 2, 5], $recordingHost->appliedPhaseOrdinals, 'the host hears the phase of before last');
    }

    public function testARunOfMoreThanTheMaximumOfMessagesIsStoppedAndTheStaticsAreBack(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        // every message dispatches the next one: a dispatch loop
        $probeRecordedRunMessageQueue->childItemIdsByItemId = [1 => 1];
        MessageDispatchCapture::$activeCapture = $probeRecordedRunMessageQueue;
        CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(new RecordedRunProbeMessage(1)));
        $outerCapture = new MessageDispatchCapture();
        MessageDispatchCapture::$activeCapture = $outerCapture;

        try {
            $probeRecordedRunMessageQueue->runPendingMessages();
            self::fail('a run beyond the maximum is stopped');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('more than ' . RecordedRunMessageQueue::MAX_MESSAGES_PER_RUN . ' dispatched messages', $runtimeException->getMessage());
        }
        self::assertCount(RecordedRunMessageQueue::MAX_MESSAGES_PER_RUN, $probeRecordedRunMessageQueue->ranItemIds);
        self::assertSame($outerCapture, MessageDispatchCapture::$activeCapture);
        self::assertNull(RecordedRunMessageQueue::$runningQueue);
    }

    public function testAMessageNotRunYetReadsPending(): void
    {
        $probeRecordedRunMessageQueue = new ProbeRecordedRunMessageQueue();
        $probeRecordedRunMessageQueue->captureEnvelope(new Envelope(new RecordedRunProbeMessage(7)));
        $dispatchedMessage = $probeRecordedRunMessageQueue->buildDispatchedMessagesWithOutcomes()->first();
        self::assertSame(RecordedRunProbeMessage::class, $dispatchedMessage->messageClass);
        self::assertSame(RecordedRunMessageQueue::OUTCOME_PENDING, $dispatchedMessage->outcome);
    }
}
