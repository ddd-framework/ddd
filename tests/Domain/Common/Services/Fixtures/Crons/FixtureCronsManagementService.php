<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronCommandLine;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Crons\CronOverviews;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Domain\Common\Services\CronsManagementService;
use Symfony\Component\Console\Application;

/**
 * The management service without a database or a kernel: the console is a given application, the Crons are given
 * overviews, the running state is set by the test, and the column writes are recorded instead of executed.
 */
class FixtureCronsManagementService extends CronsManagementService
{
    public CronOverviews $cronOverviews;

    public bool $cronIsRunning = false;

    public bool $anyCronIsRunning = false;

    /** @var string[] The property names of every recorded partial write, in order */
    public array $persistedPropertyNames = [];

    /** @var string[]|null The changed property names the last validation was asked to judge */
    public ?array $validatedPropertyNames = null;

    /** @var Cron|null The Cron the endpoints find by id (the row as loaded) */
    public ?Cron $storedCron = null;

    public function __construct(Application $consoleApplication)
    {
        $this->consoleApplication = $consoleApplication;
        $this->cronOverviews = new CronOverviews();
    }

    protected function findCronOverviews(?int $cronId = null): CronOverviews
    {
        return $this->cronOverviews;
    }

    protected function hasRunningExecution(?int $cronId): bool
    {
        return $cronId === null ? $this->anyCronIsRunning : $this->cronIsRunning;
    }

    protected function assertNameIsValidAndFree(string $name, ?int $ownCronId): void
    {
        // the format rule is tested through the service; the uniqueness lookup needs the database
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            parent::assertNameIsValidAndFree($name, $ownCronId);
        }
    }

    protected function assertCronIsValid(Cron $cron, ?array $changedPropertyNames = null): void
    {
        $this->validatedPropertyNames = $changedPropertyNames;
    }

    public function findCronOrThrowNotFound(int $cronId): Cron
    {
        if ($this->storedCron === null || (int)$this->storedCron->id !== $cronId) {
            throw new NotFoundException('No cron ' . $cronId);
        }
        return $this->storedCron;
    }

    /** The overview of {@see self::$storedCron} with the command check, without runs (they live in the database) */
    public function getCronOverview(int $cronId, ?DateTime $now = null): CronOverview
    {
        $cron = $this->findCronOrThrowNotFound($cronId);
        $cronOverview = new CronOverview();
        $cronOverview->id = (int)$cron->id;
        $cronOverview->name = $cron->name;
        $cronOverview->command = $cron->command;
        $cronOverview->commandName = CronCommandLine::fromCommand($cron->command)->commandName;
        $cronOverview->isActive = $cron->isActive;
        $cronConsoleCommand = $this->findConsoleCommand($cronOverview->commandName);
        $cronOverview->commandExists = $cronConsoleCommand !== null;
        $cronOverview->commandSchedulable = $cronConsoleCommand !== null && $cronConsoleCommand->isSchedulable;
        return $cronOverview;
    }

    protected function persistCronColumns(Cron $cron, string ...$propertyNames): void
    {
        array_push($this->persistedPropertyNames, ...$propertyNames);
    }
}
