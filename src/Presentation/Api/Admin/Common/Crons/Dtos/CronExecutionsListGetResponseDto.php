<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronExecutionOverviews;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One page of Cron runs.
 */
class CronExecutionsListGetResponseDto extends RestResponseDto
{
    /** @var CronExecutionOverviews The runs, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronExecutionOverviews $executions;

    /** @var int Runs matching the filter, over all pages */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $totalCount = 0;
}
