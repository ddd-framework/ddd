<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingCronsController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureCronsController;

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
