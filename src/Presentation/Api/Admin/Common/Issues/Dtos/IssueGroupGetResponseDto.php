<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummary;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One error group after a status change (resolve, ignore, reopen).
 */
class IssueGroupGetResponseDto extends RestResponseDto
{
    /** @var IssueGroupSummary The group with its new status */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroupSummary $group;
}
