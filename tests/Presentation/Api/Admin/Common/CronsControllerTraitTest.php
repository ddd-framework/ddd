<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronGetRequestDto;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureCronsManagementService;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureSchedulableCommand;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingCronsController;
use DDD\Presentation\Api\Admin\Common\Crons\Dtos\CronUpdateRequestDto;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureCronsController;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Validator\Validation;

class CronsControllerTraitTest extends AdminControllerTraitTestCase
{
    protected function getFixtureControllerClass(): string
    {
        return FixtureCronsController::class;
    }

    protected function getForbiddingFixtureControllerClass(): string
    {
        return FixtureForbiddingCronsController::class;
    }

    protected function getAccessHookMethodName(): string
    {
        return 'throwForbiddenUnlessCronsAccessAllowed';
    }

    public function testTheTraitMountsItsRoutesUnderTheControllerPrefix(): void
    {
        $this->assertSame([
            'DELETE /common/crons/{cronId}',
            'GET /common/crons/commands',
            'GET /common/crons/executions/list',
            'GET /common/crons/executions/{executionId}',
            'GET /common/crons/list',
            'GET /common/crons/schedule-preview',
            'GET /common/crons/timeline',
            'GET /common/crons/{cronId}',
            'PATCH /common/crons/{cronId}',
            'POST /common/crons',
            'POST /common/crons/{cronId}/activate',
            'POST /common/crons/{cronId}/deactivate',
            'POST /common/crons/{cronId}/run-now',
        ], $this->getMethodAndPathOfEveryRoute());
    }

    public function testTheIdsAcceptDigitsOnly(): void
    {
        foreach ($this->loadRouteCollection() as $route) {
            foreach (['cronId', 'executionId'] as $pathParameterName) {
                if (str_contains($route->getPath(), '{' . $pathParameterName . '}')) {
                    $this->assertSame('\d+', $route->getRequirement($pathParameterName), $route->getPath());
                }
            }
        }
    }

    public function testTheLiteralRoutesComeBeforeTheCronIdRoute(): void
    {
        $paths = [];
        foreach ($this->loadRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }
        $cronIdRoutePosition = array_search('/common/crons/{cronId}', $paths, true);
        foreach (['/common/crons/list', '/common/crons/commands', '/common/crons/timeline', '/common/crons/schedule-preview'] as $literalPath) {
            $this->assertLessThan($cronIdRoutePosition, array_search($literalPath, $paths, true), $literalPath);
        }
    }

    public function testAnUpdateMaySendBackAStoredNameLongerThanTheNamingRuleAllows(): void
    {
        // the naming rule (3 to 80 characters) judges a new name in the service; the request takes what the column holds
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $this->assertCount(0, $validator->validatePropertyValue(CronUpdateRequestDto::class, 'name', str_repeat('Legacy Cron ', 10)));
        $this->assertCount(1, $validator->validatePropertyValue(CronUpdateRequestDto::class, 'name', str_repeat('x', 256)));
    }

    /** The management service with a stored Cron whose command a release removed, and a console without it */
    protected function createServiceWithCronOfRemovedCommand(bool $isActive): FixtureCronsManagementService
    {
        $consoleApplication = new Application();
        $consoleApplication->setAutoExit(false);
        $consoleApplication->add(new FixtureSchedulableCommand());
        $cronsManagementService = new FixtureCronsManagementService($consoleApplication);
        $cron = new Cron();
        $cron->id = 7;
        $cron->name = 'removed-command';
        $cron->description = 'Runs a command that a release removed';
        $cron->schedule = '*/5 * * * *';
        $cron->command = 'app:fixture:removed --batch=100';
        $cron->isActive = $isActive;
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(time() + 120);
        $cronsManagementService->storedCron = $cron;
        return $cronsManagementService;
    }

    protected function createFixtureController(): FixtureCronsController
    {
        return (new ReflectionClass(FixtureCronsController::class))->newInstanceWithoutConstructor();
    }

    public function testTheDeactivateEndpointSwitchesOffACronWhoseCommandWasRemoved(): void
    {
        $cronsManagementService = $this->createServiceWithCronOfRemovedCommand(true);
        $requestDto = new CronGetRequestDto();
        $requestDto->cronId = 7;

        $responseDto = $this->createFixtureController()->deactivateCron($requestDto, $cronsManagementService);

        $this->assertFalse($responseDto->cron->isActive);
        $this->assertFalse($responseDto->cron->commandExists, 'the screen sees that the command is not registered');
        $this->assertSame(['isActive'], $cronsManagementService->persistedPropertyNames);
    }

    public function testTheUpdateEndpointSwitchesOffButNeverOnACronWhoseCommandWasRemoved(): void
    {
        $cronsManagementService = $this->createServiceWithCronOfRemovedCommand(true);
        $updateRequestDto = new CronUpdateRequestDto();
        $updateRequestDto->cronId = 7;
        $updateRequestDto->isActive = false;

        $responseDto = $this->createFixtureController()->updateCron($updateRequestDto, $cronsManagementService);
        $this->assertFalse($responseDto->cron->isActive);

        $updateRequestDto->isActive = true;
        $this->expectException(BadRequestException::class);
        $this->createFixtureController()->updateCron($updateRequestDto, $cronsManagementService);
    }

    public function testTheActivateEndpointRefusesACronWhoseCommandWasRemoved(): void
    {
        $cronsManagementService = $this->createServiceWithCronOfRemovedCommand(false);
        $requestDto = new CronGetRequestDto();
        $requestDto->cronId = 7;

        try {
            $this->createFixtureController()->activateCron($requestDto, $cronsManagementService);
            $this->fail('a Cron with a removed command was switched on');
        } catch (BadRequestException $badRequestException) {
            $this->assertStringContainsString("isn't in this build", $badRequestException->getMessage());
        }
        $this->assertFalse($cronsManagementService->storedCron->isActive);
        $this->assertSame([], $cronsManagementService->persistedPropertyNames);
    }

    public function testTheOpenApiDocumentDocumentsEveryRoute(): void
    {
        $document = $this->buildOpenApiDocument();
        $documentedOperationCount = 0;
        foreach ($document->paths as $operationsByMethod) {
            $documentedOperationCount += count($operationsByMethod);
        }
        $this->assertSame(13, $documentedOperationCount);
        $schemaNames = $this->getDocumentedSchemaNames($document);
        foreach (['CronOverview', 'CronExecutionOverviews', 'CronRunnerSummary', 'CronTimelineLanes', 'CronSchedulableCommands', 'CronScheduledRuns'] as $domainClassName) {
            $this->assertContains('DDD.Domain.Common.Entities.Crons.' . $domainClassName, $schemaNames);
        }
    }
}
