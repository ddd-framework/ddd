<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands;

use DDD\Symfony\Commands\Common\Issues\IssuesHealth;

/** An application subclass of a framework command without an #[AsCommand] of its own */
class InheritingIssuesHealthCommand extends IssuesHealth
{
}
