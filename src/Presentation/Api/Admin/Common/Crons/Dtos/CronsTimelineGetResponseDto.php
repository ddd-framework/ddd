<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronTimelineLanes;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Runs per Cron per time bucket: the heatmap of the runs view.
 */
class CronsTimelineGetResponseDto extends RestResponseDto
{
    /** @var DateTime Start of the window */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DateTime $from;

    /** @var DateTime End of the window (now) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DateTime $to;

    /** @var int Bucket width in minutes: 15 up to 24 hours, 60 above */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $bucketMinutes = 15;

    /** @var CronTimelineLanes One lane per Cron, sorted by name; a Cron without runs still has a lane */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronTimelineLanes $lanes;
}
