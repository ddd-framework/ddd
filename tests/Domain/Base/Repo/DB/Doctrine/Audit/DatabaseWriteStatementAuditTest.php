<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatement;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementNormalization;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementShapes;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidationModel;
use DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit\Fixtures\AuditedSqliteRun;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\DebugStack;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Every statement that writes is kept at the DBAL connection — kind, table, columns, values, multiplicity, order — and
 * normalised only where two runs differ by construction and the caller declared it: ids MINTED during the call, the
 * now-moments of sites without a pinned clock, declared derived-moment columns. A pre-existing id is compared raw.
 */
class DatabaseWriteStatementAuditTest extends TestCase
{
    protected static function auditCallWrites(int $paddingRowCount, string $updatedText): DatabaseWriteStatementShapes
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->start($paddingRowCount);
        try {
            $auditedSqliteRun->runCallWrites($updatedText);
        } finally {
            $comparableStatements = $auditedSqliteRun->finish();
        }
        return $comparableStatements;
    }

    protected static function auditFixtureRowUpdate(int $fixtureId): DatabaseWriteStatementShapes
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->start(3);
        try {
            $auditedSqliteRun->connection->executeStatement('UPDATE Notes SET text = ? WHERE id = ?', ['renamed', $fixtureId]);
        } finally {
            $comparableStatements = $auditedSqliteRun->finish();
        }
        return $comparableStatements;
    }

    protected static function auditInsertAndUpdate(bool $insertFirst): DatabaseWriteStatementShapes
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->start(1);
        try {
            if ($insertFirst) {
                $auditedSqliteRun->connection->executeStatement('INSERT INTO Notes (ownerId, text) VALUES (?, ?)', [7, 'a']);
            }
            $auditedSqliteRun->connection->executeStatement('UPDATE Notes SET text = ? WHERE ownerId = ?', ['b', 0]);
            if (!$insertFirst) {
                $auditedSqliteRun->connection->executeStatement('INSERT INTO Notes (ownerId, text) VALUES (?, ?)', [7, 'a']);
            }
        } finally {
            $comparableStatements = $auditedSqliteRun->finish();
        }
        return $comparableStatements;
    }

    public function testEveryWriteIsKeptInOrderAndReadsAreNot(): void
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $previousLogger = new DebugStack();
        $auditedSqliteRun->configuration->setSQLLogger($previousLogger);
        $auditedSqliteRun->start(0);
        try {
            $auditedSqliteRun->runCallWrites('changed');
        } finally {
            $auditedSqliteRun->databaseWriteStatementAudit->stop($auditedSqliteRun->buildScopeConfigurations());
        }
        $auditedSqliteRun->connection->executeStatement('INSERT INTO Notes (ownerId, text) VALUES (1, \'after the audit\')');

        self::assertSame(['note' => 0], $auditedSqliteRun->highWaterMarksByFamily);
        self::assertSame($previousLogger, $auditedSqliteRun->configuration->getSQLLogger(), 'the previous logger is back');
        self::assertCount(7, $previousLogger->queries, 'the previous logger kept receiving every statement: the mark read, five of the call, one after');
        self::assertFalse($auditedSqliteRun->databaseWriteStatementAudit->wasInterrupted);
        $keptKinds = [];
        foreach ($auditedSqliteRun->databaseWriteStatementAudit->statements->getElements() as $databaseWriteStatement) {
            $keptKinds[] = $databaseWriteStatement->kind;
        }
        self::assertSame(['INSERT', 'INSERT', 'UPDATE', 'DELETE'], $keptKinds);

        $comparableStatements = $auditedSqliteRun->databaseWriteStatementAudit->buildComparableStatements(['Notes.id' => 'note'], $auditedSqliteRun->highWaterMarksByFamily)->toArray();
        self::assertSame([
            'kind' => 'INSERT',
            'table' => 'Notes',
            'columns' => ['ownerId', 'text', 'created'],
            'rows' => [['ownerId' => 7, 'text' => 'first', 'created' => DatabaseWriteStatementNormalization::CLOCK_PLACEHOLDER]],
            'onDuplicateKeyUpdate' => null,
        ], $comparableStatements[0], 'the wall-clock moment of the call reads <clock>');
        self::assertSame('2020-01-01 00:00:00', $comparableStatements[1]['rows'][0]['created'], 'a moment outside the call stays raw');
        self::assertSame(['text' => 'changed'], $comparableStatements[2]['set'], 'named parameters bind by name');
        self::assertSame([['column' => 'id', 'value' => '<note#1>']], $comparableStatements[2]['whereValues']);
        self::assertSame([['column' => 'id', 'value' => ['<note#2>']]], $comparableStatements[3]['whereValues']);
    }

    public function testASecondStartIsRefusedAndTheStopLeavesNoLoggerBehind(): void
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->start(0);
        try {
            $auditedSqliteRun->databaseWriteStatementAudit->startOnScopeConfigurations($auditedSqliteRun->buildScopeConfigurations());
            self::fail('a second start is refused');
        } catch (LogicException) {
        } finally {
            $auditedSqliteRun->databaseWriteStatementAudit->stop($auditedSqliteRun->buildScopeConfigurations());
        }
        self::assertNull($auditedSqliteRun->configuration->getSQLLogger());
        self::assertFalse($auditedSqliteRun->databaseWriteStatementAudit->isStarted());
    }

    public function testTwoRunsCompareEqualAndAChangedValueIsReported(): void
    {
        $recordedStatements = self::auditCallWrites(1, 'changed')->toRecordedSequence();
        self::assertSame([], RecordedSequenceComparison::buildDifferences('statement', $recordedStatements, self::auditCallWrites(3, 'changed')->toRecordedSequence()), 'minted ids differ by construction');

        $differences = RecordedSequenceComparison::buildDifferences('statement', $recordedStatements, self::auditCallWrites(1, 'changed differently')->toRecordedSequence());
        self::assertCount(1, $differences);
        self::assertStringStartsWith('statement #2: ', $differences[0]);
        self::assertStringContainsString('changed differently', $differences[0]);
    }

    public function testAPreExistingIdIsComparedRaw(): void
    {
        // both rows exist before the call: updating fixture 2 instead of fixture 1 is a real difference
        $recordedStatements = self::auditFixtureRowUpdate(1);
        self::assertSame([['column' => 'id', 'value' => 1]], $recordedStatements->toArray()[0]['whereValues'], 'at or below the high-water mark: raw');
        self::assertCount(1, RecordedSequenceComparison::buildDifferences('statement', $recordedStatements->toRecordedSequence(), self::auditFixtureRowUpdate(2)->toRecordedSequence()));

        $upsertSql = 'INSERT INTO items (id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)';
        $mariaDbDialect = new MariaDbDialect();
        self::assertNotSame(
            DatabaseWriteStatement::fromIssuedStatement('INSERT', $upsertSql, [42, 'Pub'], $mariaDbDialect)->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item'], ['item' => 100]))->toArray(),
            DatabaseWriteStatement::fromIssuedStatement('INSERT', $upsertSql, [43, 'Pub'], $mariaDbDialect)->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item'], ['item' => 100]))->toArray(),
            'id = 42 and id = 43 of pre-existing rows differ'
        );
        $mintedRow = DatabaseWriteStatement::fromIssuedStatement('INSERT', $upsertSql, [101, 'Pub'], $mariaDbDialect)->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item'], ['item' => 100]))->rows->first();
        self::assertSame('<item#1>', $mintedRow->getValueOfColumn('id'), 'above the mark: minted');
        self::assertSame(42, DatabaseWriteStatement::fromIssuedStatement('INSERT', $upsertSql, [42, 'Pub'], $mariaDbDialect)->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item']))->toArray()['rows'][0]['id'], 'no mark declared: raw');
    }

    public function testMultiRowInsertsNullsDerivedMomentsAndSavepointRollbacks(): void
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->start(0);
        try {
            $auditedSqliteRun->connection->executeStatement('SAVEPOINT DDD_UPSERT_INSERT_RIGHTS_1');
            $auditedSqliteRun->connection->executeStatement(
                'INSERT INTO Notes (ownerId, text, remindAt) VALUES (?, ?, ?), (?, NULL, ?)',
                [7, null, date('Y-m-d H:i:s', time() + 7 * 86400), 8, null]
            );
            $auditedSqliteRun->connection->executeStatement('ROLLBACK TO SAVEPOINT DDD_UPSERT_INSERT_RIGHTS_1');
        } finally {
            $recordedStatements = $auditedSqliteRun->finish()->toArray();
        }
        self::assertSame([
            ['ownerId' => 7, 'text' => null, 'remindAt' => DatabaseWriteStatementNormalization::DERIVED_CLOCK_PLACEHOLDER],
            ['ownerId' => 8, 'text' => 'SQL:NULL', 'remindAt' => null],
        ], $recordedStatements[0]['rows'], 'two rows, a NULL parameter, a NULL literal, a declared derived moment');
        self::assertSame(['kind' => 'ROLLBACK TO SAVEPOINT'], $recordedStatements[1], 'the rollback of the insert is part of the sequence');
    }

    public function testAStatementOrderSwapIsReported(): void
    {
        $differences = RecordedSequenceComparison::buildDifferences('statement', self::auditInsertAndUpdate(true)->toRecordedSequence(), self::auditInsertAndUpdate(false)->toRecordedSequence());
        self::assertCount(2, $differences, 'same statements, other order: both positions differ');
    }

    public function testATimeColumnGateAppliesToStringMomentsToo(): void
    {
        $now = date('Y-m-d H:i:s');
        $databaseWriteStatement = DatabaseWriteStatement::fromIssuedStatement('INSERT', 'INSERT INTO Notes (text, created) VALUES (?, ?)', [$now, $now], new MariaDbDialect());
        $comparableStatement = $databaseWriteStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(clockWindow: RecordedRunClockWindow::between(time() - 5, time() + 5)))->toArray();
        self::assertSame(['text' => $now, 'created' => DatabaseWriteStatementNormalization::CLOCK_PLACEHOLDER], $comparableStatement['rows'][0], 'a moment in a column that is no time column stays raw');
    }

    public function testAnUnparsableStatementIsComparedRaw(): void
    {
        $databaseWriteStatement = DatabaseWriteStatement::fromIssuedStatement('INSERT', 'INSERT INTO Notes SELECT * FROM NotesArchive WHERE id = ?', [42], new MariaDbDialect());
        self::assertSame(
            ['kind' => 'INSERT', 'unparsedSql' => 'INSERT INTO Notes SELECT * FROM NotesArchive WHERE id = ?', 'parameters' => [42]],
            $databaseWriteStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(['Notes.id' => 'note'], ['note' => 0]))->toArray()
        );
    }

    public function testAReplacedConfigurationMarksTheAuditInterrupted(): void
    {
        $auditedSqliteRun = new AuditedSqliteRun();
        $auditedSqliteRun->databaseWriteStatementAudit->startOnScopeConfigurations($auditedSqliteRun->buildScopeConfigurations());
        $auditedSqliteRun->databaseWriteStatementAudit->stop($auditedSqliteRun->buildScopeConfigurations(new Configuration()));
        self::assertTrue($auditedSqliteRun->databaseWriteStatementAudit->wasInterrupted);
        self::assertNull($auditedSqliteRun->configuration->getSQLLogger());
    }

    public function testTheFrameworkUpsertAndARightsScopedUpdateAreParsed(): void
    {
        $mariaDbDialect = new MariaDbDialect();
        $upsertStatement = DatabaseWriteStatement::fromIssuedStatement(
            'INSERT',
            'INSERT INTO items (id, name, modified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), modified = VALUES(modified)',
            [0 => 42, 1 => "O'Brien, (Pub)", 2 => 1760000000],
            $mariaDbDialect
        );
        $comparableUpsert = $upsertStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item'], ['item' => 1000], RecordedRunClockWindow::between(1759999990, 1760000010)));
        self::assertSame(['id' => 42, 'name' => "O'Brien, (Pub)", 'modified' => DatabaseWriteStatementNormalization::CLOCK_PLACEHOLDER], $comparableUpsert->toArray()['rows'][0]);
        self::assertSame('SQL:name = VALUES(name), modified = VALUES(modified)', $comparableUpsert->getConflictClauseValue());

        $rightsScopedUpdate = DatabaseWriteStatement::fromIssuedStatement(
            'UPDATE',
            'UPDATE items SET `name` = ?, `counter` = counter + ? WHERE `id` = ? AND `id` IN (SELECT * FROM (SELECT s0_.id FROM items s0_ WHERE s0_.owner_id = ?) dddUpdateRightsScope)',
            [0 => 'New', 1 => 1, 2 => 42, 3 => 581],
            $mariaDbDialect
        );
        $comparableUpdate = $rightsScopedUpdate->buildComparableShape(new DatabaseWriteStatementNormalization());
        self::assertSame(['name' => 'New', 'counter' => 'SQL:counter + 1'], $comparableUpdate->toArray()['set']);
        self::assertSame('SQL:counter + 1', $comparableUpdate->getAssignedValueOfColumn('counter'));
        self::assertSame([['column' => 'id', 'value' => 42], ['column' => 'owner_id', 'value' => 581]], $comparableUpdate->toArray()['whereValues']);

        self::assertNull(DatabaseWriteStatement::detectWriteKind('SELECT 1'));
        self::assertSame('ROLLBACK TO SAVEPOINT', DatabaseWriteStatement::detectWriteKind('ROLLBACK TO SAVEPOINT DDD_UPSERT_INSERT_RIGHTS_3'));
    }

    public function testTheRandomCacheScopeHousekeepingIsLeftOutButAnOwnDeleteOnTheTableIsKept(): void
    {
        $housekeepingTable = DBCacheScopeInvalidationModel::getTableName();
        $databaseWriteStatementAudit = new DatabaseWriteStatementAudit();
        $mariaDbDialect = new MariaDbDialect();
        foreach ([
            ["DELETE FROM {$housekeepingTable} WHERE (numberOfTimesToInvalidateCache is not null and numberOfTimesToInvalidateCache <= 0) \n                  OR (invalidateUntil is not null and invalidateUntil < :currentDate)", ['2026-10-22 12:00:00']],
            ["DELETE FROM {$housekeepingTable} WHERE (numberOfTimesToInvalidateCache IS NOT NULL AND numberOfTimesToInvalidateCache <= 0) OR (invalidateUntil IS NOT NULL AND invalidateUntil < ?)", ['2026-10-22 12:00:00']],
            ["DELETE FROM \"{$housekeepingTable}\" WHERE (\"numberOfTimesToInvalidateCache\" IS NOT NULL AND \"numberOfTimesToInvalidateCache\" <= 0) OR (\"invalidateUntil\" IS NOT NULL AND \"invalidateUntil\" < ?)", ['2026-10-22 12:00:00']],
            ["DELETE FROM {$housekeepingTable} WHERE id = ?", [7]],
        ] as [$sql, $parameters]) {
            $databaseWriteStatement = DatabaseWriteStatement::fromIssuedStatement('DELETE', $sql, $parameters, $mariaDbDialect);
            $databaseWriteStatementAudit->statements->add($databaseWriteStatement);
        }

        $comparableStatements = $databaseWriteStatementAudit->buildComparableStatements()->toArray();

        self::assertCount(1, $comparableStatements, 'only the housekeeping statements are left out, in any letter case and quoting');
        self::assertSame('DELETE', $comparableStatements[0]['kind']);
        self::assertSame([['column' => 'id', 'value' => 7]], $comparableStatements[0]['whereValues']);
    }
}
