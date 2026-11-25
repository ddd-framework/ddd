<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\FrameworkSubclassModuleSource;

use DDD\Symfony\Commands\Common\Issues\IssuesSync;

/** A module command extending a framework command, without an #[AsCommand] of its own */
class ModuleIssuesSyncCommand extends IssuesSync
{
}
