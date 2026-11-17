<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use DDD\Symfony\Commands\Common\Crons\CronSchedulable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'app:fixture:hidden', description: 'A hidden command', hidden: true)]
#[CronSchedulable]
class FixtureHiddenSchedulableCommand extends Command
{
}
