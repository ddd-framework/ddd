<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Infrastructure\Exceptions\ForbiddenException;

/**
 * Denies both file endpoints through the access hook and records that the hook ran.
 */
class FixtureForbiddingFileSourcesController extends FixtureFileSourcesController
{
    public int $accessHookCallCount = 0;

    protected function throwForbiddenUnlessFileSourcesAccessAllowed(): void
    {
        $this->accessHookCallCount++;
        throw new ForbiddenException('Denied by the fixture access hook');
    }
}
