<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use Cron\CronExpression;
use DateTimeInterface;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronCommandLine;
use DDD\Domain\Common\Entities\Crons\CronConsoleCommand;
use DDD\Domain\Common\Entities\Crons\CronDetail;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutionDetail;
use DDD\Domain\Common\Entities\Crons\CronExecutionOverview;
use DDD\Domain\Common\Entities\Crons\CronExecutionOverviews;
use DDD\Domain\Common\Entities\Crons\CronExecutionsPage;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Crons\CronOverviews;
use DDD\Domain\Common\Entities\Crons\CronRunStatistics;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Domain\Common\Entities\Crons\CronSchedulableCommand;
use DDD\Domain\Common\Entities\Crons\CronSchedulableCommands;
use DDD\Domain\Common\Entities\Crons\CronScheduledRuns;
use DDD\Domain\Common\Entities\Crons\CronSchedulePreview;
use DDD\Domain\Common\Entities\Crons\CronsOverview;
use DDD\Domain\Common\Entities\Crons\CronsTimeline;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutions;
use DDD\Domain\Common\Repo\DB\Crons\DBCrons;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ExceptionDetails;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Validation\ValidationError;
use DDD\Infrastructure\Validation\ValidationErrors;
use DDD\Infrastructure\Validation\ValidationResult;
use DDD\Symfony\Commands\Common\Crons\CronSchedulable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Throwable;

/**
 * Administration of the `Cron` / `CronExecution` rows for an admin screen
 * ({@see \DDD\Presentation\Api\Admin\Common\Crons\CronsControllerTrait}): health verdicts, run history, the runs
 * timeline, the schedulable commands, and the writes (create, update, switch, run now, delete).
 *
 * Reads are scalar DQL over the Doctrine models — no entity hydration, engine-neutral (MariaDB and PostgreSQL), the
 * output never selected whole in a list. Writes go through the `Cron` entity: its own validation decides (expression,
 * command registered, description length), and an existing row is written column by column
 * ({@see Cron::updatePartialIgnoringRights()}) because the runner writes `lastExecutionStartedAt` and
 * `nextExecutionScheduledAt` of the same row on every tick.
 *
 * A Cron is RUNNING while it has an execution in state RUNNING — the same rule the tick uses to skip it
 * ({@see CronsService::getCronsScheduledForExecution()}); a row whose runner died is closed by the next tick
 * ({@see CronsService::closeAbandonedCronExecutions()}).
 *
 * Run now never runs the command in the request: it makes the Cron due now, and the next tick (`app:crons:execute`,
 * every minute) runs it through {@see Cron::execute()} — the execution row, its closing, the next slot and the issues
 * log are the tick's own.
 *
 * Schedules are evaluated in the PHP default time zone, exactly as the runner does.
 */
class CronsManagementService extends Service
{
    /**
     * Framework commands an admin may schedule although they carry no `#[CronSchedulable]`: they are made to run as a
     * Cron. An application adds its own commands with the attribute (or extends this service).
     *
     * @var string[]
     */
    public const array FRAMEWORK_SCHEDULABLE_COMMAND_NAMES = [
        'app:issues:sync-registry-from-log-backend',
        'app:issues:check-registry-job-and-collector-health',
        'app:workers:sample',
    ];

    /** Runs kept per Cron in the list row */
    public const int RECENT_EXECUTIONS_LIMIT = 30;

    /** Leading output characters read for the list preview */
    public const int OUTPUT_HEAD_CHARACTERS = 1000;

    public const int NAME_MIN_LENGTH = 3;
    public const int NAME_MAX_LENGTH = 80;
    /** Lowercase letters and digits in words joined by single hyphens, e.g. send-reminders */
    public const string NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    public const int ARGUMENTS_MAX_LENGTH = 300;
    /** The `command` column is VARCHAR(255): the command name plus its arguments must fit */
    public const int COMMAND_MAX_LENGTH = 255;

    /** Console options that make a command a loop, switch its environment or profile it: a Cron is one bounded pass */
    public const string FORBIDDEN_ARGUMENT_PATTERN = '/^(--loop|--env|--profile)(=|$)|^-e/';

    public const string CRON_RUNNING_MESSAGE = 'This cron is running. Wait for the run to finish.';
    public const string RUNNER_ABOUT_TO_START_MESSAGE = 'The runner is about to start this cron. Try again in a minute.';
    public const string NOT_SCHEDULABLE_MESSAGE = "This command can't be scheduled from the admin: it carries no #[CronSchedulable].";
    public const string COMMAND_MISSING_MESSAGE = "The command isn't in this build.";

    protected ?Application $consoleApplication = null;

    /** @var array<string, CronConsoleCommand|null> Implementation cache: console command name => what it is, null when the console does not know it */
    protected array $consoleCommandsByName = [];

    // ------------------------------------------------------------------------------------------------ reads

    /**
     * Every Cron with its health, newest runs and 24-hour stats, sorted by name, plus the runner's summary.
     */
    public function getCronsOverview(?DateTime $now = null): CronsOverview
    {
        $now ??= new DateTime();
        $runningCronIds = $this->findRunningCronIds();
        $endedExecutionsOf24Hours = $this->findEndedExecutionsStartedSince($this->subtractFromNow($now, '-24 hours'));
        $cronsOverview = new CronsOverview();
        $cronsOverview->runner->serverNow = $now;
        foreach ($this->findCronOverviews()->getElements() as $cronOverview) {
            $this->completeCronOverview(
                $cronOverview,
                in_array($cronOverview->id, $runningCronIds, true),
                $endedExecutionsOf24Hours->getExecutionsOfCron($cronOverview->id),
                $now
            );
            $cronsOverview->crons->add($cronOverview);
            $cronsOverview->runner->addCronOverview($cronOverview);
        }
        return $cronsOverview;
    }

    /**
     * One Cron with its next 5 slots, 14-day stats and the duration history of its last 100 ended runs.
     *
     * @throws NotFoundException
     */
    public function getCronDetail(int $cronId, ?DateTime $now = null): CronDetail
    {
        $now ??= new DateTime();
        $cronDetail = new CronDetail();
        $cronDetail->cron = $this->getCronOverview($cronId, $now);
        $cronDetail->serverNow = $now;
        if ($cronDetail->cron->isActive && $cronDetail->cron->commandExists) {
            $cronDetail->nextRuns = CronScheduledRuns::fromSchedule($cronDetail->cron->schedule, CronSchedulePreview::NEXT_RUNS_COUNT, $now);
        }
        $cronDetail->stats14d = CronRunStatistics::fromExecutions(
            $this->findEndedExecutionsStartedSince($this->subtractFromNow($now, '-14 days'), $cronId)
        );
        $cronDetail->durationHistory = $this->findExecutionOverviews(
            $cronId,
            CronExecution::STATE_ENDED,
            null,
            CronDetail::DURATION_HISTORY_LIMIT,
            0,
            false
        )->getReversed();
        return $cronDetail;
    }

    /**
     * One Cron as the list shows it.
     *
     * @throws NotFoundException
     */
    public function getCronOverview(int $cronId, ?DateTime $now = null): CronOverview
    {
        $now ??= new DateTime();
        $cronOverview = $this->findCronOverviews($cronId)->first()
            ?? throw new NotFoundException('No cron ' . $cronId);
        $this->completeCronOverview(
            $cronOverview,
            $this->hasRunningExecution($cronId),
            $this->findEndedExecutionsStartedSince($this->subtractFromNow($now, '-24 hours'), $cronId),
            $now
        );
        return $cronOverview;
    }

    /**
     * One page of runs, newest first. `$result`: ALL, SUCCESSFUL, FAILED or RUNNING
     * ({@see CronExecutionsPage::RESULTS}); `$cronId` narrows to one Cron (an unknown one matches no run).
     */
    public function getCronExecutionsPage(?int $cronId, string $result, int $limit, int $offset): CronExecutionsPage
    {
        [$state, $executionState] = match ($result) {
            CronExecutionsPage::RESULT_RUNNING => [CronExecution::STATE_RUNNING, null],
            CronExecutionsPage::RESULT_SUCCESSFUL => [CronExecution::STATE_ENDED, CronExecution::EXECUTION_STATE_SUCCESSFUL],
            CronExecutionsPage::RESULT_FAILED => [CronExecution::STATE_ENDED, CronExecution::EXECUTION_STATE_FAILED],
            default => [null, null],
        };
        $cronExecutionsPage = new CronExecutionsPage();
        $cronExecutionsPage->executions = $this->findExecutionOverviews($cronId, $state, $executionState, max(1, $limit), max(0, $offset), true);
        $cronExecutionsPage->totalCount = $this->countExecutions($cronId, $state, $executionState);
        return $cronExecutionsPage;
    }

    /**
     * One run with its full output.
     *
     * @throws NotFoundException
     */
    public function getCronExecutionDetail(int $cronExecutionId): CronExecutionDetail
    {
        $queryBuilder = $this->createExecutionQueryBuilder()
            ->addSelect('cronExecution.output AS output')
            ->andWhere('cronExecution.id = :cronExecutionId')
            ->setParameter('cronExecutionId', $cronExecutionId)
            ->setMaxResults(1);
        $executionRows = $queryBuilder->getQuery()->getArrayResult();
        if ($executionRows === []) {
            throw new NotFoundException('No cron run ' . $cronExecutionId);
        }
        $output = (string)($executionRows[0]['output'] ?? '');
        $cronExecutionDetail = new CronExecutionDetail();
        $cronExecutionDetail->execution = $this->createExecutionOverviewFromRow(
            $executionRows[0],
            mb_substr($output, 0, self::OUTPUT_HEAD_CHARACTERS)
        );
        $cronExecutionDetail->output = $output;
        return $cronExecutionDetail;
    }

    /**
     * Runs per Cron per bucket over the last `$hours` hours.
     */
    public function getCronsTimeline(int $hours, ?DateTime $now = null): CronsTimeline
    {
        $now ??= new DateTime();
        $from = $this->subtractFromNow($now, sprintf('-%d hours', $hours));
        $queryBuilder = $this->createExecutionQueryBuilder()
            ->andWhere('cronExecution.executionStartedAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_MUTABLE);
        $cronExecutionOverviews = new CronExecutionOverviews();
        foreach ($queryBuilder->getQuery()->getArrayResult() as $executionRow) {
            $cronExecutionOverview = $this->createExecutionOverviewFromRow($executionRow, null);
            $cronExecutionOverviews->add($cronExecutionOverview);
        }
        return CronsTimeline::fromExecutions($from, $now, $hours, $this->findCronOverviews(), $cronExecutionOverviews);
    }

    /**
     * The console commands an admin may schedule (`#[CronSchedulable]` or {@see self::FRAMEWORK_SCHEDULABLE_COMMAND_NAMES}),
     * not hidden, sorted by name, each with the Crons that already run it.
     */
    public function getSchedulableCommands(): CronSchedulableCommands
    {
        // method-local lookup: command name => names of the Crons running it
        $cronNamesByCommandName = [];
        foreach ($this->findCronOverviews()->getElements() as $cronOverview) {
            $cronNamesByCommandName[$cronOverview->commandName][] = $cronOverview->name;
        }
        // method-local lookup: command name => schedulable command, sorted below
        $schedulableCommandsByName = [];
        foreach ($this->getConsoleApplication()->all() as $commandName => $command) {
            $cronConsoleCommand = $this->describeConsoleCommand($command);
            if (!$cronConsoleCommand->isSchedulable || $cronConsoleCommand->isHidden) {
                continue;
            }
            $cronSchedulableCommand = new CronSchedulableCommand();
            $cronSchedulableCommand->name = (string)$commandName;
            $cronSchedulableCommand->description = $cronConsoleCommand->description;
            $cronSchedulableCommand->usedByCronNames = $cronNamesByCommandName[(string)$commandName] ?? [];
            $schedulableCommandsByName[(string)$commandName] = $cronSchedulableCommand;
        }
        ksort($schedulableCommandsByName);
        $cronSchedulableCommands = new CronSchedulableCommands();
        foreach ($schedulableCommandsByName as $cronSchedulableCommand) {
            $cronSchedulableCommands->add($cronSchedulableCommand);
        }
        return $cronSchedulableCommands;
    }

    /**
     * Whether the expression is valid and its next 5 slots. An invalid expression is a result, never an exception.
     */
    public function getSchedulePreview(string $schedule): CronSchedulePreview
    {
        return CronSchedulePreview::fromSchedule($schedule);
    }

    // ----------------------------------------------------------------------------------------------- writes

    /**
     * Creates a Cron from a schedulable command. A rule of the write that fails is a BadRequestException naming the
     * field (name, description, schedule, commandName, arguments); the entity's own validation has the last word.
     * Strict INSERT: a duplicate name throws, it never overwrites the Cron that owns it.
     *
     * @throws BadRequestException
     */
    public function createCron(
        string $name,
        string $description,
        string $schedule,
        string $commandName,
        ?string $arguments,
        bool $isActive
    ): Cron {
        $name = trim($name);
        $this->assertNameIsValidAndFree($name, null);
        $schedule = $this->normalizeScheduleOrThrow($schedule);
        $commandName = trim($commandName);
        $this->assertCommandIsSchedulable($commandName);
        $cronCommandLine = new CronCommandLine($commandName, $this->normalizeArgumentsOrThrow($arguments));
        $this->assertCommandLineFits($cronCommandLine);

        $cron = new Cron();
        $cron->name = $name;
        $cron->description = trim($description);
        $cron->schedule = $schedule;
        $cron->command = $cronCommandLine->getCommand();
        $cron->isActive = $isActive;
        // a new Cron is due at the NEXT slot of its expression, never immediately
        $cron->nextExecutionScheduledAt = CronScheduledRuns::fromSchedule($schedule, 1)->first()->scheduledAt;
        $this->assertCronIsValid($cron);
        try {
            $insertedCron = $cron->insert();
        } catch (UniqueConstraintViolationException) {
            $this->throwFieldError('name', 'A cron with this name already exists.', $name);
        }
        return $insertedCron ?? $cron;
    }

    /**
     * Applies the given fields to a Cron; a null field stays as it is, `$arguments = ''` clears the arguments. A
     * changed schedule re-anchors the next slot; switching on goes through {@see Cron::activate()}. Only the changed
     * columns are written, and only the changed properties are held to the entity's constraints (a row that predates a
     * rule still accepts an unrelated change). A schedule change while the Cron runs, or while it is due and a tick is
     * in progress, is a ConflictException: the runner would advance the next slot by the old expression.
     *
     * @throws BadRequestException
     * @throws ConflictException
     */
    public function updateCron(
        Cron $cron,
        ?string $name = null,
        ?string $description = null,
        ?string $schedule = null,
        ?string $commandName = null,
        ?string $arguments = null,
        ?bool $isActive = null
    ): Cron {
        $changedPropertyNames = [];
        $storedIsActive = $cron->isActive;
        $storedNextExecutionScheduledAt = $cron->nextExecutionScheduledAt ?? null;
        $storedCommandLine = CronCommandLine::fromCommand((string)$cron->command);

        if ($name !== null && trim($name) !== $cron->name) {
            $this->assertNameIsValidAndFree(trim($name), (int)$cron->id);
            $cron->name = trim($name);
            $changedPropertyNames[] = 'name';
        }
        if ($description !== null && trim($description) !== $cron->description) {
            $cron->description = trim($description);
            $changedPropertyNames[] = 'description';
        }

        $cronCommandLine = clone $storedCommandLine;
        if ($commandName !== null && trim($commandName) !== $storedCommandLine->commandName) {
            $cronCommandLine->commandName = trim($commandName);
            $this->assertCommandIsSchedulable($cronCommandLine->commandName);
        }
        if ($arguments !== null) {
            $cronCommandLine->arguments = $this->normalizeArgumentsOrThrow($arguments);
        }
        if ($cronCommandLine->getCommand() !== $storedCommandLine->getCommand()) {
            // new arguments for a command that is not schedulable would let the admin run it with anything
            if ($cronCommandLine->commandName === $storedCommandLine->commandName) {
                $this->assertCommandIsSchedulable($cronCommandLine->commandName, 'arguments');
            }
            $this->assertCommandLineFits($cronCommandLine);
            $cron->command = $cronCommandLine->getCommand();
            $changedPropertyNames[] = 'command';
        }

        if ($isActive !== null && $isActive !== $cron->isActive) {
            $isActive ? $cron->activate() : $cron->deactivate();
            $changedPropertyNames[] = 'isActive';
            $changedPropertyNames[] = 'nextExecutionScheduledAt';
        }
        if ($schedule !== null && trim($schedule) !== $cron->schedule) {
            $this->assertNoRunCanRevertTheChange($cron, $storedIsActive, $storedNextExecutionScheduledAt);
            $cron->schedule = $this->normalizeScheduleOrThrow($schedule);
            // a re-scheduled Cron is due at the NEXT slot of its new expression; getNextScheduledExecution() would keep an old future slot
            $cron->nextExecutionScheduledAt = CronScheduledRuns::fromSchedule($cron->schedule, 1)->first()->scheduledAt;
            $changedPropertyNames[] = 'schedule';
            $changedPropertyNames[] = 'nextExecutionScheduledAt';
        }

        if ($changedPropertyNames !== []) {
            $changedPropertyNames = array_values(array_unique($changedPropertyNames));
            $this->assertCronIsValid($cron, $changedPropertyNames);
            $this->persistCronColumns($cron, ...$changedPropertyNames);
        }
        return $cron;
    }

    /**
     * Switches the Cron on and re-anchors its next slot ({@see Cron::activate()}). Idempotent.
     */
    public function activateCron(Cron $cron): Cron
    {
        if (!$cron->isActive) {
            $cron->activate();
            $this->persistCronColumns($cron, 'isActive', 'nextExecutionScheduledAt');
        }
        return $cron;
    }

    /**
     * Switches the Cron off; schedule, command and the stored slot stay ({@see Cron::deactivate()}). Idempotent.
     */
    public function deactivateCron(Cron $cron): Cron
    {
        if ($cron->isActive) {
            $cron->deactivate();
            $this->persistCronColumns($cron, 'isActive');
        }
        return $cron;
    }

    /**
     * Makes the Cron due now: the next tick of the runner (`app:crons:execute`, every minute) runs it through
     * {@see Cron::execute()} like any due Cron — execution row, closing, next slot and issues log included. The
     * command never runs in the request.
     *
     * @throws BadRequestException The command is not in this build
     * @throws ConflictException The Cron is switched off or running
     */
    public function scheduleCronToRunNow(Cron $cron): Cron
    {
        if ($this->findConsoleCommand(CronCommandLine::fromCommand((string)$cron->command)->commandName) === null) {
            throw new BadRequestException(self::COMMAND_MISSING_MESSAGE);
        }
        if (!$cron->isActive) {
            throw new ConflictException('Turn this cron on first.');
        }
        if ($this->hasRunningExecution((int)$cron->id)) {
            throw new ConflictException(self::CRON_RUNNING_MESSAGE);
        }
        $cron->nextExecutionScheduledAt = new DateTime();
        $this->persistCronColumns($cron, 'nextExecutionScheduledAt');
        return $cron;
    }

    /**
     * Deletes the Cron and its runs in one transaction; the row is locked first, so a runner inserting an execution
     * for it waits and then fails instead of leaving runs of a deleted Cron.
     *
     * @throws ConflictException The Cron is running
     * @throws NotFoundException
     */
    public function deleteCron(Cron $cron): void
    {
        $cronId = (int)$cron->id;
        EntityManagerFactory::getInstance()->wrapInTransaction(function () use ($cronId): void {
            $lockedCronRows = EntityManagerFactory::getInstance()->createQueryBuilder()
                ->select('cron.id AS id')
                ->from(DBCrons::getBaseModel(), 'cron')
                ->where('cron.id = :cronId')
                ->setParameter('cronId', $cronId)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            if ($lockedCronRows === []) {
                throw new NotFoundException('No cron ' . $cronId);
            }
            if ($this->hasRunningExecution($cronId)) {
                throw new ConflictException('This cron is running. Wait for the run to finish, then delete it.');
            }
            EntityManagerFactory::getInstance()->createQueryBuilder()
                ->delete(DBCronExecutions::getBaseModel(), 'cronExecution')
                ->where('cronExecution.cronId = :cronId')
                ->setParameter('cronId', $cronId)
                ->getQuery()
                ->execute();
            EntityManagerFactory::getInstance()->createQueryBuilder()
                ->delete(DBCrons::getBaseModel(), 'cron')
                ->where('cron.id = :cronId')
                ->setParameter('cronId', $cronId)
                ->getQuery()
                ->execute();
        });
    }

    /**
     * Loads a Cron fresh (no registry cache), so a write never starts from a stale snapshot.
     *
     * @throws NotFoundException
     */
    public function findCronOrThrowNotFound(int $cronId): Cron
    {
        /** @var CronsService $cronsService */
        $cronsService = Crons::getService();
        $cron = $cronsService->find($cronId, false);
        if (!$cron instanceof Cron) {
            throw new NotFoundException('No cron ' . $cronId);
        }
        return $cron;
    }

    // ------------------------------------------------------------------------------------------ validation

    /**
     * @throws BadRequestException
     */
    protected function assertNameIsValidAndFree(string $name, ?int $ownCronId): void
    {
        $nameLength = mb_strlen($name);
        if ($nameLength < self::NAME_MIN_LENGTH || $nameLength > self::NAME_MAX_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $this->throwFieldError(
                'name',
                sprintf('Use %d to %d lowercase letters, digits and single hyphens, for example send-reminders.', self::NAME_MIN_LENGTH, self::NAME_MAX_LENGTH),
                $name
            );
        }
        // scalar DQL, never CronsService::findByName(): the entity registry could serve a cached answer of an earlier lookup
        $cronRows = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('cron.id AS id')
            ->from(DBCrons::getBaseModel(), 'cron')
            ->where('cron.name = :name')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
        if ($cronRows !== [] && (int)$cronRows[0]['id'] !== $ownCronId) {
            $this->throwFieldError('name', 'A cron with this name already exists.', $name);
        }
    }

    /**
     * @throws BadRequestException
     */
    protected function normalizeScheduleOrThrow(string $schedule): string
    {
        $schedule = trim($schedule);
        if ($schedule === '' || !CronExpression::isValidExpression($schedule)) {
            $this->throwFieldError('schedule', CronSchedulePreview::INVALID_SCHEDULE_MESSAGE, $schedule);
        }
        return $schedule;
    }

    /**
     * The runner splits the command line on single spaces and runs it without a shell ({@see Cron::execute()}), so
     * quote characters would reach the command literally: they are refused, and whitespace collapses to single spaces.
     *
     * @return string|null The normalized arguments; null when there are none
     * @throws BadRequestException
     */
    public function normalizeArgumentsOrThrow(?string $arguments): ?string
    {
        if ($arguments === null || trim($arguments) === '') {
            return null;
        }
        if (preg_match('/[\r\n]/', $arguments) === 1) {
            $this->throwFieldError('arguments', 'Use one line.', $arguments);
        }
        if (preg_match('/["\'`]/', $arguments) === 1) {
            $this->throwFieldError('arguments', "Quotes aren't supported: the command receives them literally.", $arguments);
        }
        $arguments = trim((string)preg_replace('/\s+/', ' ', $arguments));
        $forbiddenArguments = array_values(array_unique(array_filter(
            explode(' ', $arguments),
            static fn (string $argument): bool => preg_match(self::FORBIDDEN_ARGUMENT_PATTERN, $argument) === 1
        )));
        if ($forbiddenArguments !== []) {
            $this->throwFieldError('arguments', "These arguments can't be used in a cron: " . implode(', ', $forbiddenArguments) . '.', $arguments);
        }
        if (mb_strlen($arguments) > self::ARGUMENTS_MAX_LENGTH) {
            $this->throwFieldError('arguments', sprintf('Use at most %d characters.', self::ARGUMENTS_MAX_LENGTH), $arguments);
        }
        return $arguments;
    }

    /**
     * @throws BadRequestException
     */
    public function assertCommandIsSchedulable(string $commandName, string $fieldName = 'commandName'): void
    {
        $cronConsoleCommand = $commandName === '' ? null : $this->findConsoleCommand($commandName);
        if ($cronConsoleCommand === null) {
            $this->throwFieldError($fieldName, self::COMMAND_MISSING_MESSAGE, $commandName);
        }
        if (!$cronConsoleCommand->isSchedulable) {
            $this->throwFieldError($fieldName, self::NOT_SCHEDULABLE_MESSAGE, $commandName);
        }
    }

    /**
     * @throws BadRequestException
     */
    protected function assertCommandLineFits(CronCommandLine $cronCommandLine): void
    {
        if (mb_strlen($cronCommandLine->getCommand()) > self::COMMAND_MAX_LENGTH) {
            $this->throwFieldError(
                'arguments',
                sprintf('The command and its arguments take at most %d characters together.', self::COMMAND_MAX_LENGTH),
                $cronCommandLine->arguments
            );
        }
    }

    /**
     * The entity's own constraints as the last word, like `app:crons:upsert`: `validate()` hands back TRUE, the
     * collected errors, or FALSE; it returns the instance it was GIVEN even when that stays empty, so the argument is
     * null and the result is compared against true.
     *
     * @param string[]|null $changedPropertyNames Only violations of these properties count; null = all
     * @throws BadRequestException
     */
    protected function assertCronIsValid(Cron $cron, ?array $changedPropertyNames = null): void
    {
        $validationErrors = null;
        $validation = $cron->validate($validationErrors);
        if ($validation === true) {
            return;
        }
        if ($validation instanceof ValidationErrors && $changedPropertyNames !== null) {
            $validation = $this->filterValidationErrorsOfProperties($validation, $changedPropertyNames);
            if ($validation === null) {
                return;
            }
        }
        $exceptionDetails = new ExceptionDetails();
        $exceptionDetails->addDetail('The cron is invalid.', []);
        $badRequestException = new BadRequestException('The cron is invalid.', $exceptionDetails);
        if ($validation instanceof ValidationErrors) {
            $badRequestException->validationErrors = $validation;
        }
        throw $badRequestException;
    }

    /**
     * @param string[] $propertyNames
     * @return ValidationErrors|null The violations of those properties; null when none is left
     */
    protected function filterValidationErrorsOfProperties(ValidationErrors $validationErrors, array $propertyNames): ?ValidationErrors
    {
        $filteredValidationErrors = new ValidationErrors();
        foreach ($validationErrors->getElements() as $validationError) {
            $filteredValidationError = new ValidationError($validationError->jsonPath);
            foreach ($validationError->getElements() as $validationResult) {
                if (in_array($validationResult->propertyName, $propertyNames, true)) {
                    $filteredValidationError->add($validationResult);
                }
            }
            if ($filteredValidationError->getElements() !== []) {
                $filteredValidationErrors->add($filteredValidationError);
            }
        }
        return $filteredValidationErrors->getElements() === [] ? null : $filteredValidationErrors;
    }

    /**
     * A field error: a BadRequestException whose validation errors name the field, like the entity validation does.
     *
     * @throws BadRequestException
     */
    protected function throwFieldError(string $fieldName, string $message, mixed $receivedValue): never
    {
        $exceptionDetails = new ExceptionDetails();
        $exceptionDetails->addDetail($message, ['field' => $fieldName]);
        $badRequestException = new BadRequestException($fieldName . ': ' . $message, $exceptionDetails);
        $validationError = new ValidationError('cron.' . $fieldName);
        $validationResult = new ValidationResult($fieldName, $message, $receivedValue, Cron::class);
        $validationError->add($validationResult);
        $badRequestException->validationErrors = new ValidationErrors();
        $badRequestException->validationErrors->add($validationError);
        throw $badRequestException;
    }

    /**
     * A schedule change is refused while the runner could undo it: this Cron runs, or it is due (stored state) and a
     * tick is in progress — that tick runs it and advances the slot by the expression it loaded.
     *
     * @throws ConflictException
     */
    protected function assertNoRunCanRevertTheChange(Cron $cron, bool $storedIsActive, ?DateTime $storedNextExecutionScheduledAt): void
    {
        if ($this->hasRunningExecution((int)$cron->id)) {
            throw new ConflictException(self::CRON_RUNNING_MESSAGE);
        }
        $isDue = $storedIsActive
            && $storedNextExecutionScheduledAt !== null
            && $storedNextExecutionScheduledAt->getTimestamp() <= time();
        if ($isDue && $this->hasRunningExecution(null)) {
            throw new ConflictException(self::RUNNER_ABOUT_TO_START_MESSAGE);
        }
    }

    // --------------------------------------------------------------------------------------- console commands

    /** The console application of this build — the seam a test replaces */
    protected function getConsoleApplication(): Application
    {
        return $this->consoleApplication ??= DDDService::instance()->createConsoleApplicationForCurrentKernel();
    }

    /**
     * What the console of this build says about a command name (exact name, no abbreviation); null when it does not
     * know it.
     */
    public function findConsoleCommand(string $commandName): ?CronConsoleCommand
    {
        if (array_key_exists($commandName, $this->consoleCommandsByName)) {
            return $this->consoleCommandsByName[$commandName];
        }
        $cronConsoleCommand = null;
        try {
            $consoleApplication = $this->getConsoleApplication();
            if ($consoleApplication->has($commandName)) {
                $cronConsoleCommand = $this->describeConsoleCommand($consoleApplication->get($commandName));
            }
        } catch (Throwable) {
            $cronConsoleCommand = null;
        }
        return $this->consoleCommandsByName[$commandName] = $cronConsoleCommand;
    }

    protected function describeConsoleCommand(Command $command): CronConsoleCommand
    {
        $realCommand = $command instanceof LazyCommand ? $command->getCommand() : $command;
        $commandName = (string)$realCommand->getName();
        $isSchedulable = in_array($commandName, static::FRAMEWORK_SCHEDULABLE_COMMAND_NAMES, true);
        for ($reflectionClass = new ReflectionClass($realCommand); $reflectionClass !== false && !$isSchedulable; $reflectionClass = $reflectionClass->getParentClass()) {
            $isSchedulable = $reflectionClass->getAttributes(CronSchedulable::class) !== [];
        }
        $cronConsoleCommand = new CronConsoleCommand($commandName, (string)$realCommand->getDescription(), $isSchedulable, $realCommand->isHidden());
        $this->consoleCommandsByName[$commandName] = $cronConsoleCommand;
        return $cronConsoleCommand;
    }

    // ------------------------------------------------------------------------------------------------ writes

    /**
     * Writes ONLY the named columns of the existing row — the runner writes the other columns of the same row. The
     * seam a test replaces.
     */
    protected function persistCronColumns(Cron $cron, string ...$propertyNames): void
    {
        $cron->updatePartialIgnoringRights(...$propertyNames);
    }

    // ------------------------------------------------------------------------------------------ scalar reads

    /**
     * Every Cron (or the one with `$cronId`) sorted by name, with its definition only: no runs, stats or status yet.
     */
    protected function findCronOverviews(?int $cronId = null): CronOverviews
    {
        $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select(
                'cron.id AS id',
                'cron.name AS name',
                'cron.description AS description',
                'cron.schedule AS schedule',
                'cron.command AS command',
                'cron.isActive AS isActive',
                'cron.lastExecutionStartedAt AS lastExecutionStartedAt',
                'cron.nextExecutionScheduledAt AS nextExecutionScheduledAt',
                'cron.created AS created',
                'cron.updated AS updated'
            )
            ->from(DBCrons::getBaseModel(), 'cron')
            ->orderBy('cron.name', 'ASC');
        if ($cronId !== null) {
            $queryBuilder->andWhere('cron.id = :cronId')->setParameter('cronId', $cronId);
        }
        $cronOverviews = new CronOverviews();
        foreach ($queryBuilder->getQuery()->getArrayResult() as $cronRow) {
            $cronOverview = new CronOverview();
            $cronOverview->id = (int)$cronRow['id'];
            $cronOverview->name = (string)$cronRow['name'];
            $cronOverview->description = (string)$cronRow['description'];
            $cronOverview->schedule = (string)$cronRow['schedule'];
            $cronOverview->command = (string)$cronRow['command'];
            $cronCommandLine = CronCommandLine::fromCommand($cronOverview->command);
            $cronOverview->commandName = $cronCommandLine->commandName;
            $cronOverview->arguments = $cronCommandLine->arguments;
            $cronOverview->isActive = (bool)$cronRow['isActive'];
            $cronOverview->lastExecutionStartedAt = $this->convertToDateTime($cronRow['lastExecutionStartedAt'] ?? null);
            $cronOverview->nextExecutionScheduledAt = $this->convertToDateTime($cronRow['nextExecutionScheduledAt'] ?? null);
            $cronOverview->created = $this->convertToDateTime($cronRow['created'] ?? null);
            $cronOverview->updated = $this->convertToDateTime($cronRow['updated'] ?? null);
            $cronOverviews->add($cronOverview);
        }
        return $cronOverviews;
    }

    /**
     * Adds the command check, the newest runs, the 24-hour stats and the status to a Cron read by {@see self::findCronOverviews()}.
     */
    protected function completeCronOverview(
        CronOverview $cronOverview,
        bool $isRunning,
        CronExecutionOverviews $endedExecutionsOf24Hours,
        DateTime $now
    ): void {
        $cronConsoleCommand = $this->findConsoleCommand($cronOverview->commandName);
        $cronOverview->commandExists = $cronConsoleCommand !== null;
        $cronOverview->commandSchedulable = $cronConsoleCommand !== null && $cronConsoleCommand->isSchedulable;
        $cronOverview->recentExecutions = $this->findExecutionOverviews($cronOverview->id, null, null, self::RECENT_EXECUTIONS_LIMIT, 0, true);
        $cronOverview->lastExecution = $cronOverview->recentExecutions->first();
        $cronOverview->stats24h = CronRunStatistics::fromExecutions($endedExecutionsOf24Hours);
        $cronOverview->status = $cronOverview->resolveStatus($isRunning, $now);
    }

    /**
     * The runs list columns. The output is never selected whole: its length, and the first characters where a preview
     * is shown.
     */
    protected function createExecutionQueryBuilder(): QueryBuilder
    {
        return EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select(
                'cronExecution.id AS id',
                'cronExecution.cronId AS cronId',
                'parentCron.name AS cronName',
                'cronExecution.executionStartedAt AS startedAt',
                'cronExecution.executionEndedAt AS endedAt',
                'cronExecution.state AS state',
                'cronExecution.executionState AS executionState',
                'COALESCE(LENGTH(cronExecution.output), 0) AS outputLength'
            )
            ->from(DBCronExecutions::getBaseModel(), 'cronExecution')
            ->join('cronExecution.cron', 'parentCron');
    }

    protected function applyExecutionFilters(QueryBuilder $queryBuilder, ?int $cronId, ?string $state, ?string $executionState): void
    {
        if ($cronId !== null) {
            $queryBuilder->andWhere('cronExecution.cronId = :cronId')->setParameter('cronId', $cronId);
        }
        if ($state !== null) {
            $queryBuilder->andWhere('cronExecution.state = :state')->setParameter('state', $state);
        }
        if ($executionState !== null) {
            $queryBuilder->andWhere('cronExecution.executionState = :executionState')->setParameter('executionState', $executionState);
        }
    }

    /**
     * Runs, newest first.
     */
    protected function findExecutionOverviews(
        ?int $cronId,
        ?string $state,
        ?string $executionState,
        int $limit,
        int $offset,
        bool $withOutputPreview
    ): CronExecutionOverviews {
        $queryBuilder = $this->createExecutionQueryBuilder();
        if ($withOutputPreview) {
            $queryBuilder->addSelect(sprintf('SUBSTRING(cronExecution.output, 1, %d) AS outputHead', self::OUTPUT_HEAD_CHARACTERS));
        }
        $this->applyExecutionFilters($queryBuilder, $cronId, $state, $executionState);
        $queryBuilder->orderBy('cronExecution.executionStartedAt', 'DESC')
            ->addOrderBy('cronExecution.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $cronExecutionOverviews = new CronExecutionOverviews();
        foreach ($queryBuilder->getQuery()->getArrayResult() as $executionRow) {
            $cronExecutionOverview = $this->createExecutionOverviewFromRow($executionRow, $withOutputPreview ? (string)($executionRow['outputHead'] ?? '') : null);
            $cronExecutionOverviews->add($cronExecutionOverview);
        }
        return $cronExecutionOverviews;
    }

    protected function countExecutions(?int $cronId, ?string $state, ?string $executionState): int
    {
        $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('COUNT(cronExecution.id)')
            ->from(DBCronExecutions::getBaseModel(), 'cronExecution');
        $this->applyExecutionFilters($queryBuilder, $cronId, $state, $executionState);
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Ended runs that started since `$since` (of one Cron, or of all): the input of the stats.
     */
    protected function findEndedExecutionsStartedSince(DateTime $since, ?int $cronId = null): CronExecutionOverviews
    {
        $queryBuilder = $this->createExecutionQueryBuilder()
            ->andWhere('cronExecution.executionStartedAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_MUTABLE);
        $this->applyExecutionFilters($queryBuilder, $cronId, CronExecution::STATE_ENDED, null);
        $cronExecutionOverviews = new CronExecutionOverviews();
        foreach ($queryBuilder->getQuery()->getArrayResult() as $executionRow) {
            $cronExecutionOverview = $this->createExecutionOverviewFromRow($executionRow, null);
            $cronExecutionOverviews->add($cronExecutionOverview);
        }
        return $cronExecutionOverviews;
    }

    /**
     * @return int[] Ids of the Crons with an execution in state RUNNING
     */
    protected function findRunningCronIds(): array
    {
        $runningExecutionRows = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('cronExecution.cronId AS cronId')
            ->distinct()
            ->from(DBCronExecutions::getBaseModel(), 'cronExecution')
            ->where('cronExecution.state = :stateRunning')
            ->setParameter('stateRunning', CronExecution::STATE_RUNNING)
            ->getQuery()
            ->getArrayResult();
        return array_map(static fn (array $runningExecutionRow): int => (int)$runningExecutionRow['cronId'], $runningExecutionRows);
    }

    /**
     * Whether an execution in state RUNNING exists: of this Cron, or of any Cron when `$cronId` is null.
     */
    protected function hasRunningExecution(?int $cronId): bool
    {
        $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('COUNT(cronExecution.id)')
            ->from(DBCronExecutions::getBaseModel(), 'cronExecution')
            ->where('cronExecution.state = :stateRunning')
            ->setParameter('stateRunning', CronExecution::STATE_RUNNING);
        if ($cronId !== null) {
            $queryBuilder->andWhere('cronExecution.cronId = :cronId')->setParameter('cronId', $cronId);
        }
        return (int)$queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @param array<string, mixed> $executionRow A scalar row of {@see self::createExecutionQueryBuilder()}
     * @param string|null $outputHead The output's first characters; null when the read carries no preview
     */
    protected function createExecutionOverviewFromRow(array $executionRow, ?string $outputHead): CronExecutionOverview
    {
        $startedAt = $this->convertToDateTime($executionRow['startedAt'] ?? null) ?? new DateTime();
        $isEnded = ($executionRow['state'] ?? null) === CronExecution::STATE_ENDED;
        $endedAt = $isEnded ? $this->convertToDateTime($executionRow['endedAt'] ?? null) : null;
        $executionState = $executionRow['executionState'] ?? null;

        $cronExecutionOverview = new CronExecutionOverview();
        $cronExecutionOverview->id = (int)$executionRow['id'];
        $cronExecutionOverview->cronId = (int)$executionRow['cronId'];
        $cronExecutionOverview->cronName = (string)($executionRow['cronName'] ?? '');
        $cronExecutionOverview->startedAt = $startedAt;
        $cronExecutionOverview->endedAt = $endedAt;
        $cronExecutionOverview->durationMs = CronExecutionOverview::calculateDurationMs($startedAt, $endedAt);
        $cronExecutionOverview->state = $isEnded ? CronExecution::STATE_ENDED : CronExecution::STATE_RUNNING;
        $cronExecutionOverview->executionState = $isEnded
            && in_array($executionState, [CronExecution::EXECUTION_STATE_SUCCESSFUL, CronExecution::EXECUTION_STATE_FAILED], true)
            ? (string)$executionState
            : null;
        $cronExecutionOverview->outputPreview = CronExecutionOverview::extractOutputPreview($outputHead);
        $cronExecutionOverview->outputLength = (int)($executionRow['outputLength'] ?? 0);
        return $cronExecutionOverview;
    }

    protected function subtractFromNow(DateTime $now, string $modifier): DateTime
    {
        return (clone $now)->modify($modifier);
    }

    protected function convertToDateTime(mixed $value): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            return DateTime::fromTimestamp($value->getTimestamp());
        }
        if (is_string($value) && $value !== '') {
            return DateTime::fromTimestamp((new \DateTimeImmutable($value))->getTimestamp());
        }
        return null;
    }
}
