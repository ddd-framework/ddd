<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Window of the runs timeline.
 */
class CronsTimelineGetRequestDto extends RequestDto
{
    public const int DEFAULT_HOURS = 24;
    /** One week: the runs are kept 14 days */
    public const int MAX_HOURS = 168;

    /** @var int Hours back from now, 1 to 168; 24 by default */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Range(min: 1, max: self::MAX_HOURS)]
    public int $hours = self::DEFAULT_HOURS;
}
