<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassModuleSource;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/** A module command the module renamed, keeping the former name as an alias */
#[AsCommand(name: self::COMMAND_NAME, description: 'A renamed module command', aliases: [self::PREVIOUS_COMMAND_NAME])]
class RenamedModuleCommand extends Command
{
    public const string COMMAND_NAME = 'app:module-subclass-fixture:new-name';

    public const string PREVIOUS_COMMAND_NAME = 'app:module-subclass-fixture:previous-name';
}
