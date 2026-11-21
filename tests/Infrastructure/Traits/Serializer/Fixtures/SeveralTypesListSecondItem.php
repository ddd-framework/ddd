<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;

/** The second element type of a list that takes several */
class SeveralTypesListSecondItem extends ValueObject
{
    /** @var string|null A value only this type has */
    public ?string $secondValue = null;
}
