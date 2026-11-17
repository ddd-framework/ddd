<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Addresses one error group: one hash is one group per environment.
 */
class IssueGroupGetRequestDto extends RequestDto
{
    /** The route requirements of the two path parameters */
    public const array PATH_REQUIREMENTS = [
        'environment' => '[A-Za-z0-9_.-]+',
        'groupHash' => '[A-Za-z0-9]+',
    ];

    /** @var string The registry environment of the group ("all" is no environment here) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $environment;

    /** @var string The root-cause hash (error.group_hash) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $groupHash;
}
