<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatement;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementNormalization;
use PHPUnit\Framework\TestCase;

/**
 * The statement parser reads each statement in the syntax of the database that issued it: the identifier quote and
 * the conflict clause of an upsert come from the connection's {@see SqlDialect}. One statement per write kind per
 * database; the comparable shape of the same write is the same on both, except the conflict clause's stored key.
 */
class DatabaseWriteStatementDialectSyntaxTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected static function buildStoredShape(string $sql, array $parameters, SqlDialect $sqlDialect): array
    {
        $databaseWriteStatement = DatabaseWriteStatement::fromIssuedStatement((string)DatabaseWriteStatement::detectWriteKind($sql), $sql, $parameters, $sqlDialect);
        return $databaseWriteStatement->buildComparableShape(DatabaseWriteStatementNormalization::declare(['items.id' => 'item'], ['item' => 100]))->toArray();
    }

    public function testTheDialectsNameTheirIdentifierQuoteAndConflictClause(): void
    {
        self::assertSame('`', (new MariaDbDialect())->identifierQuoteCharacter());
        self::assertSame('ON DUPLICATE KEY UPDATE', (new MariaDbDialect())->upsertConflictClause());
        self::assertSame('"', (new PostgresDialect())->identifierQuoteCharacter());
        self::assertSame('ON CONFLICT', (new PostgresDialect())->upsertConflictClause());
    }

    public function testAnInsertParsesOnBothDatabases(): void
    {
        $expectedRows = [['id' => '<item#1>', 'name' => 'Pub']];
        $mariaDbInsert = self::buildStoredShape('INSERT INTO `items` (`id`, `name`) VALUES (?, ?)', [101, 'Pub'], new MariaDbDialect());
        $postgresInsert = self::buildStoredShape('INSERT INTO "items" ("id", "name") VALUES (?, ?) RETURNING "id"', [101, 'Pub'], new PostgresDialect());

        self::assertSame(['kind' => 'INSERT', 'table' => 'items', 'columns' => ['id', 'name'], 'rows' => $expectedRows, 'onDuplicateKeyUpdate' => null], $mariaDbInsert);
        self::assertSame(['kind' => 'INSERT', 'table' => 'items', 'columns' => ['id', 'name'], 'rows' => $expectedRows, 'onConflict' => null], $postgresInsert);
    }

    public function testAnUpsertParsesWithTheConflictClauseOfItsDatabase(): void
    {
        $mariaDbUpsert = self::buildStoredShape(
            'INSERT INTO `items` (`id`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)',
            [42, 'Pub'],
            new MariaDbDialect()
        );
        $postgresUpsert = self::buildStoredShape(
            'INSERT INTO "items" AS t ("id", "name") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name" RETURNING "id", (xmax = 0) AS inserted',
            [42, 'Pub'],
            new PostgresDialect()
        );
        $postgresInsertSkippingExistingRows = self::buildStoredShape('INSERT INTO "items" ("id", "name") VALUES (?, ?) ON CONFLICT DO NOTHING', [42, 'Pub'], new PostgresDialect());

        self::assertSame([['id' => 42, 'name' => 'Pub']], $mariaDbUpsert['rows']);
        self::assertSame('SQL:`name` = VALUES(`name`)', $mariaDbUpsert['onDuplicateKeyUpdate']);
        self::assertSame('items', $postgresUpsert['table'], 'the table alias of the upsert is no part of the table');
        self::assertSame([['id' => 42, 'name' => 'Pub']], $postgresUpsert['rows']);
        self::assertSame('SQL:("id") DO UPDATE SET "name" = EXCLUDED."name" RETURNING "id", (xmax = 0) AS inserted', $postgresUpsert['onConflict']);
        self::assertSame('SQL:DO NOTHING', $postgresInsertSkippingExistingRows['onConflict']);
    }

    public function testAnUpdateParsesOnBothDatabases(): void
    {
        $expectedWhereValues = [['column' => 'id', 'value' => '<item#1>']];
        $mariaDbUpdate = self::buildStoredShape('UPDATE `items` SET `name` = ? WHERE `id` = ?', ['New', 101], new MariaDbDialect());
        $postgresUpdate = self::buildStoredShape('UPDATE "items" SET "name" = ? WHERE "id" = ?', ['New', 101], new PostgresDialect());

        self::assertSame(['kind' => 'UPDATE', 'table' => 'items', 'set' => ['name' => 'New'], 'where' => '`id` = ?', 'whereValues' => $expectedWhereValues], $mariaDbUpdate);
        self::assertSame(['kind' => 'UPDATE', 'table' => 'items', 'set' => ['name' => 'New'], 'where' => '"id" = ?', 'whereValues' => $expectedWhereValues], $postgresUpdate);
    }

    public function testADeleteParsesOnBothDatabases(): void
    {
        $mariaDbDelete = self::buildStoredShape('DELETE FROM `items` WHERE `id` IN (?)', [[101, 5]], new MariaDbDialect());
        $postgresDelete = self::buildStoredShape('DELETE FROM "items" WHERE "id" IN (?)', [[101, 5]], new PostgresDialect());

        self::assertSame('items', $mariaDbDelete['table']);
        self::assertSame('items', $postgresDelete['table']);
        self::assertSame([['column' => 'id', 'value' => ['<item#1>', 5]]], $mariaDbDelete['whereValues']);
        self::assertSame([['column' => 'id', 'value' => ['<item#1>', 5]]], $postgresDelete['whereValues']);
    }

    public function testARollbackToASavepointIsTheSameOnBothDatabases(): void
    {
        self::assertSame(['kind' => 'ROLLBACK TO SAVEPOINT'], self::buildStoredShape('ROLLBACK TO SAVEPOINT UPSERT_1', [], new MariaDbDialect()));
        self::assertSame(['kind' => 'ROLLBACK TO SAVEPOINT'], self::buildStoredShape('ROLLBACK TO SAVEPOINT UPSERT_1', [], new PostgresDialect()));
    }

    public function testAPostgresRightsScopedUpdateWithATableAliasParses(): void
    {
        $postgresRightsScopedUpdate = self::buildStoredShape(
            'UPDATE "items" t SET "name" = ? WHERE t."id" = ? AND t."id" IN (SELECT * FROM (SELECT s0_."id" FROM "items" s0_ WHERE s0_."owner_id" = ?) dddUpdateRightsScope)',
            ['New', 101, 581],
            new PostgresDialect()
        );

        self::assertSame('items', $postgresRightsScopedUpdate['table']);
        self::assertSame(['name' => 'New'], $postgresRightsScopedUpdate['set']);
        self::assertSame([['column' => 'id', 'value' => '<item#1>'], ['column' => 'owner_id', 'value' => 581]], $postgresRightsScopedUpdate['whereValues']);
    }

    public function testAPostgresMultiRowUpsertParsesEveryRow(): void
    {
        $postgresUpsert = self::buildStoredShape(
            'INSERT INTO "items" AS t ("id", "name") VALUES (?, ?), (?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name"',
            [101, 'Pub', 7, 'Bar'],
            new PostgresDialect()
        );

        self::assertSame([['id' => '<item#1>', 'name' => 'Pub'], ['id' => 7, 'name' => 'Bar']], $postgresUpsert['rows']);
        self::assertSame('SQL:("id") DO UPDATE SET "name" = EXCLUDED."name"', $postgresUpsert['onConflict']);
    }

    public function testAPostgresCastPlaceholderIsBoundInsideItsExpression(): void
    {
        $postgresInsert = self::buildStoredShape('INSERT INTO "items" ("id", "state") VALUES (?, ?::jsonb)', [7, '{"open":true}'], new PostgresDialect());

        self::assertSame([['id' => 7, 'state' => 'SQL:"{\\"open\\":true}"::jsonb']], $postgresInsert['rows'], 'the cast is no named placeholder');
    }

    public function testAPostgresStatementReadWithMariaDbSyntaxIsNotRecognised(): void
    {
        $postgresInsertReadAsMariaDb = self::buildStoredShape('INSERT INTO "items" ("id") VALUES (?)', [101], new MariaDbDialect());
        self::assertArrayHasKey('unparsedSql', $postgresInsertReadAsMariaDb, 'the identifier quote is the dialect\'s: read with the wrong one, the table is not found');
    }
}
