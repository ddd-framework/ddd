<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Infrastructure\Exceptions\ForbiddenException;

/**
 * Denies every DatabaseModels endpoint through the access hook and records that the hook ran.
 */
class FixtureForbiddingDatabaseModelsController extends FixtureDatabaseModelsController
{
    public int $accessHookCallCount = 0;

    protected function throwForbiddenUnlessDatabaseModelsAccessAllowed(): void
    {
        $this->accessHookCallCount++;
        throw new ForbiddenException('Denied by the fixture access hook');
    }
}
