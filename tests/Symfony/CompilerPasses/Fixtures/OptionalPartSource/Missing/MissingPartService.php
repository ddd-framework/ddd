<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\OptionalPartSource\Missing;

use DDD\Tests\NotInstalledPackage\PackageBase;

/** Belongs to a part whose package is not installed: loading this file would fail */
class MissingPartService extends PackageBase
{
}
