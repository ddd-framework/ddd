<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronExecutionOverviews;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Crons\CronRunStatistics;
use DDD\Domain\Common\Entities\Crons\CronScheduledRuns;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One Cron with what a details panel shows: the next slots, 14-day stats and the duration history.
 */
class CronDetailGetResponseDto extends RestResponseDto
{
    /** @var CronOverview The Cron */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronOverview $cron;

    /** @var CronScheduledRuns The next 5 slots from now; empty when paused or the command is missing */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronScheduledRuns $nextRuns;

    /** @var CronRunStatistics Ended runs of the last 14 days */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronRunStatistics $stats14d;

    /** @var CronExecutionOverviews The last 100 ended runs, oldest first, without output preview */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronExecutionOverviews $durationHistory;

    /** @var DateTime The server's clock */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DateTime $serverNow;
}
