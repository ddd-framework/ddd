<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronOverviews;
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

    protected function persistCronColumns(Cron $cron, string ...$propertyNames): void
    {
        array_push($this->persistedPropertyNames, ...$propertyNames);
    }
}
