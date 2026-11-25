<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Common\Crons\Fixtures;

use DDD\Symfony\Commands\Common\Crons\CronsUpsert;

/** The CLI admin lives in the database; the upsert logic under test does not need it. */
class FixtureCronsUpsert extends CronsUpsert
{
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
    }
}
