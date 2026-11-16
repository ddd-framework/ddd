<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use Doctrine\DBAL\Logging\SQLLogger;

/**
 * The SQL logger the statement audit installs on one scope's connection: hands every statement to the audit together
 * with the database of that connection, so the audit reads each statement in its own database's syntax.
 *
 * DBAL 3 marks `SQLLogger` deprecated and DBAL 4 removes it; see {@see DatabaseWriteStatementAudit}.
 */
class DatabaseWriteStatementAuditLogger implements SQLLogger
{
    /**
     * @param DatabaseWriteStatementAudit $databaseWriteStatementAudit The audit that keeps the statements
     * @param SqlDialect $sqlDialect The database of the connection this logger is installed on
     */
    public function __construct(
        protected DatabaseWriteStatementAudit $databaseWriteStatementAudit,
        protected SqlDialect $sqlDialect
    ) {
    }

    /**
     * @param string $sql
     * @param array<int|string, mixed>|null $params
     * @param array<int|string, mixed>|null $types
     * @return void
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        $this->databaseWriteStatementAudit->keepIssuedStatement((string)$sql, $params ?? [], $this->sqlDialect);
    }

    public function stopQuery(): void
    {
    }
}
