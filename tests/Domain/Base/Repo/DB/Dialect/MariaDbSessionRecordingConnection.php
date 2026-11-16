<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use Doctrine\DBAL\Connection;

/**
 * A DBAL connection without a database for the dialect tests: answers the read of the session's two limits from
 * {@see $sessionValues}, records every statement instead of sending it, and reports the transaction state a test sets.
 * Built without its constructor.
 */
class MariaDbSessionRecordingConnection extends Connection
{
    /** @var string[]|false What `SELECT @@SESSION.innodb_lock_wait_timeout, @@SESSION.max_statement_time` answers */
    public array|false $sessionValues = ['50', '300.000000'];

    /** @var string[] Every statement handed to {@see executeStatement()}, in call order */
    public array $executedStatements = [];

    /** @var string[] Every query handed to {@see fetchNumeric()}, in call order */
    public array $fetchedQueries = [];

    public bool $transactionActive = true;

    public function isTransactionActive()
    {
        return $this->transactionActive;
    }

    public function fetchNumeric(string $query, array $params = [], array $types = [])
    {
        $this->fetchedQueries[] = $query;
        return $this->sessionValues;
    }

    public function executeStatement($sql, array $params = [], array $types = [])
    {
        $this->executedStatements[] = $sql;
        return 0;
    }
}
