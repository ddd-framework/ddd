<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One run of a Cron as an admin screen lists it: times, duration, result and the first line of its output, never the
 * full output (that is {@see CronExecutionDetail}).
 */
class CronExecutionOverview extends ValueObject
{
    /** Characters of the first non-empty output line kept as the preview */
    public const int OUTPUT_PREVIEW_CHARACTERS = 200;

    /** @var int Id of the run */
    public int $id;

    /** @var int Id of the Cron that ran */
    public int $cronId;

    /** @var string Name of the Cron that ran */
    public string $cronName = '';

    /** @var DateTime When the run started */
    public DateTime $startedAt;

    /** @var DateTime|null When the run ended; null while it runs */
    public ?DateTime $endedAt = null;

    /** @var int|null Duration in milliseconds; null while it runs */
    public ?int $durationMs = null;

    /** @var string RUNNING or ENDED */
    #[Choice(choices: [CronExecution::STATE_RUNNING, CronExecution::STATE_ENDED])]
    public string $state = CronExecution::STATE_RUNNING;

    /** @var string|null SUCCESSFUL or FAILED; null while it runs */
    #[Choice(choices: [CronExecution::EXECUTION_STATE_SUCCESSFUL, CronExecution::EXECUTION_STATE_FAILED])]
    public ?string $executionState = null;

    /** @var string|null First non-empty line of the output, at most 200 characters; null when there is none or the read does not carry it */
    public ?string $outputPreview = null;

    /** @var int Length of the full output in characters */
    public int $outputLength = 0;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->id);
    }

    public function isEnded(): bool
    {
        return $this->state === CronExecution::STATE_ENDED;
    }

    public function hasFailed(): bool
    {
        return $this->isEnded() && $this->executionState === CronExecution::EXECUTION_STATE_FAILED;
    }

    /**
     * The first non-empty line of an output head, at most {@see self::OUTPUT_PREVIEW_CHARACTERS} characters.
     */
    public static function extractOutputPreview(?string $outputHead): ?string
    {
        if ($outputHead === null) {
            return null;
        }
        foreach (preg_split('/\R/u', $outputHead) ?: [] as $outputLine) {
            $outputLine = trim($outputLine);
            if ($outputLine !== '') {
                return mb_substr($outputLine, 0, self::OUTPUT_PREVIEW_CHARACTERS);
            }
        }
        return null;
    }

    /**
     * Milliseconds between start and end (whole seconds: the columns store seconds); null when either is missing.
     */
    public static function calculateDurationMs(?DateTime $startedAt, ?DateTime $endedAt): ?int
    {
        if ($startedAt === null || $endedAt === null) {
            return null;
        }
        return max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000;
    }
}
