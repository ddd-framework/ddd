<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit\Fixtures;

use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseScopeConfiguration;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseScopeConfigurations;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementShapes;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * One audited run on an in-memory SQLite database with one table, read with MariaDB's syntax (its SQL here is plain):
 * `$paddingRowCount` rows exist before the call (another database starts at another id), the high-water mark is read,
 * the audit starts; the test then issues the call's statements on {@see $connection} and calls {@see finish()}.
 */
class AuditedSqliteRun
{
    public Configuration $configuration;

    public Connection $connection;

    public DatabaseWriteStatementAudit $databaseWriteStatementAudit;

    /** @var array<string, int> */
    public array $highWaterMarksByFamily = [];

    public function __construct()
    {
        $this->configuration = new Configuration();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->configuration);
        $this->connection->executeStatement('CREATE TABLE Notes (id INTEGER PRIMARY KEY AUTOINCREMENT, ownerId INT, text TEXT, created TEXT, remindAt TEXT)');
        $this->databaseWriteStatementAudit = new DatabaseWriteStatementAudit();
    }

    public function buildScopeConfigurations(?Configuration $configuration = null): DatabaseScopeConfigurations
    {
        $databaseScopeConfigurations = new DatabaseScopeConfigurations();
        $databaseScopeConfiguration = DatabaseScopeConfiguration::forScope('DEFAULT', $configuration ?? $this->configuration, new MariaDbDialect());
        $databaseScopeConfigurations->add($databaseScopeConfiguration);
        return $databaseScopeConfigurations;
    }

    public function start(int $paddingRowCount): void
    {
        for ($paddingRowIndex = 0; $paddingRowIndex < $paddingRowCount; $paddingRowIndex++) {
            $this->connection->executeStatement('INSERT INTO Notes (ownerId, text) VALUES (0, \'fixture\')');
        }
        $this->highWaterMarksByFamily = DatabaseWriteStatementAudit::findIdHighWaterMarks($this->connection, ['note' => 'Notes.id']);
        $this->databaseWriteStatementAudit->startOnScopeConfigurations($this->buildScopeConfigurations());
    }

    public function finish(): DatabaseWriteStatementShapes
    {
        $this->databaseWriteStatementAudit->stop($this->buildScopeConfigurations());
        return $this->databaseWriteStatementAudit->buildComparableStatements(['Notes.id' => 'note'], $this->highWaterMarksByFamily, [], ['Notes.remindAt']);
    }

    /** One call's writes: two inserts, a read, an update by id, a delete. */
    public function runCallWrites(string $updatedText): void
    {
        $this->connection->executeStatement('INSERT INTO Notes (ownerId, text, created) VALUES (?, ?, ?)', [7, 'first', date('Y-m-d H:i:s')]);
        $firstNoteId = (int)$this->connection->lastInsertId();
        $this->connection->executeStatement('INSERT INTO Notes (ownerId, text, created) VALUES (?, ?, ?)', [7, 'second', '2020-01-01 00:00:00']);
        $this->connection->executeQuery('SELECT * FROM Notes WHERE ownerId = ?', [7])->fetchAllAssociative();
        $this->connection->executeStatement('UPDATE Notes SET text = :text WHERE id = :id', ['text' => $updatedText, 'id' => $firstNoteId]);
        $this->connection->executeStatement('DELETE FROM Notes WHERE id IN (?)', [[$firstNoteId + 1]], [ArrayParameterType::INTEGER]);
    }
}
