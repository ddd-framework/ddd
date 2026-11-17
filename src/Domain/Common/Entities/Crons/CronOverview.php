<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One Cron as an admin screen shows it: its definition, its health verdict, its newest runs and its 24-hour stats.
 */
class CronOverview extends ValueObject
{
    /** The command is not in this build of the console */
    public const string STATUS_MISSING_COMMAND = 'MISSING_COMMAND';
    /** Switched off */
    public const string STATUS_PAUSED = 'PAUSED';
    /** A run is in progress */
    public const string STATUS_RUNNING = 'RUNNING';
    /** Active and its slot is more than {@see self::OVERDUE_AFTER_SECONDS} past, not running: the runner is not ticking */
    public const string STATUS_OVERDUE = 'OVERDUE';
    /** The last ended run failed */
    public const string STATUS_FAILING = 'FAILING';
    /** Healthy */
    public const string STATUS_OK = 'OK';

    /** @var string[] In the order they are checked: the first that matches is the status */
    public const array STATUSES = [
        self::STATUS_MISSING_COMMAND,
        self::STATUS_PAUSED,
        self::STATUS_RUNNING,
        self::STATUS_OVERDUE,
        self::STATUS_FAILING,
        self::STATUS_OK,
    ];

    /** A slot more than this many seconds in the past, with no run going, makes an active Cron OVERDUE */
    public const int OVERDUE_AFTER_SECONDS = 120;

    /** @var int Id of the Cron */
    public int $id;

    /** @var string Unique name of the Cron */
    public string $name = '';

    /** @var string What the Cron is for */
    public string $description = '';

    /** @var string Cron expression, five fields, evaluated in the PHP default time zone */
    public string $schedule = '';

    /** @var string The full stored command line */
    public string $command = '';

    /** @var string First word of the command: the console command name */
    public string $commandName = '';

    /** @var string|null The rest of the command line; null when there are no arguments */
    public ?string $arguments = null;

    /** @var bool Whether the runner takes it */
    public bool $isActive = true;

    /** @var bool Whether the console of this build knows the command */
    public bool $commandExists = true;

    /** @var bool Whether the admin endpoints may schedule the command (`#[CronSchedulable]` or the framework's list) */
    public bool $commandSchedulable = false;

    /** @var string MISSING_COMMAND, PAUSED, RUNNING, OVERDUE, FAILING or OK; the first that matches in that order */
    #[Choice(choices: self::STATUSES)]
    public string $status = self::STATUS_OK;

    /** @var DateTime|null When the newest run started */
    public ?DateTime $lastExecutionStartedAt = null;

    /** @var DateTime|null The slot the runner takes next */
    public ?DateTime $nextExecutionScheduledAt = null;

    /** @var DateTime|null When the Cron was created */
    public ?DateTime $created = null;

    /** @var DateTime|null When the Cron was last changed */
    public ?DateTime $updated = null;

    /** @var CronExecutionOverview|null The newest run */
    public ?CronExecutionOverview $lastExecution = null;

    /** @var CronExecutionOverviews The newest runs (30), newest first, with the output preview */
    public CronExecutionOverviews $recentExecutions;

    /** @var CronRunStatistics Ended runs of the last 24 hours */
    public CronRunStatistics $stats24h;

    public function __construct()
    {
        $this->recentExecutions = new CronExecutionOverviews();
        $this->stats24h = new CronRunStatistics();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->id);
    }

    /**
     * The health verdict, first match wins: MISSING_COMMAND, PAUSED, RUNNING, OVERDUE, FAILING, OK. Reads
     * `commandExists`, `isActive`, `nextExecutionScheduledAt` and `recentExecutions` (newest first).
     */
    public function resolveStatus(bool $isRunning, DateTime $now): string
    {
        if (!$this->commandExists) {
            return self::STATUS_MISSING_COMMAND;
        }
        if (!$this->isActive) {
            return self::STATUS_PAUSED;
        }
        if ($isRunning) {
            return self::STATUS_RUNNING;
        }
        if ($this->nextExecutionScheduledAt !== null
            && $this->nextExecutionScheduledAt->getTimestamp() < $now->getTimestamp() - self::OVERDUE_AFTER_SECONDS) {
            return self::STATUS_OVERDUE;
        }
        return $this->hasLastEndedRunFailed() ? self::STATUS_FAILING : self::STATUS_OK;
    }

    /** Whether the newest ENDED run failed — a run still RUNNING is skipped, the one before it decides */
    public function hasLastEndedRunFailed(): bool
    {
        return $this->recentExecutions->getNewestEndedExecution()?->hasFailed() ?? false;
    }
}
