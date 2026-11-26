<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Files\Dtos;

use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The facts of one file the caller may read.
 */
class FileSourceFileFactsGetResponseDto extends RestResponseDto
{
    /** @var FileFacts Name, family, measured type and size, audience and where the file lies */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public FileFacts $fileFacts;
}
