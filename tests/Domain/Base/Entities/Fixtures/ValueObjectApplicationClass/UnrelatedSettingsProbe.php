<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

use DDD\Domain\Base\Entities\ValueObject;

/** A value object outside the hierarchy */
class UnrelatedSettingsProbe extends ValueObject
{
    public ?string $label = null;
}
