<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Error events of one environment, of all, of one group or of the list's current selection per time bucket. The series
 * is always complete: one point per bucket from the bucket holding `from` to the one holding `to`, 0 where nothing
 * happened. When the log backend cannot be read, every point is 0 and `trendError` says why.
 */
class IssuesTrendGetResponseDto extends RestResponseDto
{
    /** @var string The environment the trend was resolved to: the request's ("all" included), or the default */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $environment;

    /** @var string|null The error group of the trend; null = every error of the environment */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $groupHash = null;

    /** @var string Window start, ISO 8601 in UTC (e.g. 2026-10-28T10:00:00Z) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $from;

    /** @var string Window end, ISO 8601 in UTC */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $to;

    /** @var int Bucket size in seconds, chosen by the window (60, 600, 3600 or 21600) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $stepSeconds;

    /** @var DateTimeValueSequence The points: x = the bucket start (UTC), y = error events in the bucket; gap-free */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DateTimeValueSequence $series;

    /** @var int Error events in the whole window (the sum of the points) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $total = 0;

    /**
     * @var string|null Why the points may be too low: backend_unavailable (the log backend failed, every point 0),
     * backend_not_configured (no log backend watches the scope, every point 0) or backend_partial (some environments
     * or groups could not be read and are missing from the sum); null when everything was read
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $trendError = null;

    /** @var bool True when the points are the sum of the selected groups' trends (a list filter was sent) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isFilteredByGroups = false;

    /** @var int|null Groups matching the filters with events in the window; null without a list filter */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?int $matchingGroupCount = null;

    /** @var int|null Groups whose trends are in the sum (at most 50, most events first); null without a list filter */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?int $summedGroupCount = null;

    /**
     * @var bool True when matching groups were left out of the sum (more than 50, the time budget ran out, or their
     * environment has no log backend): the points are a lower bound
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isTruncated = false;
}
