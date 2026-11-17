<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The cron expression to preview.
 */
class CronSchedulePreviewGetRequestDto extends RequestDto
{
    /** @var string Cron expression, five fields */
    #[Parameter(in: Parameter::QUERY, required: true)]
    #[Length(max: 100)]
    public string $schedule;
}
