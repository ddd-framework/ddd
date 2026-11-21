<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable\Fixtures;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A request DTO with a Translatable entity in its body, for the Content-Language tests */
class TranslatableFixtureRowRequestDto extends RequestDto
{
    /** @var TranslatableFixtureRow The row sent */
    #[Parameter(in: Parameter::BODY, required: true)]
    public TranslatableFixtureRow $translatableFixtureRow;
}
