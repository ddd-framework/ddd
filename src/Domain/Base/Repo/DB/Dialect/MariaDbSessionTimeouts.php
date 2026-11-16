<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two limits a MariaDB session holds for its statements, as whole seconds: how long a statement waits for a row
 * lock (`innodb_lock_wait_timeout`) and how long one statement may run (`max_statement_time`, 0 = no limit).
 * {@see MariaDbDialect} remembers them before it changes the limits of a transaction and puts them back afterwards.
 *
 * @phpstan-consistent-constructor
 */
class MariaDbSessionTimeouts extends ValueObject
{
    /**
     * @param int $lockWaitTimeoutSeconds Seconds a statement waits for a row lock
     * @param int $statementTimeoutSeconds Seconds one statement may run; 0 = no limit
     */
    public function __construct(
        public readonly int $lockWaitTimeoutSeconds,
        public readonly int $statementTimeoutSeconds,
    ) {
        parent::__construct();
    }

    /**
     * From the values the server returns for `@@SESSION.innodb_lock_wait_timeout` and `@@SESSION.max_statement_time`.
     * The statement limit is a decimal number on MariaDB (`300.000000`); a fraction is rounded up to the next whole
     * second (0 stays "no limit", a fraction never becomes 0), because a connection pooler that tracks session
     * variables (e.g. ProxySQL) follows whole numbers only.
     */
    public static function fromSessionValues(int|float|string $lockWaitTimeout, int|float|string $statementTimeout): static
    {
        return new static(
            max(0, (int)ceil((float)$lockWaitTimeout)),
            max(0, (int)ceil((float)$statementTimeout))
        );
    }

    /** The one statement that puts both limits back on the session, whole numbers only. */
    public function buildSetSessionSql(): string
    {
        return 'SET SESSION innodb_lock_wait_timeout = ' . $this->lockWaitTimeoutSeconds
            . ', max_statement_time = ' . $this->statementTimeoutSeconds;
    }

    /** Never collected in a set; every instance is its own element. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
