<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;

/**
 * The error events of a trend scope per bucket, and how complete they are: one environment (or all), one group, or the
 * groups a list filter selects (their trends summed).
 */
class IssuesTrendSelection extends ValueObject
{
    /** The log backend failed: every point 0 */
    public const string TREND_ERROR_BACKEND_UNAVAILABLE = 'backend_unavailable';

    /** No log backend watches the scope: every point 0 */
    public const string TREND_ERROR_BACKEND_NOT_CONFIGURED = 'backend_not_configured';

    /** Some environments or groups could not be read: the points are a lower bound */
    public const string TREND_ERROR_BACKEND_PARTIAL = 'backend_partial';

    /** @var DateTimeValueSequence Gap-free points, one per bucket of the window */
    public DateTimeValueSequence $series;

    /**
     * @var string|null Why the points may be too low: {@see self::TREND_ERROR_BACKEND_UNAVAILABLE},
     * {@see self::TREND_ERROR_BACKEND_NOT_CONFIGURED} or {@see self::TREND_ERROR_BACKEND_PARTIAL}; null when complete
     */
    public ?string $trendError = null;

    /** @var bool The points are the sum of the groups a list filter selects */
    public bool $isFilteredByGroups = false;

    /** @var int|null Groups matching the filter with events in the window; null without a list filter */
    public ?int $matchingGroupCount = null;

    /** @var int|null Groups whose trends are in the sum; null without a list filter */
    public ?int $summedGroupCount = null;

    /** @var bool Matching groups were left out of the sum: the points are a lower bound */
    public bool $isTruncated = false;

    public static function fromSeries(DateTimeValueSequence $series, ?string $trendError = null): static
    {
        $issuesTrendSelection = new static();
        $issuesTrendSelection->series = $series;
        $issuesTrendSelection->trendError = $trendError;
        return $issuesTrendSelection;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
