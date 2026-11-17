<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class DoctrineModelForEntityGetRequestDto extends RequestDto
{
    /** @var string The entity's short class name (`Account`) or its fully qualified class name; a short name shared by several entities is refused */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $entityName;
}
