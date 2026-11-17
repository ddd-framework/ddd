<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use Symfony\Component\Console\Attribute\AsCommand;

/** Inherits `#[CronSchedulable]` from its parent command */
#[AsCommand(name: 'app:fixture:close-stale-inherited', description: 'Closes stale fixture rows, inherited')]
class FixtureInheritedSchedulableCommand extends FixtureSchedulableCommand
{
}
