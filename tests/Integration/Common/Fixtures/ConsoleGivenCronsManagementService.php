<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Common\Fixtures;

use DDD\Domain\Common\Services\CronsManagementService;
use Symfony\Component\Console\Application;

/** The management service against the real database, with a given console instead of the kernel's */
class ConsoleGivenCronsManagementService extends CronsManagementService
{
    public function __construct(Application $consoleApplication)
    {
        $this->consoleApplication = $consoleApplication;
    }
}
