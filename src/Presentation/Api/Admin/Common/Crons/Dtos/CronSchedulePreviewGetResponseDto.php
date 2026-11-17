<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronScheduledRuns;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Whether an expression is valid, and when it would run next. An invalid expression is a 200 with `isValid` false.
 */
class CronSchedulePreviewGetResponseDto extends RestResponseDto
{
    /** @var bool Whether the expression is valid */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isValid = false;

    /** @var string|null A sentence saying what is wrong; null when valid */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $error = null;

    /** @var CronScheduledRuns The next 5 slots; empty when invalid */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronScheduledRuns $nextRuns;
}
