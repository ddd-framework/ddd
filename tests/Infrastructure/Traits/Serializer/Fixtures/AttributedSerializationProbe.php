<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\Aliases;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;

/** One property per serialization attribute, beside a plain, an unset and a static one */
class AttributedSerializationProbe extends ValueObject
{
    /** @var string|null A property without an attribute */
    public ?string $plain = null;

    /** @var string|null Serialized under another name */
    #[OverwritePropertyName('renamed')]
    public ?string $original = null;

    /** @var string|null Serialized under its name and two more */
    #[Aliases('firstAlias', 'secondAlias')]
    public ?string $aliased = null;

    /** @var string|null Never shown */
    #[HideProperty]
    public ?string $hidden = null;

    /** @var string|null Shown, never stored */
    #[DontPersistProperty]
    public ?string $notPersisted = null;

    /** @var int|null Stays null */
    public ?int $empty = null;

    /** @var string|null Belongs to the class, not to an object */
    public static ?string $staticValue = 'static';
}
