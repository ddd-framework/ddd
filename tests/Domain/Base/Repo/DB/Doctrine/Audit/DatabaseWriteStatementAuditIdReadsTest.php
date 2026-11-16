<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseIdsAboveHighWaterMarks;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseRowScopeColumns;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseTableNextIds;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseUnscopedTableRead;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit\Fixtures\ScriptedCatalogConnection;
use PHPUnit\Framework\TestCase;

/**
 * The id reads of the audit, per database: the catalog read of the next id of every table (MariaDB's
 * `information_schema.TABLES.AUTO_INCREMENT`, PostgreSQL's identity and serial sequences), the column names of the
 * grown tables, and the read of a table's created ids through the first scope column the table has. The SQL text is
 * pinned per database, the control flow runs on a scripted connection; running the SQL needs a database (the
 * integration suite).
 */
class DatabaseWriteStatementAuditIdReadsTest extends TestCase
{
    public function testTheMariaDbCatalogReadsAreUnchanged(): void
    {
        $mariaDbDialect = new MariaDbDialect();
        self::assertSame(
            'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL',
            DatabaseWriteStatementAudit::buildNextIdsOfTablesSql($mariaDbDialect, false)
        );
        self::assertSame(
            'SELECT t.TABLE_NAME, c.COLUMN_NAME, t.AUTO_INCREMENT FROM information_schema.TABLES t'
            . ' JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.EXTRA LIKE \'%auto_increment%\''
            . ' WHERE t.TABLE_SCHEMA = DATABASE() AND t.AUTO_INCREMENT IS NOT NULL',
            DatabaseWriteStatementAudit::buildNextIdsOfTablesSql($mariaDbDialect, true)
        );
        self::assertSame(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?)',
            DatabaseWriteStatementAudit::buildColumnsOfTablesSql($mariaDbDialect)
        );
    }

    public function testThePostgresCatalogReadsReadTheSequencesOfTheCurrentSchema(): void
    {
        $postgresDialect = new PostgresDialect();
        $expectedNextIdsSql = 'SELECT c.table_name AS "TABLE_NAME", c.column_name AS "COLUMN_NAME", s.last_value + 1 AS "AUTO_INCREMENT"'
            . ' FROM information_schema.columns c'
            . ' LEFT JOIN pg_sequences s ON quote_ident(s.schemaname) || \'.\' || quote_ident(s.sequencename)'
            . ' = pg_get_serial_sequence(quote_ident(c.table_schema) || \'.\' || quote_ident(c.table_name), c.column_name)'
            . ' WHERE c.table_schema = current_schema() AND (c.is_identity = \'YES\' OR c.column_default LIKE \'nextval(%\')'
            . ' ORDER BY c.table_name, c.ordinal_position';
        self::assertSame($expectedNextIdsSql, DatabaseWriteStatementAudit::buildNextIdsOfTablesSql($postgresDialect, false));
        self::assertSame($expectedNextIdsSql, DatabaseWriteStatementAudit::buildNextIdsOfTablesSql($postgresDialect, true), 'PostgreSQL always names the id column');
        self::assertSame(
            'SELECT table_name AS "TABLE_NAME", column_name AS "COLUMN_NAME" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (?)',
            DatabaseWriteStatementAudit::buildColumnsOfTablesSql($postgresDialect)
        );
        self::assertSame('SELECT COALESCE(MAX("id"), 0) FROM "Items"', DatabaseWriteStatementAudit::buildHighestIdOfTableSql($postgresDialect, 'Items', 'id'));
        self::assertSame('SELECT COALESCE(MAX(`id`), 0) FROM `Items`', DatabaseWriteStatementAudit::buildHighestIdOfTableSql(new MariaDbDialect(), 'Items', 'id'));
    }

    public function testATableWithSeveralIdentityColumnsIsReadThroughTheColumnNamedIdElseTheFirst(): void
    {
        $databaseTableNextIds = DatabaseTableNextIds::fromCatalogRows([
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'sequenceNumber', 'AUTO_INCREMENT' => 900],
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 43],
            ['TABLE_NAME' => 'Logs', 'COLUMN_NAME' => 'entryNumber', 'AUTO_INCREMENT' => 8],
            ['TABLE_NAME' => 'Logs', 'COLUMN_NAME' => 'batchNumber', 'AUTO_INCREMENT' => 3],
            ['TABLE_NAME' => 'Fresh', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => null],
        ]);

        self::assertCount(3, $databaseTableNextIds);
        self::assertSame('id', $databaseTableNextIds->findByTableName('Items')?->idColumnName);
        self::assertSame(43, $databaseTableNextIds->findByTableName('Items')?->nextId);
        self::assertSame('entryNumber', $databaseTableNextIds->findByTableName('Logs')?->idColumnName, 'none is named id: the first by position');
        self::assertSame(8, $databaseTableNextIds->findByTableName('Logs')?->nextId);
        self::assertNull($databaseTableNextIds->findByTableName('Fresh')?->nextId, 'a sequence never called: unknown');
    }

    public function testASequenceNeverCalledTakesItsMarkFromTheTablesHighestId(): void
    {
        $scriptedCatalogConnection = ScriptedCatalogConnection::forDriver('pdo_pgsql');
        $scriptedCatalogConnection->nextIdRows = [
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 43],
            ['TABLE_NAME' => 'Copied', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => null],
        ];
        $scriptedCatalogConnection->highestIdsByTable = ['Copied' => 17];

        self::assertSame(['Items' => 42, 'Copied' => 17], DatabaseWriteStatementAudit::findIdHighWaterMarksOfAllTables($scriptedCatalogConnection));
        self::assertContains('SELECT COALESCE(MAX("id"), 0) FROM "Copied"', $scriptedCatalogConnection->askedSql, 'one statement for the table whose sequence was never called');
        self::assertNotContains('SELECT COALESCE(MAX("id"), 0) FROM "Items"', $scriptedCatalogConnection->askedSql);
    }

    public function testTheCreatedIdReadFindsTheGrownTablesReportsTheUnscopedOnesAndTheCappedOnes(): void
    {
        $scriptedCatalogConnection = ScriptedCatalogConnection::forDriver('pdo_mysql');
        $scriptedCatalogConnection->nextIdRows = [
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 104],
            ['TABLE_NAME' => 'Logs', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 13],
            ['TABLE_NAME' => 'Unchanged', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 6],
            ['TABLE_NAME' => 'NotMarked', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 99],
        ];
        $scriptedCatalogConnection->columnRows = [
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'id'],
            ['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'ownerId'],
            ['TABLE_NAME' => 'Logs', 'COLUMN_NAME' => 'id'],
        ];
        $scriptedCatalogConnection->createdIdsByTable = ['Items' => [101, 102], 'Logs' => [11, 12]];

        $databaseIdsAboveHighWaterMarks = DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(
            $scriptedCatalogConnection,
            ['Items' => 100, 'Logs' => 10, 'Unchanged' => 5],
            DatabaseRowScopeColumns::fromValuesByColumn(['ownerId' => [5]]),
            2
        );

        self::assertSame([
            'idsByTable' => ['Items' => [101, 102], 'Logs' => [11, 12]],
            'scopeColumnByTable' => ['Items' => 'ownerId'],
            'unscopedTables' => ['Logs' => ['read' => 2, 'allocated' => 2]],
            'cappedTables' => ['Items', 'Logs'],
        ], $databaseIdsAboveHighWaterMarks->toArray(), 'a table that did not grow and a table without a mark are not read');
        self::assertContains('SELECT `id` FROM `Items` WHERE `id` > ? AND (`ownerId` IN (5)) ORDER BY `id` LIMIT 2', $scriptedCatalogConnection->askedSql);
        self::assertContains('SELECT `id` FROM `Logs` WHERE `id` > ? ORDER BY `id` LIMIT 2', $scriptedCatalogConnection->askedSql);
        self::assertCount(4, $scriptedCatalogConnection->askedSql, 'the catalog read, the column read, one id read per grown table');
    }

    public function testNoGrownTableAsksNoFurtherStatement(): void
    {
        $scriptedCatalogConnection = ScriptedCatalogConnection::forDriver('pdo_mysql');
        $scriptedCatalogConnection->nextIdRows = [['TABLE_NAME' => 'Items', 'COLUMN_NAME' => 'id', 'AUTO_INCREMENT' => 101]];

        self::assertSame(
            ['idsByTable' => [], 'scopeColumnByTable' => [], 'unscopedTables' => [], 'cappedTables' => []],
            DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks($scriptedCatalogConnection, ['Items' => 100])->toArray()
        );
        self::assertCount(1, $scriptedCatalogConnection->askedSql);
    }

    public function testATableIsReadThroughTheFirstScopeColumnItHasAndAColumnMaySelectRowsByIdToo(): void
    {
        $rowScopeColumns = DatabaseRowScopeColumns::fromValuesByColumn(
            ['treeId' => [], 'rootItemId' => [77], 'ownerId' => [5]],
            ['rootItemId']
        );
        self::assertNull($rowScopeColumns->findFirstColumnAmong(['id', 'treeId']), 'a column without values is skipped');
        self::assertSame('ownerId', $rowScopeColumns->findFirstColumnAmong(['id', 'ownerId'])?->columnName);
        $rootItemColumn = $rowScopeColumns->findFirstColumnAmong(['id', 'ownerId', 'rootItemId']);
        self::assertSame('rootItemId', $rootItemColumn?->columnName, 'the priority order of the caller decides, not the table\'s');

        self::assertSame(
            'SELECT `id` FROM `Items` WHERE `id` > ? AND (`rootItemId` IN (77) OR `id` IN (77)) ORDER BY `id` LIMIT 5000',
            DatabaseWriteStatementAudit::buildIdsAboveHighWaterMarkSql(new MariaDbDialect(), 'Items', 'id', $rootItemColumn, 5000)
        );
        self::assertSame(
            'SELECT "id" FROM "Items" WHERE "id" > ? AND ("rootItemId" IN (77) OR "id" IN (77)) ORDER BY "id" LIMIT 5000',
            DatabaseWriteStatementAudit::buildIdsAboveHighWaterMarkSql(new PostgresDialect(), 'Items', 'id', $rootItemColumn, 5000)
        );
        self::assertSame(
            'SELECT "id" FROM "Items" WHERE "id" > ? AND ("ownerId" IN (5)) ORDER BY "id" LIMIT 10',
            DatabaseWriteStatementAudit::buildIdsAboveHighWaterMarkSql(new PostgresDialect(), 'Items', 'id', $rowScopeColumns->findFirstColumnAmong(['ownerId']), 10)
        );
        self::assertSame(
            'SELECT `id` FROM `Items` WHERE `id` > ? ORDER BY `id` LIMIT 10',
            DatabaseWriteStatementAudit::buildIdsAboveHighWaterMarkSql(new MariaDbDialect(), 'Items', 'id', null, 10),
            'unscoped'
        );
    }

    public function testTheReadReportIsStoredInItsKeysAndOrder(): void
    {
        $databaseIdsAboveHighWaterMarks = new DatabaseIdsAboveHighWaterMarks();
        $databaseIdsAboveHighWaterMarks->idsByTable = ['Items' => [101, 102], 'Logs' => [9]];
        $databaseIdsAboveHighWaterMarks->scopeColumnByTable = ['Items' => 'ownerId'];
        $databaseUnscopedTableRead = DatabaseUnscopedTableRead::forTable('Logs', 1, 3);
        $databaseIdsAboveHighWaterMarks->unscopedTableReads->add($databaseUnscopedTableRead);
        $databaseIdsAboveHighWaterMarks->cappedTables = ['Items'];

        self::assertSame([
            'idsByTable' => ['Items' => [101, 102], 'Logs' => [9]],
            'scopeColumnByTable' => ['Items' => 'ownerId'],
            'unscopedTables' => ['Logs' => ['read' => 1, 'allocated' => 3]],
            'cappedTables' => ['Items'],
        ], $databaseIdsAboveHighWaterMarks->toArray());
        self::assertSame(
            ['idsByTable' => [], 'scopeColumnByTable' => [], 'unscopedTables' => [], 'cappedTables' => []],
            (new DatabaseIdsAboveHighWaterMarks())->toArray(),
            'a read that found no grown table'
        );
    }
}
