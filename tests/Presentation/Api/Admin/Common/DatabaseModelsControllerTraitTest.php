<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignature;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignatures;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\ApplyDiffRequestDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\ApplyDiffsRequestDto;
use DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos\DBTableDiffsGetRequestDto;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingDatabaseModelsController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureDatabaseModelsController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureRecordingDatabaseSchemaDiffService;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureSqlRenderingEntityModelGeneratorService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class DatabaseModelsControllerTraitTest extends AdminControllerTraitTestCase
{
    protected FixtureDatabaseModelsController $controller;

    protected FixtureRecordingDatabaseSchemaDiffService $databaseSchemaDiffService;

    protected function getFixtureControllerClass(): string
    {
        return FixtureDatabaseModelsController::class;
    }

    protected function getForbiddingFixtureControllerClass(): string
    {
        return FixtureForbiddingDatabaseModelsController::class;
    }

    protected function getAccessHookMethodName(): string
    {
        return 'throwForbiddenUnlessDatabaseModelsAccessAllowed';
    }

    protected function setUp(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $this->controller = new FixtureDatabaseModelsController($requestStack);
        $this->controller->schemaWritesDisabledOverride = false;
        $this->databaseSchemaDiffService = new FixtureRecordingDatabaseSchemaDiffService();
        $this->databaseSchemaDiffService->addPendingDiff('Accounts');
        $this->databaseSchemaDiffService->addPendingDiff('Orders');
        $this->databaseSchemaDiffService->addPendingDiff('Settled', DBTableDiff::CHANGE_TYPE_NO_CHANGE);
    }

    protected function tearDown(): void
    {
        putenv('APP_SCHEMA_WRITES_DISABLED');
    }

    protected function buildExpectedDiffSignatures(string ...$sqlTableNames): DBExpectedDiffSignatures
    {
        $expectedDiffSignatures = new DBExpectedDiffSignatures();
        foreach ($sqlTableNames as $sqlTableName) {
            $expectedDiffSignature = new DBExpectedDiffSignature();
            $expectedDiffSignature->sqlTableName = $sqlTableName;
            $expectedDiffSignature->signature = 'signature-' . $sqlTableName;
            $expectedDiffSignatures->add($expectedDiffSignature);
        }
        return $expectedDiffSignatures;
    }

    public function testTheTraitMountsItsRoutesUnderTheControllerPrefix(): void
    {
        $this->assertSame([
            'GET /common/databaseModels/diff',
            'GET /common/databaseModels/generateDatabaseTables',
            'GET /common/databaseModels/generateDoctrineModels',
            'GET /common/databaseModels/generateForEntity',
            'POST /common/databaseModels/applyDiff',
            'POST /common/databaseModels/applyDiffs',
        ], $this->getMethodAndPathOfEveryRoute());
    }

    public function testTheOpenApiDocumentDocumentsEveryRoute(): void
    {
        $document = $this->buildOpenApiDocument();
        $this->assertCount(6, $document->paths);
        $schemaNames = $this->getDocumentedSchemaNames($document);
        $this->assertContains('DDD.Presentation.Api.Admin.Common.DatabaseModels.Dtos.DBTableDiffsGetResponseDto', $schemaNames);
        $this->assertContains('DDD.Presentation.Api.Admin.Common.DatabaseModels.Dtos.ApplyDiffsRequestDto', $schemaNames);
    }

    public function testNoRequestCanBypassTheProductionGuard(): void
    {
        foreach ([ApplyDiffRequestDto::class, ApplyDiffsRequestDto::class] as $requestDtoClass) {
            $this->assertFalse(property_exists($requestDtoClass, 'bypassProductionGuard'), $requestDtoClass);
        }
    }

    public function testTheDiffReturnsThePendingDiffs(): void
    {
        $responseDto = $this->controller->getDatabaseSchemaDiff(new DBTableDiffsGetRequestDto(), $this->databaseSchemaDiffService);
        $this->assertSame(3, $responseDto->diffs->count());
    }

    public function testApplyDiffsAppliesTheListedTablesWithTheirSignaturesAndNeverBypassesTheGuard(): void
    {
        $requestDto = new ApplyDiffsRequestDto();
        $requestDto->sqlTableNames = ['Orders'];
        $requestDto->expectedDiffSignatures = $this->buildExpectedDiffSignatures('Accounts', 'Orders');

        $this->controller->applyDatabaseSchemaDiffs($requestDto, $this->databaseSchemaDiffService);

        $this->assertSame(['Orders'], array_map(
            static fn (DBTableDiff $tableDiff): string => $tableDiff->sqlTableName,
            $this->databaseSchemaDiffService->appliedDiffs->getElements()
        ));
        $this->assertSame(['Orders'], $this->databaseSchemaDiffService->appliedExpectedDiffSignatures->getCoveredTableNames());
        $this->assertFalse($this->databaseSchemaDiffService->appliedWithProductionGuardBypass);
    }

    public function testApplyDiffsWithoutTableNamesAppliesEveryDiff(): void
    {
        $requestDto = new ApplyDiffsRequestDto();
        $requestDto->expectedDiffSignatures = $this->buildExpectedDiffSignatures('Accounts', 'Orders');

        $this->controller->applyDatabaseSchemaDiffs($requestDto, $this->databaseSchemaDiffService);

        $this->assertSame(3, $this->databaseSchemaDiffService->appliedDiffs->count());
        $this->assertSame(['Accounts', 'Orders'], $this->databaseSchemaDiffService->appliedExpectedDiffSignatures->getCoveredTableNames());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideInvalidTableNameLists(): array
    {
        return [
            'empty list' => [[]],
            'object instead of list' => [['first' => 'Orders']],
            'empty name' => [['Orders', '']],
            'not a name' => [['Orders', 42]],
        ];
    }

    /**
     * @dataProvider provideInvalidTableNameLists
     */
    public function testApplyDiffsRefusesAnInvalidTableNameList(array $sqlTableNames): void
    {
        $requestDto = new ApplyDiffsRequestDto();
        $requestDto->sqlTableNames = $sqlTableNames;

        $this->expectException(BadRequestException::class);
        try {
            $this->controller->applyDatabaseSchemaDiffs($requestDto, $this->databaseSchemaDiffService);
        } finally {
            $this->assertNull($this->databaseSchemaDiffService->appliedDiffs);
        }
    }

    public function testApplyDiffsRefusesATableWithoutPendingDiffAsDrift(): void
    {
        $requestDto = new ApplyDiffsRequestDto();
        $requestDto->sqlTableNames = ['Orders', 'Settled', 'Unknown'];

        try {
            $this->controller->applyDatabaseSchemaDiffs($requestDto, $this->databaseSchemaDiffService);
            $this->fail('A table without a pending diff must be refused');
        } catch (BadRequestException $badRequestException) {
            $this->assertStringStartsWith(DatabaseSchemaDiffService::ERROR_CODE_DIFF_SIGNATURE_MISMATCH, $badRequestException->getMessage());
            $this->assertStringContainsString('`Settled`, `Unknown`', $badRequestException->getMessage());
        }
        $this->assertNull($this->databaseSchemaDiffService->appliedDiffs);
    }

    public function testApplyDiffAppliesOneTableWithItsSignature(): void
    {
        $requestDto = new ApplyDiffRequestDto();
        $requestDto->sqlTableName = 'Accounts';
        $requestDto->expectedDiffSignature = 'signature-Accounts';

        $this->controller->applyDatabaseSchemaDiff($requestDto, $this->databaseSchemaDiffService);

        $this->assertSame('Accounts', $this->databaseSchemaDiffService->appliedDiffs->first()->sqlTableName);
        $this->assertSame('signature-Accounts', $this->databaseSchemaDiffService->appliedExpectedDiffSignature);
        $this->assertFalse($this->databaseSchemaDiffService->appliedWithProductionGuardBypass);
    }

    public function testApplyDiffOfASettledTableIsDriftWithASignatureAndNotFoundWithout(): void
    {
        $requestDto = new ApplyDiffRequestDto();
        $requestDto->sqlTableName = 'Settled';
        $requestDto->expectedDiffSignature = 'signature-Settled';
        try {
            $this->controller->applyDatabaseSchemaDiff($requestDto, $this->databaseSchemaDiffService);
            $this->fail('A settled table with a signature must be drift');
        } catch (BadRequestException $badRequestException) {
            $this->assertStringStartsWith(DatabaseSchemaDiffService::ERROR_CODE_DIFF_SIGNATURE_MISMATCH, $badRequestException->getMessage());
        }

        $requestDto->expectedDiffSignature = null;
        $this->expectException(NotFoundException::class);
        $this->controller->applyDatabaseSchemaDiff($requestDto, $this->databaseSchemaDiffService);
    }

    public function testSchemaWritesAreRefusedWhenDisabled(): void
    {
        $this->controller->schemaWritesDisabledOverride = true;
        $applyDiffRequestDto = new ApplyDiffRequestDto();
        $applyDiffRequestDto->sqlTableName = 'Accounts';
        foreach ([
            fn () => $this->controller->applyDatabaseSchemaDiff($applyDiffRequestDto, $this->databaseSchemaDiffService),
            fn () => $this->controller->applyDatabaseSchemaDiffs(new ApplyDiffsRequestDto(), $this->databaseSchemaDiffService),
        ] as $schemaWrite) {
            try {
                $schemaWrite();
                $this->fail('A schema write must be refused');
            } catch (ForbiddenException) {
                $this->assertNull($this->databaseSchemaDiffService->appliedDiffs);
            }
        }
    }

    public function testTheReadOnlyEndpointsStayAvailableWhenSchemaWritesAreDisabled(): void
    {
        $this->controller->schemaWritesDisabledOverride = true;

        $responseDto = $this->controller->getDatabaseSchemaDiff(new DBTableDiffsGetRequestDto(), $this->databaseSchemaDiffService);
        $this->assertSame(3, $responseDto->diffs->count());

        $entityModelGeneratorService = new FixtureSqlRenderingEntityModelGeneratorService();
        $tablesSqlResponseDto = $this->controller->generateDatabaseTablesSql($entityModelGeneratorService);
        $this->assertTrue($entityModelGeneratorService->tablesSqlRendered);
        $this->assertSame(FixtureSqlRenderingEntityModelGeneratorService::RENDERED_SQL, $tablesSqlResponseDto->getContent());
        $this->assertNull($this->databaseSchemaDiffService->appliedDiffs);
    }

    public function testTheSchemaWritesRefusalCarriesTheDefaultMessage(): void
    {
        $this->controller->schemaWritesDisabledOverride = true;
        try {
            $this->controller->applyDatabaseSchemaDiffs(new ApplyDiffsRequestDto(), $this->databaseSchemaDiffService);
            $this->fail('A schema write must be refused');
        } catch (ForbiddenException $forbiddenException) {
            $this->assertStringStartsWith('Schema writes are disabled on this process', $forbiddenException->getMessage());
        }
    }

    public function testAnApplicationNamesItsOwnProcedureInTheSchemaWritesRefusal(): void
    {
        $this->controller->schemaWritesDisabledOverride = true;
        $this->controller->schemaWritesDisabledMessageOverride = 'Schema changes ship with the release pipeline.';
        $applyDiffRequestDto = new ApplyDiffRequestDto();
        $applyDiffRequestDto->sqlTableName = 'Accounts';
        try {
            $this->controller->applyDatabaseSchemaDiff($applyDiffRequestDto, $this->databaseSchemaDiffService);
            $this->fail('A schema write must be refused');
        } catch (ForbiddenException $forbiddenException) {
            $this->assertSame('Schema changes ship with the release pipeline.', $forbiddenException->getMessage());
        }
    }

    public function testTheEnvironmentSwitchDisablesSchemaWrites(): void
    {
        putenv('APP_SCHEMA_WRITES_DISABLED');
        $this->assertFalse($this->controller->areSchemaWritesDisabledByEnvironment());
        putenv('APP_SCHEMA_WRITES_DISABLED=1');
        $this->assertTrue($this->controller->areSchemaWritesDisabledByEnvironment());
        putenv('APP_SCHEMA_WRITES_DISABLED=0');
        $this->assertFalse($this->controller->areSchemaWritesDisabledByEnvironment());
    }
}
