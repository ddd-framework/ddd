<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\RecordedRuns;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The wall-clock window of a recorded run or of one call inside it, in Unix seconds, both ends included: a moment the
 * run's wall clock wrote lies inside it. Read by {@see RecordedRunWallClockNormalization}.
 */
class RecordedRunClockWindow extends ValueObject
{
    /** @var int The first second of the window (Unix seconds) */
    public int $fromTimestamp = 0;

    /** @var int The last second of the window (Unix seconds) */
    public int $toTimestamp = 0;

    /**
     * @param int $fromTimestamp The first second of the window
     * @param int $toTimestamp The last second of the window
     * @return static
     */
    public static function between(int $fromTimestamp, int $toTimestamp): static
    {
        $recordedRunClockWindow = new static();
        $recordedRunClockWindow->fromTimestamp = $fromTimestamp;
        $recordedRunClockWindow->toTimestamp = $toTimestamp;
        return $recordedRunClockWindow;
    }

    /**
     * Whether the second lies inside the window, both ends included.
     *
     * @param int $timestamp Unix seconds
     * @return bool
     */
    public function contains(int $timestamp): bool
    {
        return $timestamp >= $this->fromTimestamp && $timestamp <= $this->toTimestamp;
    }
}
