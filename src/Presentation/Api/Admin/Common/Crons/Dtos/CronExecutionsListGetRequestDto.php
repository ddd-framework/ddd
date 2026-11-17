<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronExecutionsPage;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Filters and paging of the Cron runs list.
 */
class CronExecutionsListGetRequestDto extends RequestDto
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    /** @var int|null Only the runs of this Cron; an unknown id matches no run */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $cronId = null;

    /** @var string ALL (default), SUCCESSFUL, FAILED or RUNNING */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: CronExecutionsPage::RESULTS)]
    public string $result = CronExecutionsPage::RESULT_ALL;

    /** @var int Page size from 1 to 200, 50 by default */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Range(min: 1, max: self::MAX_LIMIT)]
    public int $limit = self::DEFAULT_LIMIT;

    /** @var int Runs to skip (paging) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Range(min: 0, max: 1000000)]
    public int $offset = 0;
}
