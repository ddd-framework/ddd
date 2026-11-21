<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

/** A second subclass of the module's value object — what a row names in `objectType` */
class SpecificSettingsProbe extends ModuleSettingsProbe
{
    public ?string $kind = null;
}
