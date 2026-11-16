<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine\Audit\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySqlDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSqlDriver;

/**
 * A connection that never connects: it answers the audit's catalog and id reads from scripted rows and records every
 * statement it was asked, so the control flow of the id reads is testable without a database.
 */
class ScriptedCatalogConnection extends Connection
{
    /** @var array<int, array<string, mixed>> The rows of the catalog read of the next ids */
    public array $nextIdRows = [];

    /** @var array<int, array<string, mixed>> The rows of the catalog read of the columns of the grown tables */
    public array $columnRows = [];

    /** @var array<string, int[]> Table → the ids its read of created ids answers */
    public array $createdIdsByTable = [];

    /** @var array<string, int> Table → the highest id its MAX read answers */
    public array $highestIdsByTable = [];

    /** @var string[] Every statement asked, in order */
    public array $askedSql = [];

    public static function forDriver(string $driverName): self
    {
        $driver = $driverName === 'pdo_pgsql' ? new PgSqlDriver() : new MySqlDriver();
        return new self(['driver' => $driverName], $driver);
    }

    public function __construct(array $params, Driver $driver)
    {
        parent::__construct($params, $driver);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->askedSql[] = $query;
        return str_contains($query, 'AUTO_INCREMENT') ? $this->nextIdRows : $this->columnRows;
    }

    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        $this->askedSql[] = $query;
        foreach ($this->createdIdsByTable as $table => $createdIds) {
            if (str_contains($query, ' FROM `' . $table . '` ') || str_contains($query, ' FROM "' . $table . '" ')) {
                return $createdIds;
            }
        }
        return [];
    }

    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        $this->askedSql[] = $query;
        foreach ($this->highestIdsByTable as $table => $highestId) {
            if (str_ends_with($query, ' FROM `' . $table . '`') || str_ends_with($query, ' FROM "' . $table . '"')) {
                return $highestId;
            }
        }
        return 0;
    }
}
