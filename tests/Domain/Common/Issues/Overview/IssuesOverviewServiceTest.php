<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Issues\Overview;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Issues\IssueEntryPoint;
use DDD\Domain\Common\Entities\Issues\IssueEntryPoints;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCount;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupStatusTotals;
use DDD\Domain\Common\Entities\Issues\Overview\IssuesTrendSelection;
use DDD\Domain\Common\Services\IssuesOverviewService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Logging\IssuesQueryEnvironments;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Tests\Domain\Common\Issues\Overview\Fixtures\FixtureIssuesOverviewService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class IssuesOverviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // an entity's lazy-load attributes read the default repository type an application configures in its .env
        $defaultRepoTypeProperty = new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType');
        if (!$defaultRepoTypeProperty->isInitialized()) {
            $defaultRepoTypeProperty->setValue(null, LazyLoadRepo::DB);
        }
    }

    protected function createIssuesOverviewService(string $queryBackend = IssuesRegistrySettings::BACKEND_NONE): FixtureIssuesOverviewService
    {
        $issuesRegistryJobService = new IssuesRegistryJobService();
        $issuesRegistryJobService->setSettings(new IssuesRegistrySettings(
            queryBackend: $queryBackend,
            queryEnvironments: IssuesQueryEnvironments::fromJson('{"prod": "{job=\"prod\"}", "testing": "{job=\"testing\"}"}'),
        ));
        $issueGroupStatusCounts = new IssueGroupStatusCounts();
        foreach ([['prod', IssueGroup::STATUS_OPEN, 3], ['legacy', IssueGroup::STATUS_OPEN, 2], ['prod', IssueGroup::STATUS_RESOLVED, 1]] as [$environment, $status, $count]) {
            $issueGroupStatusCount = new IssueGroupStatusCount($environment, $status, $count);
            $issueGroupStatusCounts->add($issueGroupStatusCount);
        }
        return new FixtureIssuesOverviewService($issuesRegistryJobService, $issueGroupStatusCounts);
    }

    public function testTheKnownEnvironmentsAreTheConfiguredOnesThenThoseWithGroups(): void
    {
        $this->assertSame(['prod', 'testing', 'legacy'], $this->createIssuesOverviewService()->getKnownEnvironments());
    }

    public function testAnOmittedEnvironmentResolvesToTheFirstKnownOne(): void
    {
        $issuesOverviewService = $this->createIssuesOverviewService();
        $this->assertSame('prod', $issuesOverviewService->resolveEnvironment(null));
        $this->assertSame('prod', $issuesOverviewService->resolveEnvironment('  '));
        $this->assertSame('legacy', $issuesOverviewService->resolveEnvironment('legacy'));
        $this->assertSame(IssuesOverviewService::ENVIRONMENT_ALL, $issuesOverviewService->resolveEnvironment(' ALL '));
    }

    public function testAnUnknownEnvironmentIsABadRequest(): void
    {
        $this->expectException(BadRequestException::class);
        $this->createIssuesOverviewService()->resolveEnvironment('staging');
    }

    public function testAllStandsForEveryKnownEnvironment(): void
    {
        $issuesOverviewService = $this->createIssuesOverviewService();
        $this->assertSame(['prod', 'testing', 'legacy'], $issuesOverviewService->resolveEnvironmentsToQuery(IssuesOverviewService::ENVIRONMENT_ALL));
        $this->assertSame(['testing'], $issuesOverviewService->resolveEnvironmentsToQuery('testing'));
        $this->assertSame([], $issuesOverviewService->resolveEnvironmentsToQuery(''));
    }

    public function testTheStatusTotalsSumTheEnvironments(): void
    {
        $issuesOverviewService = $this->createIssuesOverviewService();
        $issueGroupStatusTotals = IssueGroupStatusTotals::fromIssueGroupStatusCountsOfEnvironments(
            $issuesOverviewService->fixtureIssueGroupStatusCounts,
            ['prod', 'legacy', 'prod']
        );
        $this->assertSame([5, 1, 0], [$issueGroupStatusTotals->open, $issueGroupStatusTotals->resolved, $issueGroupStatusTotals->ignored]);
    }

    public function testTheTrendWindowDefaultsAndLimits(): void
    {
        $issuesOverviewService = $this->createIssuesOverviewService();
        $timeInterval = $issuesOverviewService->resolveTrendInterval(null, '2026-10-28T10:00:00Z');
        $this->assertSame('2026-10-27T10:00:00Z', gmdate(DateTime::DATE_RFC3339_ZULU, $timeInterval->startDate->getTimestamp()));
        $timeInterval = $issuesOverviewService->resolveTrendInterval('2026-10-28T09:00:00+01:00', '2026-10-28T10:00:00Z');
        $this->assertSame(7200, $timeInterval->endDate->getTimestamp() - $timeInterval->startDate->getTimestamp());
        foreach ([['2026-10-28T10:00:00Z', '2026-10-28T09:00:00Z'], ['2026-09-01T00:00:00Z', '2026-10-28T00:00:00Z'], ['yesterday-ish', null]] as [$from, $to]) {
            try {
                $issuesOverviewService->resolveTrendInterval($from, $to);
                $this->fail("$from … $to must be refused");
            } catch (BadRequestException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testATrendWithoutALogBackendIsAnAllZeroSeriesNamingWhy(): void
    {
        $issuesOverviewService = $this->createIssuesOverviewService();
        $timeInterval = new TimeInterval(DateTime::fromTimestamp(1761645600), DateTime::fromTimestamp(1761645600 + 3600));
        $issuesTrendSelection = $issuesOverviewService->findTrend('prod', null, $timeInterval);
        $this->assertSame(IssuesTrendSelection::TREND_ERROR_BACKEND_NOT_CONFIGURED, $issuesTrendSelection->trendError);
        $this->assertSame(0.0, (float)$issuesTrendSelection->series->getTotal());
        $this->assertSame(60, $issuesTrendSelection->series->stepSeconds);
        $this->assertCount(61, $issuesTrendSelection->series->getValuesByTimestamp());
        $this->assertFalse($issuesTrendSelection->isFilteredByGroups);
    }

    public function testTheListFiltersAndTheLimit(): void
    {
        $this->assertFalse(IssuesOverviewService::hasGroupFilter(null, ' ', null, ''));
        $this->assertTrue(IssuesOverviewService::hasGroupFilter(IssueGroup::STATUS_OPEN, null, null, null));
        $this->assertTrue(IssuesOverviewService::hasGroupFilter(null, null, 'HTTP', null));
        $this->assertSame(1, IssuesOverviewService::capListLimit(0));
        $this->assertSame(IssuesOverviewService::MAX_LIST_LIMIT, IssuesOverviewService::capListLimit(5000));
    }

    public function testEntryPointsReadAsPeopleReadThem(): void
    {
        $this->assertSame(
            'POST /api/admin/orders',
            IssuesOverviewService::formatEntryPoint('App\Presentation\Api\Admin\Orders\OrdersController:create__POST:/orders')
        );
        $this->assertSame(
            'GET|HEAD /health',
            IssuesOverviewService::formatEntryPoint('Vendor\Module\HealthController:check__GET,HEAD:/health')
        );
        $this->assertSame('GET /status', IssuesOverviewService::formatEntryPoint('GET:/status'));
        $this->assertSame('app:crons:execute', IssuesOverviewService::formatEntryPoint('CLI:app:crons:execute'));
        $this->assertSame('/orders/[orderId]', IssuesOverviewService::formatEntryPoint('WEB_SERVER:/orders/[orderId]'));
        $this->assertSame('send_email', IssuesOverviewService::formatEntryPoint('MessageHandler:send_email'));
        $this->assertNull(IssuesOverviewService::formatEntryPoint('N/A'));
        $this->assertSame(
            'SendEmailMessage (send_email)',
            IssuesOverviewService::formatMessengerEntryPoint('send_email', ['App\Domain\Mail\SendEmailMessage'])
        );
        $this->assertSame('async', IssuesOverviewService::formatMessengerEntryPoint('async', ['A\One', 'A\Two']));
    }

    public function testThePrimaryEntryPointIsTheOneOfTheSampleEvent(): void
    {
        $issueGroup = new IssueGroup();
        $issueGroup->slug = 'OrdersController:create__POST:/orders|PDOException';
        $issueGroup->entryPoints = new IssueEntryPoints();
        foreach (['CLI:app:orders:import', 'App\Presentation\Api\Admin\OrdersController:create__POST:/orders'] as $entryPointName) {
            $issueEntryPoint = new IssueEntryPoint();
            $issueEntryPoint->name = $entryPointName;
            $issueGroup->entryPoints->add($issueEntryPoint);
        }
        $this->assertSame('App\Presentation\Api\Admin\OrdersController:create__POST:/orders', IssuesOverviewService::selectPrimaryEntryPoint($issueGroup));
        $issueGroup->slug = null;
        $this->assertSame('CLI:app:orders:import', IssuesOverviewService::selectPrimaryEntryPoint($issueGroup));
        $this->assertNull(IssuesOverviewService::findMessageHandlerTransport($issueGroup));
    }
}
