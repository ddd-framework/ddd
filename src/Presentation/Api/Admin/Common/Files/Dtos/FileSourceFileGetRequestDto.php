<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Files\Dtos;

use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Addresses one file by its reference `<source>/<id>`.
 */
class FileSourceFileGetRequestDto extends RequestDto
{
    /** The route requirements of the two path parameters: a reference is never a path */
    public const array PATH_REQUIREMENTS = [
        'source' => FileReference::SOURCE_NAME_PATTERN,
        'id' => FileReference::ID_PATTERN,
    ];

    /** @var string The name of the file source, e.g. media */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $source;

    /** @var string The id of the file inside its source */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $id;

    public function getFileReference(): FileReference
    {
        return FileReference::fromString($this->source . '/' . $this->id);
    }
}
