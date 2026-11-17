<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Issues\Overview\Fixtures;

use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Domain\Common\Services\IssuesOverviewService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;

/**
 * The overview service over a registry job with given settings and given registry counts: no database.
 */
class FixtureIssuesOverviewService extends IssuesOverviewService
{
    public function __construct(
        public IssuesRegistryJobService $fixtureIssuesRegistryJobService,
        public IssueGroupStatusCounts $fixtureIssueGroupStatusCounts = new IssueGroupStatusCounts(),
    ) {
    }

    public function getKnownEnvironments(?IssueGroupStatusCounts $issueGroupStatusCounts = null): array
    {
        return parent::getKnownEnvironments($issueGroupStatusCounts ?? $this->fixtureIssueGroupStatusCounts);
    }

    protected function getIssuesRegistryJobService(): IssuesRegistryJobService
    {
        return $this->fixtureIssuesRegistryJobService;
    }
}
