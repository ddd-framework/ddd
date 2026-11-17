<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\ApplyDiffRequestDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\ApplyDiffsRequestDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\DBTableDiffsGetRequestDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\DBTableDiffsGetResponseDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\DoctrineModelForEntityGetRequestDto;
use DDD\Presentation\Base\Dtos\HtmlResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * The database schema endpoints of an admin API: the live-vs-entity schema diff, its apply actions, the SQL of every
 * table and the generated Doctrine model code. An application mounts them with one controller of its own, which sets
 * the path prefix, the tag and the access:
 *
 *     #[Route('/common/databaseModels')]
 *     #[Tag(group: 'Common', name: 'DatabaseModels', description: 'Database schema diff and generated models')]
 *     class AdminDatabaseModelsController extends AdminController
 *     {
 *         use DatabaseModelsControllerTrait;
 *     }
 *
 * The guards of {@see DatabaseSchemaDiffService} stay in force: the diff-signature gate, the apply lock and the
 * large-table production guard. The guard's bypass is not reachable over HTTP; a large-table change runs from a
 * console command or a message handler. A process that shares another stage's database refuses every schema write
 * ({@see self::areSchemaWritesDisabled()}).
 */
trait DatabaseModelsControllerTrait
{
    /**
     * The pending differences between the entity-derived schema and the live database, one per table, each with its
     * phase-ordered SQL, severity, size, production-guard signal and `diffSignature`.
     *
     * @throws Exception
     * @throws ReflectionException
     */
    #[Get('/diff')]
    #[Summary('DatabaseModels Diff')]
    public function getDatabaseSchemaDiff(
        DBTableDiffsGetRequestDto $requestDto,
        DatabaseSchemaDiffService $databaseSchemaDiffService
    ): DBTableDiffsGetResponseDto {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $responseDto = new DBTableDiffsGetResponseDto();
        $responseDto->diffs = $databaseSchemaDiffService->computeDiffs();
        return $responseDto;
    }

    /**
     * Applies the pending diff of every table, or of the listed tables, and returns the diff computed afterwards.
     * The listed tables must all have a pending diff (else `[DIFF_SIGNATURE_MISMATCH]`: applied meanwhile, refresh);
     * the expected signatures are narrowed to them.
     *
     * @throws BadRequestException When the table list is empty or not a list of names, a listed table has no pending diff, a signature changed, the apply lock is busy or the production guard refuses
     * @throws ForbiddenException When schema writes are disabled on this process
     * @throws Exception
     * @throws ReflectionException
     */
    #[Post('/applyDiffs')]
    #[Summary('DatabaseModels Apply All Diffs')]
    public function applyDatabaseSchemaDiffs(
        ApplyDiffsRequestDto $requestDto,
        DatabaseSchemaDiffService $databaseSchemaDiffService
    ): DBTableDiffsGetResponseDto {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $this->throwForbiddenWhenSchemaWritesAreDisabled();
        $requestedTableNames = $requestDto->sqlTableNames;
        if ($requestedTableNames !== null) {
            if (!array_is_list($requestedTableNames)) {
                throw new BadRequestException('`sqlTableNames` must be a list of table names, not an object.');
            }
            if ($requestedTableNames === []) {
                throw new BadRequestException('`sqlTableNames` must be null (apply every diff) or name at least one table.');
            }
            foreach ($requestedTableNames as $requestedTableName) {
                if (!is_string($requestedTableName) || trim($requestedTableName) === '') {
                    throw new BadRequestException('Every entry of `sqlTableNames` must be a non-empty table name.');
                }
            }
        }

        $diffs = $databaseSchemaDiffService->computeDiffs();
        $expectedDiffSignatures = $requestDto->expectedDiffSignatures;
        if ($requestedTableNames !== null) {
            $requestedDiffs = new DBTableDiffs();
            $tableNamesWithoutPendingDiff = [];
            foreach ($requestedTableNames as $requestedTableName) {
                $diff = $diffs->getDiffByTableName($requestedTableName);
                if ($diff === null || $diff->changeType === DBTableDiff::CHANGE_TYPE_NO_CHANGE) {
                    $tableNamesWithoutPendingDiff[] = '`' . $requestedTableName . '`';
                    continue;
                }
                $requestedDiffs->add($diff);
            }
            if ($tableNamesWithoutPendingDiff !== []) {
                // loud on drift: applying only the still pending part of a stale selection would report success
                throw new BadRequestException(
                    DatabaseSchemaDiffService::ERROR_CODE_DIFF_SIGNATURE_MISMATCH
                    . ' These tables have no pending diff any more (applied meanwhile, or the entities match the live '
                    . 'database again): ' . implode(', ', $tableNamesWithoutPendingDiff) . '. Refresh the diff and submit again.'
                );
            }
            $diffs = $requestedDiffs;
            // the service requires the signatures to cover exactly the applied diffs
            $expectedDiffSignatures = $expectedDiffSignatures?->getByTableNames($requestedTableNames);
        }

        $responseDto = new DBTableDiffsGetResponseDto();
        $responseDto->diffs = $databaseSchemaDiffService->applyDiffs(
            $diffs,
            $requestDto->disableForeignKeyChecks,
            false, // the production guard's bypass is never reachable over HTTP
            $expectedDiffSignatures
        );
        return $responseDto;
    }

    /**
     * Applies the pending diff of one table and returns the diff computed afterwards. A table without a pending diff
     * is `[DIFF_SIGNATURE_MISMATCH]` when the caller sent the signature it saw (applied meanwhile), else a 404.
     *
     * @throws BadRequestException When the table has no pending diff any more, its signature changed, the apply lock is busy or the production guard refuses
     * @throws NotFoundException When the table has no pending diff and no signature was sent
     * @throws ForbiddenException When schema writes are disabled on this process
     * @throws Exception
     * @throws ReflectionException
     */
    #[Post('/applyDiff')]
    #[Summary('DatabaseModels Apply Single Diff')]
    public function applyDatabaseSchemaDiff(
        ApplyDiffRequestDto $requestDto,
        DatabaseSchemaDiffService $databaseSchemaDiffService
    ): DBTableDiffsGetResponseDto {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $this->throwForbiddenWhenSchemaWritesAreDisabled();
        $diff = $databaseSchemaDiffService->computeDiffs()->getDiffByTableName($requestDto->sqlTableName);
        if ($diff === null || $diff->changeType === DBTableDiff::CHANGE_TYPE_NO_CHANGE) {
            if ($requestDto->expectedDiffSignature !== null) {
                throw new BadRequestException(
                    DatabaseSchemaDiffService::ERROR_CODE_DIFF_SIGNATURE_MISMATCH
                    . " The diff of table `{$requestDto->sqlTableName}` is no longer pending (applied meanwhile, or the "
                    . 'entity matches the live database again). Refresh the diff.'
                );
            }
            throw new NotFoundException("No pending diff for table `{$requestDto->sqlTableName}`");
        }

        $responseDto = new DBTableDiffsGetResponseDto();
        $responseDto->diffs = $databaseSchemaDiffService->applyDiff(
            $diff,
            $requestDto->disableForeignKeyChecks,
            false, // the production guard's bypass is never reachable over HTTP
            $requestDto->expectedDiffSignature
        );
        return $responseDto;
    }

    /**
     * The SQL that creates every table of the application's schema (text/plain), without the tables the application
     * excludes. Returned only, never executed.
     *
     * @throws ForbiddenException When schema writes are disabled on this process
     * @throws Exception
     * @throws ReflectionException
     */
    #[Get('/generateDatabaseTables')]
    #[Summary('DatabaseModels SQL Tables Generation')]
    public function generateDatabaseTablesSql(EntityModelGeneratorService $entityModelGeneratorService): HtmlResponseDto
    {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $this->throwForbiddenWhenSchemaWritesAreDisabled();
        // null = the whole schema: the tables the application excludes stay out
        $sql = $entityModelGeneratorService->createOrUpdateDatabaseTablesForEntities();
        return new HtmlResponseDto($sql, 200, [], 'text/plain');
    }

    /**
     * The Doctrine model code generated for every database entity (text/plain), as
     * `app:generate-doctrine-models-for-entities` would write it. Nothing is written.
     *
     * @throws ReflectionException
     */
    #[Get('/generateDoctrineModels')]
    #[Summary('DatabaseModels Doctrine Models Generation')]
    public function generateDoctrineModelsCode(EntityModelGeneratorService $entityModelGeneratorService): HtmlResponseDto
    {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $doctrineModelsCode = '';
        foreach ($entityModelGeneratorService::getAllEntityClasses(LazyLoadRepo::DB) as $entityClassWithNamespace) {
            $doctrineModelsCode .= $entityModelGeneratorService->generateDoctrineModelForEntityClass(
                $entityClassWithNamespace->getNameWithNamespace()
            );
        }
        return new HtmlResponseDto($doctrineModelsCode, 200, [], 'text/plain');
    }

    /**
     * The Doctrine model code generated for one database entity (text/plain), by short or fully qualified class name.
     * Nothing is written: the models are written by `app:generate-doctrine-models-for-entities`.
     *
     * @throws BadRequestException When the name is empty or names several entities
     * @throws NotFoundException When no database entity has that name
     * @throws ReflectionException
     */
    #[Get('/generateForEntity')]
    #[Summary('DatabaseModels Doctrine Model For Entity')]
    public function generateDoctrineModelCodeForEntity(
        DoctrineModelForEntityGetRequestDto $requestDto,
        EntityModelGeneratorService $entityModelGeneratorService
    ): HtmlResponseDto {
        $this->throwForbiddenUnlessDatabaseModelsAccessAllowed();
        $entityClassName = $this->findDatabaseEntityClassNameByName($requestDto->entityName, $entityModelGeneratorService);
        return new HtmlResponseDto(
            $entityModelGeneratorService->generateDoctrineModelForEntityClass($entityClassName),
            200,
            [],
            'text/plain'
        );
    }

    /**
     * The fully qualified class name of the database entity a short or fully qualified name stands for.
     *
     * @throws BadRequestException When the name is empty or names several entities
     * @throws NotFoundException When no database entity has that name
     * @throws ReflectionException
     */
    protected function findDatabaseEntityClassNameByName(
        string $entityName,
        EntityModelGeneratorService $entityModelGeneratorService
    ): string {
        $entityName = ltrim(trim($entityName), '\\');
        if ($entityName === '') {
            throw new BadRequestException('`entityName` must not be empty.');
        }
        $matchingEntityClassNames = [];
        foreach ($entityModelGeneratorService::getAllEntityClasses(LazyLoadRepo::DB) as $entityClassWithNamespace) {
            $entityClassName = $entityClassWithNamespace->getNameWithNamespace();
            if ($entityClassName === $entityName || $entityClassWithNamespace->name === $entityName) {
                $matchingEntityClassNames[] = $entityClassName;
            }
        }
        if ($matchingEntityClassNames === []) {
            throw new NotFoundException("No database entity `$entityName` (an entity with a DB repository).");
        }
        if (count($matchingEntityClassNames) > 1) {
            throw new BadRequestException(
                "The entity name `$entityName` is ambiguous; pass the fully qualified class name: "
                . implode(', ', $matchingEntityClassNames)
            );
        }
        return $matchingEntityClassNames[0];
    }

    /**
     * Whether this process must not write the schema: it shares another stage's database (a testing stack on the
     * production database, a development workspace pointed at production). Set `APP_SCHEMA_WRITES_DISABLED=1` in the
     * process environment or `.env`; an application with its own signal overrides this method.
     */
    protected function areSchemaWritesDisabled(): bool
    {
        $schemaWritesDisabled = getenv('APP_SCHEMA_WRITES_DISABLED');
        if ($schemaWritesDisabled === false || $schemaWritesDisabled === '') {
            $schemaWritesDisabled = Config::getEnv('APP_SCHEMA_WRITES_DISABLED');
        }
        return in_array(strtolower(trim((string)$schemaWritesDisabled)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @throws ForbiddenException When schema writes are disabled on this process
     */
    protected function throwForbiddenWhenSchemaWritesAreDisabled(): void
    {
        if ($this->areSchemaWritesDisabled()) {
            throw new ForbiddenException(
                'Schema writes are disabled on this process: it shares another stage\'s database. '
                . 'Apply schema changes through the process that owns the database.'
            );
        }
    }

    /**
     * The access check of every database models endpoint, called first by each action (before validation and any service call).
     * Empty by default: access is what the firewall and `access_control` grant. An application whose rule is not a
     * role (an account predicate) overrides it and throws {@see ForbiddenException} (403).
     *
     * @throws ForbiddenException When the current request must not reach these endpoints
     */
    protected function throwForbiddenUnlessDatabaseModelsAccessAllowed(): void
    {
    }
}
