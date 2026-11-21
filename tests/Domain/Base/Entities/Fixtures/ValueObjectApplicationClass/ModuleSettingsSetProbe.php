<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

use DDD\Domain\Base\Entities\ObjectSet;

/** A module's set of its value objects */
class ModuleSettingsSetProbe extends ObjectSet
{
    /** @var ModuleSettingsProbe[] */
    public array $elements = [];
}
