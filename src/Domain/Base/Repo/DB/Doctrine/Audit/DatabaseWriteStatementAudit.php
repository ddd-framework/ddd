<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Logging\LoggerChain;
use LogicException;
use Throwable;

/**
 * The statement audit of a recorded or replayed call: while it runs, every INSERT / REPLACE / UPDATE / DELETE (and
 * every rollback to a savepoint) the DBAL connections execute is kept — SQL and bound parameters, in execution order —
 * so two runs of one call are compared by the complete set of statements that write, multiplicity and order included,
 * not by a later read.
 *
 * Hooked at the DBAL connection as its SQL logger (the one hook the connection calls for every `executeQuery`,
 * `executeStatement` and prepared `Statement::execute`, ORM persisters and the framework's upsert alike), chained
 * after a logger already configured, installed only between {@see start()} and {@see stop()}, one
 * {@see DatabaseWriteStatementAuditLogger} per scope so each statement is read in its own database's syntax:
 * - cheap: a SELECT costs one anchored regex; a write is kept raw and parsed only when the comparison asks;
 * - fail-safe: it never throws into the statement — a failure to keep a statement counts in {@see $auditFailureCount};
 * - honest: when the connection's configuration was replaced while the audit ran (an entity manager re-created after a
 *   closed connection), the statements after it were not seen, and {@see $wasInterrupted} says so.
 *
 * Use it as a pair in try/finally: `$audit->start(); try { … } finally { $audit->stop(); }`.
 *
 * Not seen: `beginTransaction` / `commit` / `rollBack` (driver calls, no SQL), writes on a connection not passed to
 * {@see start()}, and writes outside the DBAL (an HTTP client — those are compared by their requests).
 *
 * DBAL 3 marks `SQLLogger` / `LoggerChain` and `Configuration::setSQLLogger()` deprecated and DBAL 4 removes them. The
 * DBAL-4 path is a driver middleware (`Doctrine\DBAL\Driver\Middleware` wrapping connection and statement), which
 * must be configured when the entity manager is CREATED ({@see EntityManagerFactory::create()}) — moving there is part
 * of the DBAL-4 upgrade; until then the SQL logger is the hook that survives a reconnect of the connection.
 *
 * The id reads ({@see findIdHighWaterMarksOfAllTables()}, {@see findIdsAboveHighWaterMarks()}) read the catalog of the
 * connection's database: MariaDB's `information_schema.TABLES.AUTO_INCREMENT`, PostgreSQL's identity and serial
 * sequences (`pg_sequences.last_value`).
 */
class DatabaseWriteStatementAudit
{
    /** @var DatabaseWriteStatements The statements that write, in execution order */
    public DatabaseWriteStatements $statements;

    /** How many statements could not be kept (never thrown into the caller). */
    public int $auditFailureCount = 0;

    /** Whether a connection's configuration was replaced while the audit ran: statements may be missing. */
    public bool $wasInterrupted = false;

    /** Unix seconds of start(), the beginning of the call's wall-clock window. */
    public ?int $startedAtTimestamp = null;

    /** Unix seconds of stop(), the end of the call's wall-clock window. */
    public ?int $stoppedAtTimestamp = null;

    /** @var DatabaseWriteStatementAuditInstallations Where the audit is installed, one element per scope */
    protected DatabaseWriteStatementAuditInstallations $installations;

    public function __construct()
    {
        $this->statements = new DatabaseWriteStatements();
        $this->installations = new DatabaseWriteStatementAuditInstallations();
    }

    /**
     * Starts auditing the connections of the given entity manager scopes.
     *
     * @param string[] $entityManagerScopes
     * @return void
     * @throws LogicException When the audit is already started
     */
    public function start(array $entityManagerScopes = [EntityManagerFactory::SCOPE_DEFAULT]): void
    {
        $this->startOnScopeConfigurations(static::findScopeConfigurationsOfEntityManagers($entityManagerScopes));
    }

    /**
     * The connection configuration and the database of each scope, read through the entity manager factory.
     *
     * @param string[] $entityManagerScopes
     * @return DatabaseScopeConfigurations
     */
    public static function findScopeConfigurationsOfEntityManagers(array $entityManagerScopes): DatabaseScopeConfigurations
    {
        $databaseScopeConfigurations = new DatabaseScopeConfigurations();
        foreach ($entityManagerScopes as $entityManagerScope) {
            $databaseScopeConfiguration = DatabaseScopeConfiguration::forScope(
                $entityManagerScope,
                EntityManagerFactory::getInstance($entityManagerScope)->getConnection()->getConfiguration(),
                EntityManagerFactory::getDialect($entityManagerScope)
            );
            $databaseScopeConfigurations->add($databaseScopeConfiguration);
        }
        return $databaseScopeConfigurations;
    }

    /**
     * Starts auditing the connections of these configurations — {@see start()} without the entity manager lookup.
     *
     * @param DatabaseScopeConfigurations $databaseScopeConfigurations
     * @return void
     * @throws LogicException When the audit is already started
     */
    public function startOnScopeConfigurations(DatabaseScopeConfigurations $databaseScopeConfigurations): void
    {
        if ($this->isStarted()) {
            throw new LogicException('DatabaseWriteStatementAudit: the audit is already started');
        }
        $this->startedAtTimestamp = time();
        foreach ($databaseScopeConfigurations->getElements() as $databaseScopeConfiguration) {
            $configuration = $databaseScopeConfiguration->configuration;
            $previousLogger = $configuration->getSQLLogger();
            $auditLogger = new DatabaseWriteStatementAuditLogger($this, $databaseScopeConfiguration->sqlDialect);
            $installedLogger = $previousLogger !== null ? new LoggerChain([$previousLogger, $auditLogger]) : $auditLogger;
            $configuration->setSQLLogger($installedLogger);
            $databaseWriteStatementAuditInstallation = DatabaseWriteStatementAuditInstallation::onScope($databaseScopeConfiguration, $previousLogger, $installedLogger);
            $this->installations->add($databaseWriteStatementAuditInstallation);
        }
    }

    /**
     * Whether the audit is installed: {@see start()} ran and {@see stop()} did not yet.
     *
     * @return bool
     */
    public function isStarted(): bool
    {
        return $this->installations->count() > 0;
    }

    /**
     * Stops the audit and puts the previous loggers back.
     *
     * @param DatabaseScopeConfigurations|null $currentScopeConfigurations The configurations the scopes use NOW (null:
     *        looked up through the entity manager factory) — a different one than at start means the audit was
     *        interrupted
     * @return void
     */
    public function stop(?DatabaseScopeConfigurations $currentScopeConfigurations = null): void
    {
        $this->stoppedAtTimestamp = time();
        foreach ($this->installations->getElements() as $databaseWriteStatementAuditInstallation) {
            $scopeConfiguration = $databaseWriteStatementAuditInstallation->scopeConfiguration;
            $scopeConfiguration->configuration->setSQLLogger($databaseWriteStatementAuditInstallation->previousLogger);
            try {
                $currentConfiguration = $currentScopeConfigurations !== null
                    ? $currentScopeConfigurations->findByScope($scopeConfiguration->scope)?->configuration
                    : EntityManagerFactory::getInstance($scopeConfiguration->scope)->getConnection()->getConfiguration();
            } catch (Throwable) {
                $currentConfiguration = null;
            }
            if ($currentConfiguration !== $scopeConfiguration->configuration) {
                $this->wasInterrupted = true;
            }
        }
        $this->installations = new DatabaseWriteStatementAuditInstallations();
    }

    /**
     * Keeps one issued statement when it writes; called by the installed {@see DatabaseWriteStatementAuditLogger}.
     * Never throws: a statement it cannot keep counts in {@see $auditFailureCount}.
     *
     * @param string $sql
     * @param array<int|string, mixed> $parameters The bound parameters by position or name
     * @param SqlDialect $sqlDialect The database of the connection that issued it
     * @return void
     */
    public function keepIssuedStatement(string $sql, array $parameters, SqlDialect $sqlDialect): void
    {
        try {
            $writeKind = DatabaseWriteStatement::detectWriteKind($sql);
            if ($writeKind !== null) {
                $databaseWriteStatement = DatabaseWriteStatement::fromIssuedStatement($writeKind, $sql, $parameters, $sqlDialect);
                $this->statements->add($databaseWriteStatement);
            }
        } catch (Throwable) {
            $this->auditFailureCount++;
        }
    }

    /**
     * The audited statements as two runs are compared by, in execution order — without the framework's random
     * cache-scope housekeeping ({@see DatabaseWriteStatement::isRandomCacheScopeInvalidationHousekeeping()}).
     *
     * @param array<string, string> $idFieldFamilies `table.column` → id family for the auto-increment ids
     * @param array<string, int> $highWaterMarksByFamily Id family → the highest id of its table read at call start
     *        ({@see findIdHighWaterMarks()}); only ids above it are ordinals, a family without a mark stays raw
     * @param array<int, string|int> $pinnedMoments The moments the pinned clock answered (kept raw)
     * @param string[] $derivedMomentColumns `table.column` holding moments derived from the wall clock (`<derived-clock>`)
     * @param RecordedRunWallClockNormalization|null $wallClockNormalization The run's wall-clock rule (its window, pinned
     *        moments and declared keys); null: the call's own window with $pinnedMoments
     * @return DatabaseWriteStatementShapes
     */
    public function buildComparableStatements(array $idFieldFamilies = [], array $highWaterMarksByFamily = [], array $pinnedMoments = [], array $derivedMomentColumns = [], ?RecordedRunWallClockNormalization $wallClockNormalization = null): DatabaseWriteStatementShapes
    {
        $clockWindow = $this->startedAtTimestamp !== null
            ? RecordedRunClockWindow::between($this->startedAtTimestamp - 1, ($this->stoppedAtTimestamp ?? time()) + 1)
            : null;
        $databaseWriteStatementNormalization = DatabaseWriteStatementNormalization::declare($idFieldFamilies, $highWaterMarksByFamily, $clockWindow, $pinnedMoments, $derivedMomentColumns, $wallClockNormalization);
        $databaseWriteStatementShapes = new DatabaseWriteStatementShapes();
        foreach ($this->statements->getElements() as $databaseWriteStatement) {
            if ($databaseWriteStatement->isRandomCacheScopeInvalidationHousekeeping()) {
                continue;
            }
            $databaseWriteStatementShape = $databaseWriteStatement->buildComparableShape($databaseWriteStatementNormalization);
            $databaseWriteStatementShapes->add($databaseWriteStatementShape);
        }
        return $databaseWriteStatementShapes;
    }

    /**
     * The high-water marks to read at the start of a call: per id family, the highest value of its `table.column`
     * (0 for an empty table). Read on the connection the call writes on, before the call runs.
     *
     * @param Connection $connection
     * @param array<string, string> $idColumnByFamily Id family → `table.column` of its auto-increment id
     * @return array<string, int>
     * @throws DbalException
     */
    public static function findIdHighWaterMarks(Connection $connection, array $idColumnByFamily): array
    {
        $highWaterMarksByFamily = [];
        foreach ($idColumnByFamily as $idFamily => $qualifiedIdColumn) {
            [$table, $column] = explode('.', $qualifiedIdColumn, 2) + [1 => 'id'];
            $highWaterMarksByFamily[$idFamily] = (int)$connection->fetchOne(
                'SELECT COALESCE(MAX(' . $connection->quoteIdentifier($column) . '), 0) FROM ' . $connection->quoteIdentifier($table)
            );
        }
        return $highWaterMarksByFamily;
    }

    /**
     * The high-water mark of EVERY table of the connection's schema that has an auto-increment (MariaDB) or identity /
     * serial (PostgreSQL) column: the value its next row would get, minus one — one catalog read, for a run that cannot
     * know in advance which tables it will write.
     *
     * @param Connection $connection
     * @return array<string, int> Table → highest id at this moment
     * @throws DbalException
     */
    public static function findIdHighWaterMarksOfAllTables(Connection $connection): array
    {
        $highWaterMarksByTable = [];
        foreach (static::findNextIdsOfTables($connection, DialectResolver::forConnection($connection), false)->getElements() as $databaseTableNextId) {
            if ($databaseTableNextId->nextId !== null) {
                $highWaterMarksByTable[$databaseTableNextId->tableName] = max(0, $databaseTableNextId->nextId - 1);
            }
        }
        return $highWaterMarksByTable;
    }

    /**
     * The ids created since the marks were read, per table whose next id moved past its mark: its id values above the
     * mark, ascending, at most $limitPerTable. A shared database holds the rows of other runs too, so a table is read
     * through the FIRST of the given scope columns it has ({@see DatabaseRowScopeColumns::findFirstColumnAmong()}; a
     * column with {@see DatabaseRowScopeColumn::$alsoSelectsRowsById} also admits the rows whose own id is one of its
     * values). A table with none of those columns is read UNSCOPED and reported with how many ids were read and how many
     * were allocated since the mark; a table whose read hit the limit is reported as capped.
     *
     * @param Connection $connection
     * @param array<string, int> $highWaterMarksByTable From {@see findIdHighWaterMarksOfAllTables()}
     * @param DatabaseRowScopeColumns|null $rowScopeColumns The columns to read through, in priority order (null: none)
     * @param int $limitPerTable
     * @return DatabaseIdsAboveHighWaterMarks
     * @throws DbalException
     */
    public static function findIdsAboveHighWaterMarks(Connection $connection, array $highWaterMarksByTable, ?DatabaseRowScopeColumns $rowScopeColumns = null, int $limitPerTable = 5000): DatabaseIdsAboveHighWaterMarks
    {
        $sqlDialect = DialectResolver::forConnection($connection);
        $databaseIdsAboveHighWaterMarks = new DatabaseIdsAboveHighWaterMarks();
        $grownIdColumnsByTable = [];
        $allocatedIdCountsByTable = [];
        foreach (static::findNextIdsOfTables($connection, $sqlDialect, true)->getElements() as $databaseTableNextId) {
            $table = $databaseTableNextId->tableName;
            if (!isset($highWaterMarksByTable[$table]) || $databaseTableNextId->nextId === null || $databaseTableNextId->idColumnName === null) {
                continue;
            }
            $allocatedSinceMark = $databaseTableNextId->nextId - 1 - $highWaterMarksByTable[$table];
            if ($allocatedSinceMark > 0) {
                $grownIdColumnsByTable[$table] = $databaseTableNextId->idColumnName;
                $allocatedIdCountsByTable[$table] = $allocatedSinceMark;
            }
        }
        if ($grownIdColumnsByTable === []) {
            return $databaseIdsAboveHighWaterMarks;
        }
        $columnNamesByTable = [];
        foreach ($connection->fetchAllAssociative(
            static::buildColumnsOfTablesSql($sqlDialect),
            [array_keys($grownIdColumnsByTable)],
            [ArrayParameterType::STRING]
        ) as $columnRow) {
            $columnNamesByTable[(string)$columnRow['TABLE_NAME']][] = (string)$columnRow['COLUMN_NAME'];
        }
        $limit = max(1, $limitPerTable);
        foreach ($grownIdColumnsByTable as $table => $idColumnName) {
            $rowScopeColumn = $rowScopeColumns?->findFirstColumnAmong($columnNamesByTable[$table] ?? []);
            if ($rowScopeColumn !== null) {
                $databaseIdsAboveHighWaterMarks->scopeColumnByTable[$table] = $rowScopeColumn->columnName;
            }
            $mintedIds = array_map('intval', $connection->fetchFirstColumn(
                static::buildIdsAboveHighWaterMarkSql($sqlDialect, $table, $idColumnName, $rowScopeColumn, $limit),
                [$highWaterMarksByTable[$table]]
            ));
            $databaseIdsAboveHighWaterMarks->idsByTable[$table] = $mintedIds;
            if ($rowScopeColumn === null) {
                $databaseUnscopedTableRead = DatabaseUnscopedTableRead::forTable($table, count($mintedIds), $allocatedIdCountsByTable[$table]);
                $databaseIdsAboveHighWaterMarks->unscopedTableReads->add($databaseUnscopedTableRead);
            }
            if (count($mintedIds) >= $limit) {
                $databaseIdsAboveHighWaterMarks->cappedTables[] = $table;
            }
        }
        return $databaseIdsAboveHighWaterMarks;
    }

    /**
     * The next id of every table with an auto-increment column: the catalog read ({@see buildNextIdsOfTablesSql()}),
     * one element per table ({@see DatabaseTableNextIds::fromCatalogRows()}); a table whose next id the catalog does not
     * know (a PostgreSQL sequence never called, or set with `setval(…, false)`) gets it from its highest id
     * ({@see buildHighestIdOfTableSql()}, one statement per such table).
     *
     * @param Connection $connection
     * @param SqlDialect $sqlDialect
     * @param bool $withIdColumnName
     * @return DatabaseTableNextIds
     * @throws DbalException
     */
    protected static function findNextIdsOfTables(Connection $connection, SqlDialect $sqlDialect, bool $withIdColumnName): DatabaseTableNextIds
    {
        $databaseTableNextIds = DatabaseTableNextIds::fromCatalogRows($connection->fetchAllAssociative(static::buildNextIdsOfTablesSql($sqlDialect, $withIdColumnName)));
        foreach ($databaseTableNextIds->getElements() as $databaseTableNextId) {
            if ($databaseTableNextId->nextId === null && $databaseTableNextId->idColumnName !== null) {
                $databaseTableNextId->nextId = (int)$connection->fetchOne(
                    static::buildHighestIdOfTableSql($sqlDialect, $databaseTableNextId->tableName, $databaseTableNextId->idColumnName)
                ) + 1;
            }
        }
        return $databaseTableNextIds;
    }

    /**
     * The read of a table's highest id (0 for an empty table).
     *
     * @param SqlDialect $sqlDialect
     * @param string $table
     * @param string $idColumnName
     * @return string
     */
    public static function buildHighestIdOfTableSql(SqlDialect $sqlDialect, string $table, string $idColumnName): string
    {
        return 'SELECT COALESCE(MAX(' . $sqlDialect->quote($idColumnName) . '), 0) FROM ' . $sqlDialect->quote($table);
    }

    /**
     * The catalog read of the next id of every table with an auto-increment column, as rows `TABLE_NAME`,
     * `AUTO_INCREMENT` (the next id; the highest allocated id plus one) and, with $withIdColumnName, `COLUMN_NAME` (the
     * id column). MariaDB: `information_schema.TABLES.AUTO_INCREMENT`. PostgreSQL: the identity and serial columns of
     * the current schema in column order, with the last value of their sequence plus one (`pg_sequences.last_value`;
     * NULL for a sequence never called — {@see findNextIdsOfTables()} reads the table's highest id then); it always
     * names the column.
     *
     * @param SqlDialect $sqlDialect
     * @param bool $withIdColumnName
     * @return string
     */
    public static function buildNextIdsOfTablesSql(SqlDialect $sqlDialect, bool $withIdColumnName): string
    {
        if ($sqlDialect->name() === SqlDialect::POSTGRES) {
            return 'SELECT c.table_name AS "TABLE_NAME", c.column_name AS "COLUMN_NAME", s.last_value + 1 AS "AUTO_INCREMENT"'
                . ' FROM information_schema.columns c'
                . ' LEFT JOIN pg_sequences s ON quote_ident(s.schemaname) || \'.\' || quote_ident(s.sequencename)'
                . ' = pg_get_serial_sequence(quote_ident(c.table_schema) || \'.\' || quote_ident(c.table_name), c.column_name)'
                . ' WHERE c.table_schema = current_schema() AND (c.is_identity = \'YES\' OR c.column_default LIKE \'nextval(%\')'
                . ' ORDER BY c.table_name, c.ordinal_position';
        }
        if (!$withIdColumnName) {
            return 'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL';
        }
        return 'SELECT t.TABLE_NAME, c.COLUMN_NAME, t.AUTO_INCREMENT FROM information_schema.TABLES t'
            . ' JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.EXTRA LIKE \'%auto_increment%\''
            . ' WHERE t.TABLE_SCHEMA = DATABASE() AND t.AUTO_INCREMENT IS NOT NULL';
    }

    /**
     * The catalog read of the column names of a list of tables (one `?` bound to the list), as rows `TABLE_NAME`,
     * `COLUMN_NAME`.
     *
     * @param SqlDialect $sqlDialect
     * @return string
     */
    public static function buildColumnsOfTablesSql(SqlDialect $sqlDialect): string
    {
        if ($sqlDialect->name() === SqlDialect::POSTGRES) {
            return 'SELECT table_name AS "TABLE_NAME", column_name AS "COLUMN_NAME" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (?)';
        }
        return 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?)';
    }

    /**
     * The read of a table's ids above its mark (one `?` bound to the mark), through a scope column or unscoped.
     *
     * @param SqlDialect $sqlDialect
     * @param string $table
     * @param string $idColumnName
     * @param DatabaseRowScopeColumn|null $rowScopeColumn Null: unscoped
     * @param int $limit
     * @return string
     */
    public static function buildIdsAboveHighWaterMarkSql(SqlDialect $sqlDialect, string $table, string $idColumnName, ?DatabaseRowScopeColumn $rowScopeColumn, int $limit): string
    {
        $quotedIdColumn = $sqlDialect->quote($idColumnName);
        $scopeConditionSql = $rowScopeColumn?->buildConditionSql($sqlDialect, $quotedIdColumn) ?? '';
        return 'SELECT ' . $quotedIdColumn . ' FROM ' . $sqlDialect->quote($table) . ' WHERE ' . $quotedIdColumn . ' > ?' . $scopeConditionSql
            . ' ORDER BY ' . $quotedIdColumn . ' LIMIT ' . $limit;
    }
}
