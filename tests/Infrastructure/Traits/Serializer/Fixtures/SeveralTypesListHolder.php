<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;

/** Holds a plain list whose elements are one of two types */
class SeveralTypesListHolder extends ValueObject
{
    /** @var (SeveralTypesListFirstItem|SeveralTypesListSecondItem)[] Elements of either type */
    public array $items = [];
}
