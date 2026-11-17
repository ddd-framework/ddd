<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingIssuesController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureIssuesController;

class IssuesControllerTraitTest extends AdminControllerTraitTestCase
{
    protected function getFixtureControllerClass(): string
    {
        return FixtureIssuesController::class;
    }

    protected function getForbiddingFixtureControllerClass(): string
    {
        return FixtureForbiddingIssuesController::class;
    }

    protected function getAccessHookMethodName(): string
    {
        return 'throwForbiddenUnlessIssuesAccessAllowed';
    }

    public function testTheTraitMountsItsRoutesUnderTheControllerPrefix(): void
    {
        $this->assertSame([
            'GET /common/issues/groups/list',
            'GET /common/issues/groups/{environment}/{groupHash}',
            'GET /common/issues/trend',
            'POST /common/issues/groups/{environment}/{groupHash}/ignore',
            'POST /common/issues/groups/{environment}/{groupHash}/reopen',
            'POST /common/issues/groups/{environment}/{groupHash}/resolve',
        ], $this->getMethodAndPathOfEveryRoute());
    }

    public function testTheGroupPathsCarryTheirRequirements(): void
    {
        foreach ($this->loadRouteCollection() as $route) {
            if (!str_contains($route->getPath(), '{groupHash}')) {
                continue;
            }
            $this->assertSame('[A-Za-z0-9_.-]+', $route->getRequirement('environment'), $route->getPath());
            $this->assertSame('[A-Za-z0-9]+', $route->getRequirement('groupHash'), $route->getPath());
        }
    }

    public function testTheOpenApiDocumentDocumentsEveryRoute(): void
    {
        $document = $this->buildOpenApiDocument();
        $this->assertCount(6, $document->paths);
        $schemaNames = $this->getDocumentedSchemaNames($document);
        foreach ([
            'DDD.Presentation.Api.Admin.Common.Issues.Dtos.IssueGroupsListGetResponseDto',
            'DDD.Presentation.Api.Admin.Common.Issues.Dtos.IssuesTrendGetResponseDto',
            'DDD.Presentation.Api.Admin.Common.Issues.Dtos.IssueGroupDetailGetResponseDto',
            'DDD.Presentation.Api.Admin.Common.Issues.Dtos.IssueGroupGetResponseDto',
            'DDD.Domain.Common.Entities.Issues.Overview.IssueEventDetail',
            'DDD.Domain.Common.Entities.Issues.Overview.IssueEventWorkerContext',
        ] as $schemaName) {
            $this->assertContains($schemaName, $schemaNames);
        }
    }
}
