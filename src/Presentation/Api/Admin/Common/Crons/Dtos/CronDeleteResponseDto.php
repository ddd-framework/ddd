<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The Cron that was deleted, with its run history.
 */
class CronDeleteResponseDto extends RestResponseDto
{
    /** @var int The id of the deleted Cron */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $deletedCronId;
}
