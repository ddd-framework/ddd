<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands;

use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use Symfony\Component\Console\Attribute\AsCommand;

/** An application subclass of a framework command with a name and an alias of its own */
#[AsCommand(name: self::COMMAND_NAME, aliases: [self::ALIAS_NAME])]
class RenamedIssuesHealthCommand extends IssuesHealth
{
    public const string COMMAND_NAME = 'app:host:issues-health';

    public const string ALIAS_NAME = 'app:host:health';
}
