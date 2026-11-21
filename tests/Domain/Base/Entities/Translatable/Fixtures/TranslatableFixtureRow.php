<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;

/** An entity with two Translatable properties and a plain one, for the Translatable unit tests */
class TranslatableFixtureRow extends Entity
{
    use TranslatableTrait;

    /** @var string|null A translated name */
    #[Translatable]
    public ?string $name;

    /** @var string|null A translated title */
    #[Translatable]
    public ?string $title;

    /** @var string|null A plain label */
    public ?string $label = null;
}
