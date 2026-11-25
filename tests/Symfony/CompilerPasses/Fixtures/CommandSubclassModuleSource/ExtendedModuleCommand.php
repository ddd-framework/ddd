<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassModuleSource;

use Symfony\Component\Console\Attribute\AsCommand;

/** A module command extending another command of the same module: both stay, each with its own names */
#[AsCommand(name: self::COMMAND_NAME, description: 'A module command built on another one')]
class ExtendedModuleCommand extends RenamedModuleCommand
{
    public const string COMMAND_NAME = 'app:module-subclass-fixture:extended';
}
