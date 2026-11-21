<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

use DDD\Domain\Base\Entities\ValueObject;

/** A module's value object */
class ModuleSettingsProbe extends ValueObject
{
    public ?int $maxSteps = null;
}
