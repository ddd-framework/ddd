<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronOverviews;
use DDD\Domain\Common\Entities\Crons\CronRunnerSummary;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Every Cron with its health, and the runner's summary.
 */
class CronsListGetResponseDto extends RestResponseDto
{
    /** @var CronOverviews The Crons, sorted by name */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronOverviews $crons;

    /** @var CronRunnerSummary The runner's summary */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronRunnerSummary $runner;
}
