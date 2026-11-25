<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands;

use DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassModuleSource\RenamedModuleCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** An application subclass of a module command that still repeats the command's former name */
#[AsCommand(name: RenamedModuleCommand::PREVIOUS_COMMAND_NAME)]
class StaleNameModuleCommand extends RenamedModuleCommand
{
}
