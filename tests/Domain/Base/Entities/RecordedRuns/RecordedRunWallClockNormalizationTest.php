<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\RecordedRuns;

use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison;
use DDD\Domain\Base\Entities\MessageHandlers\ComparableMessageDispatches;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatement;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementNormalization;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

/**
 * The ONE wall-clock rule of a recorded-run comparison, applied alike to the statements and the dispatched messages:
 * a moment the run's wall clock wrote under a time-named key reads `<clock>` (a row's `changeHistory.createdTime`
 * written when the run created it, a stamp an earlier call wrote), the pinned moment stays raw, the declared derived
 * moments and durations read placeholders — and a real change of a value, a write or a dispatch is still a difference.
 */
class RecordedRunWallClockNormalizationTest extends TestCase
{
    /** The record run: started at 07:48:56, the pinned moment two seconds before. */
    protected const string RECORD_RUN_START = '2026-10-22 07:48:56';

    protected const string REPLAY_RUN_START = '2026-10-22 07:50:27';

    protected const string PINNED_MOMENT = '2026-10-22 07:48:54';

    protected static function buildClockWindow(string $runStart, int $runSeconds): RecordedRunClockWindow
    {
        return RecordedRunClockWindow::between((int)strtotime($runStart) - 1, (int)strtotime($runStart) + $runSeconds);
    }

    protected static function buildRunWallClockRule(string $runStart, int $runSeconds = 120): RecordedRunWallClockNormalization
    {
        return RecordedRunWallClockNormalization::declare(
            self::buildClockWindow($runStart, $runSeconds),
            [self::PINNED_MOMENT, (string)strtotime(self::PINNED_MOMENT)],
            ['scheduledAt'],
            ['elapsedMs']
        );
    }

    /** A notification message: the store's stamps (creation + an earlier call), a derived schedule. */
    protected static function buildNotificationMessage(string $runStart, string $title): stdClass
    {
        $runStartTimestamp = (int)strtotime($runStart);
        $message = new stdClass();
        $message->notification = [
            'title' => $title,
            'scheduledAt' => date('Y-m-d H:i:s', $runStartTimestamp + 4 * 3600 + 17),
            'store' => [
                'changeHistory' => ['createdTime' => $runStart, 'modifiedTime' => self::PINNED_MOMENT],
                'city' => ['changeHistory' => ['createdTime' => '2020-05-29 16:11:28']],
            ],
            'storeSubscriptions' => [
                ['changeHistory' => ['createdTime' => $runStart, 'modifiedTime' => date('Y-m-d H:i:s', $runStartTimestamp + 17)]],
            ],
        ];
        return $message;
    }

    protected static function captureAndCompare(stdClass $message, RecordedRunWallClockNormalization $wallClockNormalization): ComparableMessageDispatches
    {
        $messageDispatchCapture = new MessageDispatchCapture();
        $messageDispatchCapture->captureEnvelope(new Envelope($message));
        return $messageDispatchCapture->buildComparableDispatches(wallClockNormalization: $wallClockNormalization);
    }

    public function testADispatchBodyComparesEqualAcrossTwoRunsAndAChangedValueIsReported(): void
    {
        $recordedDispatches = self::captureAndCompare(self::buildNotificationMessage(self::RECORD_RUN_START, 'Publish'), self::buildRunWallClockRule(self::RECORD_RUN_START));
        $replayedDispatches = self::captureAndCompare(self::buildNotificationMessage(self::REPLAY_RUN_START, 'Publish'), self::buildRunWallClockRule(self::REPLAY_RUN_START));

        $recordedBody = $recordedDispatches->toArray()[0]['body']['notification'];
        self::assertSame('<clock>', $recordedBody['store']['changeHistory']['createdTime'], 'a stamp the run wrote when it created the row');
        self::assertSame(self::PINNED_MOMENT, $recordedBody['store']['changeHistory']['modifiedTime'], 'the pinned moment stays raw');
        self::assertSame('2020-05-29 16:11:28', $recordedBody['store']['city']['changeHistory']['createdTime'], 'a moment before the run stays raw');
        self::assertSame('<clock>', $recordedBody['storeSubscriptions'][0]['changeHistory']['modifiedTime'], 'a stamp an earlier call of the run wrote');
        self::assertSame('<derived-clock>', $recordedBody['scheduledAt']);
        self::assertSame([], RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), $replayedDispatches->toRecordedSequence()));

        $changedDispatches = self::captureAndCompare(self::buildNotificationMessage(self::REPLAY_RUN_START, 'Publish now'), self::buildRunWallClockRule(self::REPLAY_RUN_START));
        $differences = RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), $changedDispatches->toRecordedSequence());
        self::assertCount(1, $differences, 'a changed body value is still a difference');
        self::assertStringContainsString('Publish now', $differences[0]);

        $noDispatchDifferences = RecordedSequenceComparison::buildDifferences('dispatch', $recordedDispatches->toRecordedSequence(), RecordedSequence::fromStoredDocuments([]));
        self::assertCount(2, $noDispatchDifferences, 'a dropped dispatch is still a difference (count + the missing message)');
    }

    public function testWithoutARuleADispatchBodyIsComparedRaw(): void
    {
        $messageDispatchCapture = new MessageDispatchCapture();
        $messageDispatchCapture->captureEnvelope(new Envelope(self::buildNotificationMessage(self::RECORD_RUN_START, 'Publish')));
        self::assertSame(self::RECORD_RUN_START, $messageDispatchCapture->buildComparableDispatches()->toArray()[0]['body']['notification']['store']['changeHistory']['createdTime']);
    }

    /** A check record a site merges into a row's JSON state. */
    protected static function buildCheckRecordWrite(string $checkedAt, int $elapsedMs, float $score): DatabaseWriteStatement
    {
        $payload = json_encode(['checks' => [['name' => 'reach', 'checkedAt' => $checkedAt, 'elapsedMs' => $elapsedMs, 'scores' => ['first' => $score]]]]);
        return DatabaseWriteStatement::fromIssuedStatement(
            'UPDATE',
            'UPDATE Processes SET state = JSON_MERGE_PATCH(COALESCE(state, \'{}\'), ?) WHERE id = ?',
            [$payload, 77],
            new MariaDbDialect()
        );
    }

    public function testAJsonPayloadInAnSqlExpressionGetsTheRuleAndAChangedAnswerIsReported(): void
    {
        $recordStart = (int)strtotime(self::RECORD_RUN_START);
        $replayStart = (int)strtotime(self::REPLAY_RUN_START);
        $recordedStatement = self::buildCheckRecordWrite(date('Y-m-d H:i:s', $recordStart + 30), 399, 0.99)
            ->buildComparableShape(DatabaseWriteStatementNormalization::declare(wallClockNormalization: self::buildRunWallClockRule(self::RECORD_RUN_START)))->toArray();
        $replayedStatement = self::buildCheckRecordWrite(date('Y-m-d H:i:s', $replayStart + 41), 3, 0.99)
            ->buildComparableShape(DatabaseWriteStatementNormalization::declare(wallClockNormalization: self::buildRunWallClockRule(self::REPLAY_RUN_START)))->toArray();

        self::assertStringContainsString('\\"checkedAt\\":\\"<clock>\\"', $recordedStatement['set']['state']);
        self::assertStringContainsString('\\"elapsedMs\\":\\"<duration>\\"', $recordedStatement['set']['state']);
        self::assertSame([], RecordedSequenceComparison::buildDifferences('statement', RecordedSequence::fromStoredDocuments([$recordedStatement]), RecordedSequence::fromStoredDocuments([$replayedStatement])));

        $changedStatement = self::buildCheckRecordWrite(date('Y-m-d H:i:s', $replayStart + 41), 3, 0.08)
            ->buildComparableShape(DatabaseWriteStatementNormalization::declare(wallClockNormalization: self::buildRunWallClockRule(self::REPLAY_RUN_START)))->toArray();
        self::assertCount(1, RecordedSequenceComparison::buildDifferences('statement', RecordedSequence::fromStoredDocuments([$recordedStatement]), RecordedSequence::fromStoredDocuments([$changedStatement])), 'a changed answer in the payload is still a difference');
    }

    public function testAnUntouchedJsonPayloadKeepsItsBytesAndAJsonColumnObjectGetsTheRule(): void
    {
        $wallClockNormalization = self::buildRunWallClockRule(self::RECORD_RUN_START);
        $untouchedPayload = '{"name":"Café","created":"2020-01-01 00:00:00"}';
        self::assertSame($untouchedPayload, $wallClockNormalization->normalizeJsonText($untouchedPayload), 'nothing changed: the bytes stay');
        self::assertSame('not json {', $wallClockNormalization->normalizeJsonText('not json {'));

        $insertStatement = DatabaseWriteStatement::fromIssuedStatement(
            'INSERT',
            'INSERT INTO Subscriptions (connectionSettings, updated) VALUES (?, ?)',
            [['lastSyncAt' => self::RECORD_RUN_START, 'syncState' => 'NEVER_SYNCED'], self::RECORD_RUN_START],
            new MariaDbDialect()
        );
        $comparableInsert = $insertStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(wallClockNormalization: $wallClockNormalization))->toArray();
        self::assertSame(
            ['connectionSettings' => ['lastSyncAt' => '<clock>', 'syncState' => 'NEVER_SYNCED'], 'updated' => '<clock>'],
            $comparableInsert['rows'][0]
        );
    }

    public function testAnInListOfIdsStaysAColumnValueList(): void
    {
        $deleteStatement = DatabaseWriteStatement::fromIssuedStatement('DELETE', 'DELETE FROM Notes WHERE id IN (?)', [[101, 5]], new MariaDbDialect());
        $comparableDelete = $deleteStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(
            ['Notes.id' => 'note'],
            ['note' => 100],
            wallClockNormalization: self::buildRunWallClockRule(self::RECORD_RUN_START)
        ))->toArray();
        self::assertSame([['column' => 'id', 'value' => ['<note#1>', 5]]], $comparableDelete['whereValues']);
    }

    public function testADeclaredTimeOfDayPhraseInTextComparesEqualAndAnyOtherTimeStaysRaw(): void
    {
        $recordRule = RecordedRunWallClockNormalization::declare(self::buildClockWindow(self::RECORD_RUN_START, 300), [self::PINNED_MOMENT], clockTextPrefixes: ['(started ']);
        $replayRule = RecordedRunWallClockNormalization::declare(self::buildClockWindow(self::REPLAY_RUN_START, 300), [self::PINNED_MOMENT], clockTextPrefixes: ['(started ']);
        $recordedText = 'An import is in progress for this store (started ' . date('H:i', (int)strtotime(self::RECORD_RUN_START) + 10) . ') — open 08:30–18:00';
        $replayedText = 'An import is in progress for this store (started ' . date('H:i', (int)strtotime(self::REPLAY_RUN_START) + 10) . ') — open 08:30–18:00';

        self::assertSame($recordRule->normalizeClockTextPhrases($recordedText), $replayRule->normalizeClockTextPhrases($replayedText));
        self::assertStringContainsString('(started <clock>)', $replayRule->normalizeClockTextPhrases($replayedText));
        self::assertStringContainsString('open 08:30–18:00', $replayRule->normalizeClockTextPhrases($replayedText), 'a time after no declared prefix stays raw');
        self::assertSame(
            'in progress (started 03:12)',
            $replayRule->normalizeClockTextPhrases('in progress (started 03:12)'),
            'a time of day outside the run window (an import started hours before — a real change) stays raw'
        );
        self::assertSame(
            'in progress (started ' . date('H:i', (int)strtotime(self::REPLAY_RUN_START) + 10) . ')',
            RecordedRunWallClockNormalization::declare(self::buildClockWindow(self::REPLAY_RUN_START, 300))
                ->normalizeClockTextPhrases('in progress (started ' . date('H:i', (int)strtotime(self::REPLAY_RUN_START) + 10) . ')'),
            'without a declared prefix free text is compared raw'
        );
    }

    public function testADeclaredClockPhraseInsideAJsonResultTextIsNormalisedLikeTheTopLevel(): void
    {
        $replayRule = RecordedRunWallClockNormalization::declare(self::buildClockWindow(self::REPLAY_RUN_START, 300), clockTextPrefixes: ['(started ']);
        $resultText = '{"content":[{"type":"text","text":"refused (started ' . date('H:i', (int)strtotime(self::REPLAY_RUN_START)) . ') — status RUNNING"}]}';

        self::assertStringContainsString('(started <clock>)', $replayRule->normalizeJsonText($resultText));
    }

    public function testDecodedJsonOfAnyShapeKeepsItsShapeAndKeys(): void
    {
        $replayRule = self::buildRunWallClockRule(self::REPLAY_RUN_START);
        $replayedMoment = date('Y-m-d H:i:s', (int)strtotime(self::REPLAY_RUN_START) + 5);
        $decodedListOfObjects = [['type' => 'text', 'createdAt' => $replayedMoment], ['type' => 'image', 'nested' => [[1, 2], ['updated' => $replayedMoment]]]];

        self::assertSame(
            [['type' => 'text', 'createdAt' => '<clock>'], ['type' => 'image', 'nested' => [[1, 2], ['updated' => '<clock>']]]],
            $replayRule->normalizeStructure($decodedListOfObjects),
            'a list, objects inside it and lists inside those: same shape and keys, only the moments replaced'
        );
        self::assertSame([], $replayRule->normalizeStructure([]));
    }

    public function testAMicrosecondWallClockStampUnderAWallUsNameReadsTheClockAndAnOutOfWindowOneStaysRaw(): void
    {
        $recordRule = self::buildRunWallClockRule(self::RECORD_RUN_START);
        $replayRule = self::buildRunWallClockRule(self::REPLAY_RUN_START);
        $recordedBody = ['orderId' => 5, 'dispatchedAtWallUs' => strtotime(self::RECORD_RUN_START) + 3.061636];
        $replayedBody = ['orderId' => 5, 'dispatchedAtWallUs' => strtotime(self::REPLAY_RUN_START) + 7.116382];

        self::assertSame($recordRule->normalizeStructure($recordedBody), $replayRule->normalizeStructure($replayedBody));
        self::assertSame(['dispatchedAtWallUs' => 1000000000.5], $replayRule->normalizeStructure(['dispatchedAtWallUs' => 1000000000.5]));
        self::assertNotSame(
            $recordRule->normalizeStructure($recordedBody),
            $replayRule->normalizeStructure(['orderId' => 6] + $replayedBody),
            'the rest of the body is still compared'
        );
    }

    public function testASourceInstantInsideTheRunReadsTheClockAndALowerCaseInstantNameStaysRaw(): void
    {
        $recordRule = self::buildRunWallClockRule(self::RECORD_RUN_START);
        $replayRule = self::buildRunWallClockRule(self::REPLAY_RUN_START);
        $recordedRow = ['sourceInstant' => date('Y-m-d H:i:s', (int)strtotime(self::RECORD_RUN_START) + 9)];
        $replayedRow = ['sourceInstant' => date('Y-m-d H:i:s', (int)strtotime(self::REPLAY_RUN_START) + 18)];

        self::assertSame($recordRule->normalizeStructure($recordedRow), $replayRule->normalizeStructure($replayedRow));
        self::assertSame(['instant' => $replayedRow['sourceInstant']], $replayRule->normalizeStructure(['instant' => $replayedRow['sourceInstant']]));
    }

    public function testADatedClockPhraseAfterADeclaredPrefixReadsTheClockAndAnOutOfWindowOneStaysRaw(): void
    {
        $replayRule = RecordedRunWallClockNormalization::declare(self::buildClockWindow(self::REPLAY_RUN_START, 300), [self::PINNED_MOMENT], clockTextPrefixes: ['via the console, ']);
        $inWindow = 'CREATE via the console, ' . date('Y-m-d H:i', (int)strtotime(self::REPLAY_RUN_START) + 60) . ' — "A calm page"';
        $outOfWindow = 'CREATE via the console, 2020-01-01 10:00 — "A calm page"';

        self::assertSame('CREATE via the console, <clock> — "A calm page"', $replayRule->normalizeClockTextPhrases($inWindow));
        self::assertSame($outOfWindow, $replayRule->normalizeClockTextPhrases($outOfWindow));
    }

    public function testATimestampAndATimeOfNameInsideTheRunReadTheClock(): void
    {
        $recordRule = self::buildRunWallClockRule(self::RECORD_RUN_START);
        $replayRule = self::buildRunWallClockRule(self::REPLAY_RUN_START);
        $recordedRow = ['timestamp' => (int)strtotime(self::RECORD_RUN_START) + 4, 'timeOfCompletion' => date('Y-m-d H:i:s', (int)strtotime(self::RECORD_RUN_START) + 4)];
        $replayedRow = ['timestamp' => (int)strtotime(self::REPLAY_RUN_START) + 9, 'timeOfCompletion' => date('Y-m-d H:i:s', (int)strtotime(self::REPLAY_RUN_START) + 9)];

        self::assertSame($recordRule->normalizeStructure($recordedRow), $replayRule->normalizeStructure($replayedRow));
        self::assertSame(['timeout' => 30], $replayRule->normalizeStructure(['timeout' => 30]));
    }
}
