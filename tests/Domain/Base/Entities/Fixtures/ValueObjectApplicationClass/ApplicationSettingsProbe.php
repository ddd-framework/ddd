<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass;

/** The application's class of it: a field only the application knows */
class ApplicationSettingsProbe extends ModuleSettingsProbe
{
    public ?int $ownRowId = null;
}
