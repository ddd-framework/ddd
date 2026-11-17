<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use DDD\Symfony\Commands\Common\Crons\CronSchedulable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'app:fixture:close-stale', description: 'Closes stale fixture rows')]
#[CronSchedulable]
class FixtureSchedulableCommand extends Command
{
}
