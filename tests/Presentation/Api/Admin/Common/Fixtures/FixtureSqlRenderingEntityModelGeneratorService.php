<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Domain\Common\Services\EntityModelGeneratorService;

/**
 * Renders a fixed SQL text instead of the entity schema and records that it was asked.
 */
class FixtureSqlRenderingEntityModelGeneratorService extends EntityModelGeneratorService
{
    public const string RENDERED_SQL = "CREATE TABLE `Accounts` (`id` INT NOT NULL);\n";

    public bool $tablesSqlRendered = false;

    public function createOrUpdateDatabaseTablesForEntities(?array $entityClasses = null): string
    {
        $this->tablesSqlRendered = true;
        return self::RENDERED_SQL;
    }
}
