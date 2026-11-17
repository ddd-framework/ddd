<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Infrastructure\Exceptions\ForbiddenException;

/**
 * Denies every Crons endpoint through the access hook and records that the hook ran.
 */
class FixtureForbiddingCronsController extends FixtureCronsController
{
    public int $accessHookCallCount = 0;

    protected function throwForbiddenUnlessCronsAccessAllowed(): void
    {
        $this->accessHookCallCount++;
        throw new ForbiddenException('Denied by the fixture access hook');
    }
}
