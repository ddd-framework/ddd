<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Common\Crons\Fixtures;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Domain\Common\Services\CronsService;

/** The Crons in memory instead of the database */
class FixtureCronsService extends CronsService
{
    public Crons $crons;

    public function __construct()
    {
        $this->crons = new Crons();
    }

    public function list(): Crons
    {
        return $this->crons;
    }

    public function findByName(string $name): ?Cron
    {
        foreach ($this->crons->getElements() as $cron) {
            if ($cron->name === $name) {
                return $cron;
            }
        }
        return null;
    }
}
