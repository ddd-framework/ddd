<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\CaptureMiddlewareDispatcher;
use DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures\RecordedRunProbeMessage;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * The messages a recorded or replayed call dispatches are kept, in order and multiplicity, and never sent; outside a
 * capture the bus is untouched.
 */
class MessageDispatchCaptureTest extends TestCase
{
    protected function tearDown(): void
    {
        MessageDispatchCapture::$activeCapture = null;
        parent::tearDown();
    }

    protected static function buildMessage(int $itemId, string $text): stdClass
    {
        $message = new stdClass();
        $message->orderId = $itemId;
        $message->text = $text;
        $message->dispatchedFromWorkspaceDir = '/srv/workspaces/one/app';
        return $message;
    }

    public function testADispatchDuringACaptureIsKeptAndNeverSent(): void
    {
        $messageDispatchCapture = new MessageDispatchCapture();
        $forwardedDispatches = [];
        $messageDispatchCapture->startCapturing();
        try {
            $forwardedDispatches[] = CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(self::buildMessage(41, 'first')));
            $forwardedDispatches[] = CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(self::buildMessage(41, 'first')));
            // a consumed message is a worker's business, never a dispatch of the call
            $forwardedDispatches[] = CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(self::buildMessage(9, 'received'), [new ReceivedStamp('async')]));
        } finally {
            $messageDispatchCapture->stopCapturing();
        }
        $forwardedDispatches[] = CaptureMiddlewareDispatcher::dispatchThroughCaptureMiddleware(new Envelope(self::buildMessage(41, 'after the capture')));

        self::assertSame([false, false, true, true], $forwardedDispatches, 'the consumed message and the dispatch after the capture pass on');
        self::assertCount(2, $messageDispatchCapture->capturedDispatches, 'the multiplicity is kept');
        self::assertSame(stdClass::class, $messageDispatchCapture->capturedDispatches->first()->messageClass);
        self::assertNull(MessageDispatchCapture::$activeCapture);
    }

    public function testTheCaptureActiveBeforeTheStartIsBackAfterAThrow(): void
    {
        $outerCapture = new MessageDispatchCapture();
        MessageDispatchCapture::$activeCapture = $outerCapture;
        $messageDispatchCapture = new MessageDispatchCapture();
        try {
            $messageDispatchCapture->startCapturing();
            try {
                self::assertSame($messageDispatchCapture, MessageDispatchCapture::$activeCapture);
                throw new RuntimeException('the call failed');
            } finally {
                $messageDispatchCapture->stopCapturing();
            }
        } catch (RuntimeException) {
        }
        self::assertSame($outerCapture, MessageDispatchCapture::$activeCapture);
    }

    public function testASecondStartIsRefused(): void
    {
        $messageDispatchCapture = new MessageDispatchCapture();
        $messageDispatchCapture->startCapturing();
        try {
            $this->expectException(LogicException::class);
            $messageDispatchCapture->startCapturing();
        } finally {
            $messageDispatchCapture->stopCapturing();
        }
    }

    public function testAStopWithoutAStartLeavesAForeignActiveCaptureAlone(): void
    {
        $foreignCapture = new MessageDispatchCapture();
        MessageDispatchCapture::$activeCapture = $foreignCapture;

        (new MessageDispatchCapture())->stopCapturing();

        self::assertSame($foreignCapture, MessageDispatchCapture::$activeCapture);
    }

    public function testTheComparableDispatchesNormalizeMintedIdsAndShowAChangedBody(): void
    {
        // ids above the high-water mark (40 / 70) were minted during the call
        $recordedCapture = new MessageDispatchCapture();
        $recordedCapture->captureEnvelope(new Envelope(self::buildMessage(41, 'hello')));
        $recordedCapture->captureEnvelope(new Envelope(self::buildMessage(42, 'again')));
        $replayedCapture = new MessageDispatchCapture();
        $replayedCapture->captureEnvelope(new Envelope(self::buildMessage(71, 'hello')));
        $replayedCapture->captureEnvelope(new Envelope(self::buildMessage(72, 'again')));

        $idKeyFamilies = ['orderId' => 'order'];
        $recordedDispatches = $recordedCapture->buildComparableDispatches($idKeyFamilies, ['order' => 40]);
        self::assertSame(['messageClass' => stdClass::class, 'body' => ['orderId' => '<order#1>', 'text' => 'hello']], $recordedDispatches->toArray()[0]);
        self::assertSame([], RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), $replayedCapture->buildComparableDispatches($idKeyFamilies, ['order' => 70])->toRecordedSequence()));
        self::assertSame(
            [],
            RecordedSequenceComparison::buildDifferences('dispatch', RecordedSequence::fromStoredDocuments(json_decode((string)json_encode($recordedDispatches->toArray()), true)), $recordedDispatches->toRecordedSequence()),
            'a stored recording, decoded again, compares equal'
        );

        $changedCapture = new MessageDispatchCapture();
        $changedCapture->captureEnvelope(new Envelope(self::buildMessage(71, 'hello')));
        $differences = RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), $changedCapture->buildComparableDispatches($idKeyFamilies, ['order' => 70])->toRecordedSequence());
        self::assertSame('dispatch count: 2 recorded, 1 replayed', $differences[0]);
        self::assertStringStartsWith('dispatch #1: only recorded', $differences[1]);
    }

    public function testAnAppMessageBodyDoesNotDependOnAProcessWideObjectTypeHide(): void
    {
        $bodyBeforeAnyHide = MessageDispatchCapture::serializeMessage(new RecordedRunProbeMessage(41));
        // what code that ran earlier in the process may do, for the rest of the process
        Entity::addStaticPropertiesToHide(false, 'objectType');
        try {
            $bodyAfterTheHide = MessageDispatchCapture::serializeMessage(new RecordedRunProbeMessage(41));
        } finally {
            Entity::removeStaticPropertiesToHide(false, 'objectType');
        }
        self::assertSame($bodyBeforeAnyHide, $bodyAfterTheHide);
        self::assertSame(RecordedRunProbeMessage::class, json_decode($bodyAfterTheHide, true)['objectType'] ?? null);
    }

    public function testAPreExistingIdInADispatchIsComparedRaw(): void
    {
        $recordedCapture = new MessageDispatchCapture();
        $recordedCapture->captureEnvelope(new Envelope(self::buildMessage(42, 'resume')));
        $replayedCapture = new MessageDispatchCapture();
        $replayedCapture->captureEnvelope(new Envelope(self::buildMessage(43, 'resume')));
        $idKeyFamilies = ['orderId' => 'order'];
        $recordedDispatches = $recordedCapture->buildComparableDispatches($idKeyFamilies, ['order' => 100]);
        self::assertSame(42, $recordedDispatches->toArray()[0]['body']['orderId']);
        self::assertCount(1, RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), $replayedCapture->buildComparableDispatches($idKeyFamilies, ['order' => 100])->toRecordedSequence()));
    }

    public function testTheWorkspaceKeysAreDropped(): void
    {
        $messageDispatchCapture = new MessageDispatchCapture();
        $messageDispatchCapture->captureEnvelope(new Envelope(self::buildMessage(5, 'x')));
        $comparableDispatch = $messageDispatchCapture->buildComparableDispatches()->first();
        self::assertArrayNotHasKey('dispatchedFromWorkspaceDir', $comparableDispatch->getComparableBody());
    }
}
