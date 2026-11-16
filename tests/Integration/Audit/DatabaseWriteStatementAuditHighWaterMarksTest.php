<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Audit;

use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The audit's id reads against a real database, on both engines: the high-water mark of every table and the ids
 * created above a mark. The PostgreSQL case that the catalog alone cannot answer: rows written with explicit ids and a
 * sequence set with `setval(…, false)` (what a copy into PostgreSQL leaves) — `pg_sequences.last_value` is NULL then,
 * and the mark comes from the table's highest id instead of 0.
 */
class DatabaseWriteStatementAuditHighWaterMarksTest extends IntegrationTestCase
{
    protected const string TABLE = 'CounterRows';

    /** Writes three rows with the explicit ids 1, 2, 3; on PostgreSQL the sequence is then set as never called. */
    protected function insertThreeRowsWithExplicitIdsAndASequenceNeverCalled(): void
    {
        $connection = static::connection();
        for ($rowId = 1; $rowId <= 3; $rowId++) {
            $connection->executeStatement(
                'INSERT INTO ' . static::quoteIdentifier(self::TABLE) . ' (' . static::quoteIdentifier('id') . ', ' . static::quoteIdentifier('businessKey') . ') VALUES (?, ?)',
                [$rowId, 'high-water-mark-' . $rowId]
            );
        }
        if (static::dialect()->name() === SqlDialect::POSTGRES) {
            $connection->executeQuery(
                'SELECT setval(pg_get_serial_sequence(?, ?), 4, false)',
                [static::quoteIdentifier(self::TABLE), 'id']
            )->fetchOne();
            $this->assertNull(
                $connection->fetchOne(
                    'SELECT s.last_value FROM pg_sequences s WHERE quote_ident(s.schemaname) || \'.\' || quote_ident(s.sequencename) = pg_get_serial_sequence(?, ?)',
                    [static::quoteIdentifier(self::TABLE), 'id']
                ),
                'the case under test: the catalog has no last value, the mark must come from the highest id'
            );
        }
    }

    public function testTheMarkOfATableIsItsHighestIdAlsoWhenItsSequenceWasNeverCalled(): void
    {
        $this->insertThreeRowsWithExplicitIdsAndASequenceNeverCalled();

        $highWaterMarksByTable = DatabaseWriteStatementAudit::findIdHighWaterMarksOfAllTables(static::connection());

        $this->assertSame(3, $highWaterMarksByTable[self::TABLE] ?? null);
    }

    public function testTheIdsAboveAMarkAreReadUnscopedWhenTheTableHasNoScopeColumn(): void
    {
        $this->insertThreeRowsWithExplicitIdsAndASequenceNeverCalled();

        $databaseIdsAboveHighWaterMarks = DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(static::connection(), [self::TABLE => 1]);

        $this->assertSame([2, 3], $databaseIdsAboveHighWaterMarks->idsByTable[self::TABLE] ?? null);
        $this->assertSame(
            [self::TABLE => ['read' => 2, 'allocated' => 2]],
            array_intersect_key($databaseIdsAboveHighWaterMarks->toArray()['unscopedTables'], [self::TABLE => true])
        );
    }
}
