<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Common\Crons\Fixtures;

use DDD\Symfony\Commands\Common\Crons\CronsList;

/** The CLI admin lives in the database; the list under test does not need it. */
class FixtureCronsList extends CronsList
{
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
    }
}
