<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'app:fixture:drop-everything', description: 'A command no admin may schedule')]
class FixtureUnmarkedCommand extends Command
{
}
