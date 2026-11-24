<?php

declare(strict_types=1);

namespace DDD\Tests;

use DDD\Testing\Modules\ModuleNamespacesTestCase;

/** Core and the DDD modules installed beside it share no class name and no config file */
class ModuleNamespacesTest extends ModuleNamespacesTestCase
{
    protected function getProjectDirectory(): string
    {
        return dirname(__DIR__);
    }
}
