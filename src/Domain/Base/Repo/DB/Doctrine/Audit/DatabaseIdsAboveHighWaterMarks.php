<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one read of the ids created since the high-water marks found
 * ({@see DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks()}): the ids per grown table, the scope column each
 * table was read through, the tables read without a scope and the tables whose read hit the limit.
 */
class DatabaseIdsAboveHighWaterMarks extends ValueObject
{
    /** @var array<string, int[]> Table → the ids above its mark, ascending */
    public array $idsByTable = [];

    /** @var array<string, string> Table → the scope column it was read through */
    public array $scopeColumnByTable = [];

    /** @var DatabaseUnscopedTableReads The tables read without a scope */
    public DatabaseUnscopedTableReads $unscopedTableReads;

    /** @var string[] The tables whose read hit the limit */
    public array $cappedTables = [];

    public function __construct()
    {
        $this->unscopedTableReads = new DatabaseUnscopedTableReads();
        parent::__construct();
    }

    /**
     * The stored document of the read, the keys in this order: `idsByTable`, `scopeColumnByTable`, `unscopedTables`
     * (table → `{read, allocated}`), `cappedTables`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'idsByTable' => $this->idsByTable,
            'scopeColumnByTable' => $this->scopeColumnByTable,
            'unscopedTables' => $this->unscopedTableReads->toArray(),
            'cappedTables' => $this->cappedTables,
        ];
    }
}
