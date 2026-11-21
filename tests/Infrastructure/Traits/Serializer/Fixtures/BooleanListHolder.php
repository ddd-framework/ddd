<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;

/** Holds a list of booleans */
class BooleanListHolder extends ValueObject
{
    /** @var bool[] Switches in their order */
    public array $switches = [];
}
