<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One Cron after a create, update, switch or run-now.
 */
class CronGetResponseDto extends RestResponseDto
{
    /** @var CronOverview The Cron as it is now */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronOverview $cron;
}
