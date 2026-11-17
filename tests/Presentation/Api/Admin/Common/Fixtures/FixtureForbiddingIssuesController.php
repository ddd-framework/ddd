<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Infrastructure\Exceptions\ForbiddenException;

/**
 * Denies every Issues endpoint through the access hook and records that the hook ran.
 */
class FixtureForbiddingIssuesController extends FixtureIssuesController
{
    public int $accessHookCallCount = 0;

    protected function throwForbiddenUnlessIssuesAccessAllowed(): void
    {
        $this->accessHookCallCount++;
        throw new ForbiddenException('Denied by the fixture access hook');
    }
}
