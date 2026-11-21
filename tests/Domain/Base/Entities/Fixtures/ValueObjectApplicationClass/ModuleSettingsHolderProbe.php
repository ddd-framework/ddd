<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

use DDD\Domain\Base\Entities\ValueObject;

/** A module's holder: the property types are the module's class and stay it in every subclass (PHP: invariant) */
class ModuleSettingsHolderProbe extends ValueObject
{
    public ?ModuleSettingsProbe $settings;

    /** @var ModuleSettingsProbe[] */
    public array $settingsList = [];
}
