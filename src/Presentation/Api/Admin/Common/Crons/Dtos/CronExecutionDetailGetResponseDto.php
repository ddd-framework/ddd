<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronExecutionOverview;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One Cron run with its full output.
 */
class CronExecutionDetailGetResponseDto extends RestResponseDto
{
    /** @var CronExecutionOverview The run */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronExecutionOverview $execution;

    /** @var string The full output (stdout, stderr, the executor's reason); empty when the run wrote none */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $output = '';
}
