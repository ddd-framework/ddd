<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Runs per Cron per time bucket over a window: the heatmap of an admin runs view. 15-minute buckets up to 24 hours,
 * hourly above. Every Cron gets a lane, also one without a run in the window.
 */
class CronsTimeline extends ValueObject
{
    /** Window up to which the buckets are 15 minutes wide; above it they are 60 */
    public const int FINE_BUCKETS_UP_TO_HOURS = 24;

    /** @var DateTime Start of the window */
    public DateTime $from;

    /** @var DateTime End of the window (now) */
    public DateTime $to;

    /** @var int Bucket width in minutes: 15 up to 24 hours, 60 above */
    public int $bucketMinutes = 15;

    /** @var CronTimelineLanes One lane per Cron, sorted by name */
    public CronTimelineLanes $lanes;

    public function __construct()
    {
        $this->from = new DateTime();
        $this->to = new DateTime();
        $this->lanes = new CronTimelineLanes();
        parent::__construct();
    }

    public static function getBucketMinutesForHours(int $hours): int
    {
        return $hours <= self::FINE_BUCKETS_UP_TO_HOURS ? 15 : 60;
    }

    /**
     * Buckets the runs per Cron. `$cronOverviews` gives the lanes (and their order); a run of a Cron not among them is
     * left out.
     */
    public static function fromExecutions(
        DateTime $from,
        DateTime $to,
        int $hours,
        CronOverviews $cronOverviews,
        CronExecutionOverviews $cronExecutionOverviews
    ): static {
        $cronsTimeline = new static();
        $cronsTimeline->from = $from;
        $cronsTimeline->to = $to;
        $cronsTimeline->bucketMinutes = static::getBucketMinutesForHours($hours);
        $bucketSeconds = $cronsTimeline->bucketMinutes * 60;
        foreach ($cronOverviews->getElements() as $cronOverview) {
            $cronTimelineLane = new CronTimelineLane();
            $cronTimelineLane->cronId = $cronOverview->id;
            $cronTimelineLane->cronName = $cronOverview->name;
            // method-local lookup: bucket start timestamp => bucket
            $bucketsByStartTimestamp = [];
            foreach ($cronExecutionOverviews->getExecutionsOfCron($cronOverview->id)->getElements() as $cronExecutionOverview) {
                $bucketStartTimestamp = intdiv($cronExecutionOverview->startedAt->getTimestamp(), $bucketSeconds) * $bucketSeconds;
                $bucketsByStartTimestamp[$bucketStartTimestamp] ??= new CronTimelineBucket(DateTime::fromTimestamp($bucketStartTimestamp));
                $bucketsByStartTimestamp[$bucketStartTimestamp]->addExecution($cronExecutionOverview);
            }
            ksort($bucketsByStartTimestamp);
            foreach ($bucketsByStartTimestamp as $cronTimelineBucket) {
                $cronTimelineLane->buckets->add($cronTimelineBucket);
            }
            $cronsTimeline->lanes->add($cronTimelineLane);
        }
        return $cronsTimeline;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
