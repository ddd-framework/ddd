<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Addresses one Cron by id.
 */
class CronGetRequestDto extends RequestDto
{
    /** @var int The id of the Cron */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $cronId;
}
