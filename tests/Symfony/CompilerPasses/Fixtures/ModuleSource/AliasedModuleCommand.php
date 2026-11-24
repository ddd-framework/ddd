<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\Console\Attribute\AsCommand;

/** A console command of a module that keeps an older name as an alias */
#[AsCommand(name: 'app:module-fixture:aliased', description: 'A module command with an alias', aliases: ['app:module-fixture:old-name'])]
class AliasedModuleCommand extends AbstractModuleCommand
{
}
