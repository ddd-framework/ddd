<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingWorkersController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureWorkersController;

class WorkersControllerTraitTest extends AdminControllerTraitTestCase
{
    protected function getFixtureControllerClass(): string
    {
        return FixtureWorkersController::class;
    }

    protected function getForbiddingFixtureControllerClass(): string
    {
        return FixtureForbiddingWorkersController::class;
    }

    protected function getAccessHookMethodName(): string
    {
        return 'throwForbiddenUnlessWorkersAccessAllowed';
    }

    public function testTheTraitMountsItsRoutesUnderTheControllerPrefix(): void
    {
        $this->assertSame([
            'GET /maintenance/workers/history',
            'GET /maintenance/workers/overview',
            'GET /maintenance/workers/transports/{transport}',
            'GET /maintenance/workers/transports/{transport}/messageRuns',
            'GET /maintenance/workers/transports/{transport}/processes',
            'POST /maintenance/workers/restart',
        ], $this->getMethodAndPathOfEveryRoute());
    }

    public function testTheOpenApiDocumentDocumentsEveryRoute(): void
    {
        $document = $this->buildOpenApiDocument();
        $this->assertCount(6, $document->paths);
        $this->assertContains('DDD.Presentation.Api.Admin.Common.Workers.Dtos.WorkersRestartResponseDto', $this->getDocumentedSchemaNames($document));
    }
}
