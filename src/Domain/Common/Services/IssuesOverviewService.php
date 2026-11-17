<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DateTimeZone;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroups;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Domain\Common\Entities\Issues\Overview\IssueEventDetail;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupDetail;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummaries;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummary;
use DDD\Domain\Common\Entities\Issues\Overview\IssuesTrendSelection;
use DDD\Domain\Common\Entities\Issues\Overview\IssueSummaries;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use DDD\Symfony\Commands\Common\Issues\IssuesShow;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The issues registry for an admin screen ({@see \DDD\Presentation\Api\Admin\Common\Issues\IssuesControllerTrait}):
 * what {@see IssueGroupsService}, {@see IssuesTrendService} and {@see IssuesRegistryJobService} do not offer on their
 * own —
 *
 * - the environment switch: the known environments, the default one and "all" ({@see self::ENVIRONMENT_ALL});
 * - the list with search and paging, and idempotent status changes;
 * - the trend of an environment, of one group, or of exactly the groups a list filter selects (their trends summed);
 *   a log backend that fails or is not configured degrades to an all-zero series with a `trendError`, never an error;
 * - the details of a group: its issues, its newest events (typed, client frames resolved through the source maps) and
 *   the agent JSON, from one in-process run of `app:issues:show`;
 * - the readable entry point of a group (`POST /api/admin/orders`, `SendEmailMessage (send_email)`, `app:x:y`).
 *
 * Access is decided by the mounting controller; the registry repositories are admin-only on top of that.
 */
class IssuesOverviewService extends Service
{
    /** Upper bound of one list page */
    public const int MAX_LIST_LIMIT = 200;

    /** Newest events shown in the details */
    public const int DETAIL_EVENT_LIMIT = 20;

    /** Events in the agent JSON: the default of `app:issues:show --events` */
    public const int AGENT_JSON_EVENT_LIMIT = 3;

    /** Pretty JSON exactly as the issues commands print it */
    public const int AGENT_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Trend window when the request names no start */
    public const int TREND_DEFAULT_WINDOW_SECONDS = 24 * 3600;

    /** Longest trend window a request may ask for */
    public const int TREND_MAX_WINDOW_SECONDS = 31 * 24 * 3600;

    /**
     * The environment value that selects every known environment (list, counts, trend). Never a registry environment
     * itself: the group endpoints (path environment) do not accept it.
     */
    public const string ENVIRONMENT_ALL = 'all';

    /**
     * Groups whose trends are summed for a filtered trend at most (the log backend answers one group per query): the
     * groups with the most events; the rest is left out and `isTruncated` says so.
     */
    public const int TREND_MAX_SUMMED_GROUPS = 50;

    /** Time budget of the per-group trend queries of one filtered trend; groups beyond it are left out (truncated) */
    public const float TREND_SUMMING_BUDGET_SECONDS = 15.0;

    /** Entry point prefix of a failed worker message: `MessageHandler:<transport>` */
    public const string ENTRY_POINT_PREFIX_MESSAGE_HANDLER = 'MessageHandler:';

    /** Entry point prefix of a console command: `CLI:<command>` */
    public const string ENTRY_POINT_PREFIX_CLI = 'CLI:';

    /** Entry point of a request no route matched */
    public const string ENTRY_POINT_UNKNOWN = 'N/A';

    /**
     * The trend window of a request: ISO 8601 bounds, an omitted end = now, an omitted start =
     * {@see self::TREND_DEFAULT_WINDOW_SECONDS} before the end. Both come back in UTC.
     *
     * @throws BadRequestException A bound is not a date-time, the end is not after the start, or the window is longer
     *                             than {@see self::TREND_MAX_WINDOW_SECONDS}
     */
    public function resolveTrendInterval(?string $from, ?string $to): TimeInterval
    {
        $endDate = $this->parseTrendBound('to', $to) ?? DateTime::fromTimestamp(time());
        $startDate = $this->parseTrendBound('from', $from)
            ?? DateTime::fromTimestamp($endDate->getTimestamp() - self::TREND_DEFAULT_WINDOW_SECONDS);
        $windowSeconds = $endDate->getTimestamp() - $startDate->getTimestamp();
        if ($windowSeconds <= 0) {
            throw new BadRequestException('The trend window end ("to") must be after its start ("from").');
        }
        if ($windowSeconds > self::TREND_MAX_WINDOW_SECONDS) {
            throw new BadRequestException(sprintf(
                'The trend window is at most %d days.',
                intdiv(self::TREND_MAX_WINDOW_SECONDS, 86400)
            ));
        }
        return new TimeInterval($startDate, $endDate);
    }

    /**
     * The error events of one environment (or one of its groups), or of every watched environment summed
     * ({@see self::ENVIRONMENT_ALL}), per bucket (step chosen by the window, cached 60 s by the trend service). Counts
     * every error event of the scope in the log backend, also of groups the registry has not seen yet. Never throws for
     * the log backend: an environment no backend watches, or a backend that fails, yields the gap-free series with
     * every point 0 and a `trendError`; for "all", environments that fail are left out of the sum (`backend_partial`).
     */
    public function findTrend(string $environment, ?string $groupHash, TimeInterval $timeInterval): IssuesTrendSelection
    {
        $isAllEnvironments = $environment === self::ENVIRONMENT_ALL;
        $watchedEnvironments = $this->getWatchedEnvironments();
        $trendEnvironments = $isAllEnvironments
            ? $watchedEnvironments
            : array_values(array_intersect([$environment], $watchedEnvironments));
        $seriesName = $groupHash ?? ($environment !== '' ? $environment : IssuesTrendService::ALL_ERRORS_NAME);
        if ($trendEnvironments === []) {
            return IssuesTrendSelection::fromSeries(
                $this->buildEmptyTrendSeries($seriesName, $timeInterval),
                IssuesTrendSelection::TREND_ERROR_BACKEND_NOT_CONFIGURED
            );
        }
        try {
            $issuesTrendResult = $this->getIssuesTrendService()->findTrendWithFailedEnvironments(
                $isAllEnvironments ? null : $environment,
                $groupHash,
                $timeInterval
            );
        } catch (Throwable $throwable) {
            $this->logBackendFailure($throwable, 'issuesOverviewTrend', $environment, $groupHash);
            return IssuesTrendSelection::fromSeries(
                $this->buildEmptyTrendSeries($seriesName, $timeInterval),
                IssuesTrendSelection::TREND_ERROR_BACKEND_UNAVAILABLE
            );
        }
        $failedEnvironmentCount = $issuesTrendResult->failedEnvironments->count();
        foreach ($issuesTrendResult->failedEnvironments->getElements() as $issuesEnvironmentFailure) {
            $this->logBackendFailure(
                new InternalErrorException($issuesEnvironmentFailure->error),
                'issuesOverviewTrend',
                $issuesEnvironmentFailure->environment,
                $groupHash
            );
        }
        if ($failedEnvironmentCount >= count($trendEnvironments)) {
            return IssuesTrendSelection::fromSeries(
                $this->buildEmptyTrendSeries($seriesName, $timeInterval),
                IssuesTrendSelection::TREND_ERROR_BACKEND_UNAVAILABLE
            );
        }
        return IssuesTrendSelection::fromSeries(
            $issuesTrendResult->trend,
            $failedEnvironmentCount > 0 ? IssuesTrendSelection::TREND_ERROR_BACKEND_PARTIAL : null
        );
    }

    /**
     * The trend of exactly the groups the list shows for these filters: the registry selects the groups, the log
     * backend is asked per group (it filters by one group hash per query) and the trends are summed.
     *
     * - Only groups with an event inside the window (last seen at or after its start, first seen at or before its end)
     *   can add events, so only those are candidates (`matchingGroupCount`).
     * - At most {@see self::TREND_MAX_SUMMED_GROUPS} candidates are summed, most events first, within
     *   {@see self::TREND_SUMMING_BUDGET_SECONDS}; `isTruncated` says when candidates were left out.
     * - Groups of environments no log backend watches cannot be read; with none left, `backend_not_configured`. A
     *   group whose backend fails is left out (`backend_partial`, or `backend_unavailable` when all failed).
     *
     * @param string $seriesName The series name: the resolved environment, or "all"
     * @param string[] $environments The environments to select groups from ({@see self::resolveEnvironmentsToQuery()})
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findTrendOfFilteredGroups(
        string $seriesName,
        array $environments,
        ?string $status,
        ?string $search,
        ?string $section,
        ?string $release,
        TimeInterval $timeInterval
    ): IssuesTrendSelection {
        $watchedEnvironments = array_values(array_intersect($environments, $this->getWatchedEnvironments()));
        $seriesName = $seriesName !== '' ? $seriesName : IssuesTrendService::ALL_ERRORS_NAME;
        $stepSeconds = self::resolveTrendStepSeconds($timeInterval);
        $issuesTrendSelection = IssuesTrendSelection::fromSeries(
            $this->buildEmptyTrendSeries($seriesName, $timeInterval),
            IssuesTrendSelection::TREND_ERROR_BACKEND_NOT_CONFIGURED
        );
        $issuesTrendSelection->isFilteredByGroups = true;
        $issuesTrendSelection->matchingGroupCount = 0;
        $issuesTrendSelection->summedGroupCount = 0;
        if ($environments === []) {
            return $issuesTrendSelection;
        }

        $issueGroupsService = $this->getIssueGroupsService();
        $matchingGroupCount = $issueGroupsService->countGroups(new IssueGroupsFilter(
            status: $status,
            release: $release,
            section: $section,
            environments: array_values($environments),
            search: $search,
            lastSeenSince: $timeInterval->startDate,
            firstSeenUntil: $timeInterval->endDate,
        ));
        $issuesTrendSelection->matchingGroupCount = $matchingGroupCount;
        $issuesTrendSelection->isTruncated = $matchingGroupCount > 0;
        if ($watchedEnvironments === []) {
            return $issuesTrendSelection;
        }

        $candidateIssueGroups = $issueGroupsService->listGroups(new IssueGroupsFilter(
            status: $status,
            release: $release,
            section: $section,
            limit: self::TREND_MAX_SUMMED_GROUPS,
            environments: $watchedEnvironments,
            search: $search,
            lastSeenSince: $timeInterval->startDate,
            firstSeenUntil: $timeInterval->endDate,
            order: IssueGroupsFilter::ORDER_MOST_EVENTS_FIRST,
        ))->getElements();
        if ($candidateIssueGroups === []) {
            // no matching group of a watched environment had events in the window: a real, all-zero trend unless the
            // matching groups all belong to environments no log backend watches
            $issuesTrendSelection->trendError = $matchingGroupCount > 0
                ? IssuesTrendSelection::TREND_ERROR_BACKEND_NOT_CONFIGURED
                : null;
            return $issuesTrendSelection;
        }

        $valuesByTimestamp = [];
        $summedGroupCount = 0;
        $failedGroupCount = 0;
        $startedAt = microtime(true);
        $issuesTrendService = $this->getIssuesTrendService();
        foreach ($candidateIssueGroups as $issueGroup) {
            if (microtime(true) - $startedAt > self::TREND_SUMMING_BUDGET_SECONDS) {
                break;
            }
            $groupEnvironment = (string)$issueGroup->environment;
            try {
                $issuesTrendResult = $issuesTrendService->findTrendWithFailedEnvironments(
                    $groupEnvironment,
                    $issueGroup->groupHash,
                    $timeInterval,
                    $stepSeconds
                );
            } catch (Throwable $throwable) {
                $this->logBackendFailure($throwable, 'issuesOverviewTrend', $groupEnvironment, $issueGroup->groupHash);
                $failedGroupCount++;
                continue;
            }
            $issuesEnvironmentFailure = $issuesTrendResult->failedEnvironments->first();
            if ($issuesEnvironmentFailure !== null) {
                $this->logBackendFailure(
                    new InternalErrorException($issuesEnvironmentFailure->error),
                    'issuesOverviewTrend',
                    $groupEnvironment,
                    $issueGroup->groupHash
                );
                $failedGroupCount++;
                continue;
            }
            foreach ($issuesTrendResult->trend->getValuesByTimestamp() as $timestamp => $value) {
                $valuesByTimestamp[$timestamp] = ($valuesByTimestamp[$timestamp] ?? 0) + $value;
            }
            $summedGroupCount++;
        }

        $issuesTrendSelection->series = DateTimeValueSequence::fromValuesByTimestamp(
            $seriesName,
            $timeInterval->startDate,
            $timeInterval->endDate,
            $stepSeconds,
            $valuesByTimestamp
        );
        $issuesTrendSelection->trendError = null;
        if ($failedGroupCount > 0) {
            $issuesTrendSelection->trendError = $summedGroupCount === 0
                ? IssuesTrendSelection::TREND_ERROR_BACKEND_UNAVAILABLE
                : IssuesTrendSelection::TREND_ERROR_BACKEND_PARTIAL;
        }
        $issuesTrendSelection->summedGroupCount = $summedGroupCount;
        $issuesTrendSelection->isTruncated = $summedGroupCount + $failedGroupCount < $matchingGroupCount;
        return $issuesTrendSelection;
    }

    /** The bucket size the trend service picks for the window */
    public static function resolveTrendStepSeconds(TimeInterval $timeInterval): int
    {
        return IssuesTrendService::limitStepSeconds(
            $timeInterval,
            IssuesTrendService::resolveAutoStepSeconds($timeInterval)
        );
    }

    /**
     * The environments a log backend watches (`queryEnvironments`); none without a query backend.
     *
     * @return string[]
     */
    public function getWatchedEnvironments(): array
    {
        $issuesRegistryJobService = $this->getIssuesRegistryJobService();
        if (!$issuesRegistryJobService->getSettings()->hasQueryBackend()) {
            return [];
        }
        return $issuesRegistryJobService->getEnvironmentNames();
    }

    /**
     * The environments the screen can switch to: the configured ones (in their order), then any other environment
     * that still has groups in the registry.
     *
     * @param IssueGroupStatusCounts|null $issueGroupStatusCounts The registry counts when the caller has them already
     * @return string[]
     */
    public function getKnownEnvironments(?IssueGroupStatusCounts $issueGroupStatusCounts = null): array
    {
        $issueGroupStatusCounts ??= $this->getIssueGroupsService()->countGroupsByEnvironmentAndStatus();
        return array_values(array_unique(array_merge(
            $this->getIssuesRegistryJobService()->getEnvironmentNames(),
            $issueGroupStatusCounts->getEnvironments()
        )));
    }

    /**
     * @throws BadRequestException The environment is neither configured nor holds any group
     */
    public function assertKnownEnvironment(string $environment, ?IssueGroupStatusCounts $issueGroupStatusCounts = null): void
    {
        $knownEnvironments = $this->getKnownEnvironments($issueGroupStatusCounts);
        if (!in_array($environment, $knownEnvironments, true)) {
            throw $this->createUnknownEnvironmentException($environment, $knownEnvironments);
        }
    }

    /**
     * Resolves a request's environment: a missing or empty value defaults to the first known environment
     * ({@see self::getKnownEnvironments()}) so a client that knows no environment yet can still open the screen; an
     * explicitly sent value that the registry does not know answers 400. {@see self::ENVIRONMENT_ALL} (any case) is
     * accepted. Returns `''` only when the registry knows no environment at all (nothing configured, no group yet).
     *
     * @throws BadRequestException The explicitly sent environment is neither configured nor holds any group
     */
    public function resolveEnvironment(?string $environment, ?IssueGroupStatusCounts $issueGroupStatusCounts = null): string
    {
        $knownEnvironments = $this->getKnownEnvironments($issueGroupStatusCounts);
        if ($environment === null || trim($environment) === '') {
            return $knownEnvironments[0] ?? '';
        }
        if (self::isAllEnvironments($environment)) {
            return self::ENVIRONMENT_ALL;
        }
        if (!in_array($environment, $knownEnvironments, true)) {
            throw $this->createUnknownEnvironmentException($environment, $knownEnvironments);
        }
        return $environment;
    }

    public static function isAllEnvironments(?string $environment): bool
    {
        return $environment !== null && strtolower(trim($environment)) === self::ENVIRONMENT_ALL;
    }

    /**
     * The registry environments a resolved environment stands for: itself, or for {@see self::ENVIRONMENT_ALL} every
     * known environment (exactly the switch's options).
     *
     * @param IssueGroupStatusCounts|null $issueGroupStatusCounts The registry counts when the caller has them already
     * @return string[]
     */
    public function resolveEnvironmentsToQuery(string $resolvedEnvironment, ?IssueGroupStatusCounts $issueGroupStatusCounts = null): array
    {
        if ($resolvedEnvironment === self::ENVIRONMENT_ALL) {
            return $this->getKnownEnvironments($issueGroupStatusCounts);
        }
        return $resolvedEnvironment !== '' ? [$resolvedEnvironment] : [];
    }

    /**
     * One page of groups of the given environments, last seen first. `$search` matches message, exception type and
     * origin case-insensitively; `$status` null = every status; the page size is capped at {@see self::MAX_LIST_LIMIT}.
     * No environment = no group.
     *
     * @param string[] $environments
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findGroupsForList(
        array $environments,
        ?string $status,
        ?string $search,
        ?string $section,
        ?string $release,
        int $limit,
        int $offset
    ): IssueGroups {
        return $this->getIssueGroupsService()->listGroups(new IssueGroupsFilter(
            status: $status,
            release: $release,
            section: $section,
            limit: self::capListLimit($limit),
            environments: array_values($environments),
            search: $search,
            offset: max(0, $offset),
        ));
    }

    /** The groups with their readable entry points */
    public function summariseIssueGroups(IssueGroups $issueGroups): IssueGroupSummaries
    {
        $issueGroupSummaries = new IssueGroupSummaries();
        foreach ($issueGroups->getElements() as $issueGroup) {
            $issueGroupSummary = IssueGroupSummary::fromIssueGroup($issueGroup, $this->describeEntryPoint($issueGroup));
            $issueGroupSummaries->add($issueGroupSummary);
        }
        return $issueGroupSummaries;
    }

    /** Whether the list filters select a subset of the groups (anything beyond the environment and every status) */
    public static function hasGroupFilter(?string $status, ?string $search, ?string $section, ?string $release): bool
    {
        return $status !== null
            || ($search !== null && trim($search) !== '')
            || ($section !== null && trim($section) !== '')
            || ($release !== null && trim($release) !== '');
    }

    public static function capListLimit(int $limit): int
    {
        return min(self::MAX_LIST_LIMIT, max(1, $limit));
    }

    /** Resolves the group; a group that is already resolved comes back unchanged (its resolvedAt is kept) */
    public function resolveGroup(IssueGroup $issueGroup): IssueGroup
    {
        return $issueGroup->status === IssueGroup::STATUS_RESOLVED
            ? $issueGroup
            : $this->getIssueGroupsService()->resolve($issueGroup);
    }

    /** Ignores the group; a group that is already ignored comes back unchanged */
    public function ignoreGroup(IssueGroup $issueGroup): IssueGroup
    {
        return $issueGroup->status === IssueGroup::STATUS_IGNORED
            ? $issueGroup
            : $this->getIssueGroupsService()->ignore($issueGroup);
    }

    /** Reopens the group (status OPEN, resolvedAt cleared); an open group comes back unchanged */
    public function reopenGroup(IssueGroup $issueGroup): IssueGroup
    {
        return $issueGroup->status === IssueGroup::STATUS_OPEN
            ? $issueGroup
            : $this->getIssueGroupsService()->reopen($issueGroup);
    }

    /**
     * The details of a group, from ONE in-process run of `app:issues:show` (its JSON is the agent payload, so the run
     * is reused rather than rebuilt):
     *
     * - `events`: up to {@see self::DETAIL_EVENT_LIMIT} events, newest first, the stack trace cut at the issues log's
     *   event cap; a client section (WEB, WEB_SERVER, MOBILE) runs with `--resolve`, so its frames carry the original
     *   positions where a source map of the release exists;
     * - `eventsError`: why the log backend could not be read, or null;
     * - `agentJson`: the command's JSON with the events cut to its default (3), i.e. byte for byte what
     *   `app:issues:show --environment=X <hash> --format=json` prints.
     *
     * Never throws for an unreachable backend: the failure is logged (WARNING) and named in `eventsError`.
     */
    public function findIssueGroupDetail(IssueGroup $issueGroup): IssueGroupDetail
    {
        $issueGroupDetail = new IssueGroupDetail();
        $issueGroupDetail->group = IssueGroupSummary::fromIssueGroup($issueGroup, $this->describeEntryPoint($issueGroup));
        $issueGroupDetail->issues = IssueSummaries::fromIssues($issueGroup->issues);
        $issueGroupDetail->logBackendUrl = $this->getIssuesRegistryJobService()->getSettings()->getAlertLink(
            $issueGroup->groupHash,
            $issueGroup->environment
        );

        $bufferedOutput = new BufferedOutput();
        $commandArguments = [
            'groupHash' => $issueGroup->groupHash,
            '--environment' => (string)$issueGroup->environment,
            '--events' => (string)self::DETAIL_EVENT_LIMIT,
            '--format' => 'json',
        ];
        if (in_array($issueGroup->section, IssuesLogService::CLIENT_SECTIONS, true)) {
            $commandArguments['--resolve'] = true;
        }
        $commandInput = new ArrayInput($commandArguments);
        $commandInput->setInteractive(false);
        try {
            $exitCode = $this->createIssuesShowCommand()->run($commandInput, $bufferedOutput);
        } catch (Throwable $throwable) {
            $this->logBackendFailure($throwable, 'issuesOverviewDetails', (string)$issueGroup->environment, $issueGroup->groupHash);
            return $this->applyEventsError($issueGroupDetail, $issueGroup, 'The issue details could not be read: ' . $throwable->getMessage());
        }
        $commandOutput = $bufferedOutput->fetch();
        $showPayload = json_decode($commandOutput);
        if ($exitCode !== Command::SUCCESS || !$showPayload instanceof stdClass) {
            return $this->applyEventsError($issueGroupDetail, $issueGroup, 'The issue details could not be read: ' . trim($commandOutput));
        }

        $eventPayloads = is_array($showPayload->events ?? null) ? $showPayload->events : [];
        $eventsError = isset($showPayload->eventsError) && is_string($showPayload->eventsError) ? $showPayload->eventsError : null;
        if ($eventsError === null && !$this->getIssuesRegistryJobService()->getSettings()->hasQueryBackend()) {
            $eventsError = 'No log backend is configured (ISSUES_QUERY_BACKEND), so recent events cannot be shown.';
        }
        $maxEventBytes = $this->getMaxEventBytes();
        foreach ($eventPayloads as $eventPayload) {
            $issueEventDetail = IssueEventDetail::fromEventPayload(
                (array)json_decode((string)json_encode($eventPayload), true),
                $maxEventBytes
            );
            $issueGroupDetail->events->add($issueEventDetail);
        }
        $issueGroupDetail->eventsError = $eventsError;
        $showPayload->events = array_slice($eventPayloads, 0, self::AGENT_JSON_EVENT_LIMIT);
        $issueGroupDetail->agentJson = (string)json_encode($showPayload, self::AGENT_JSON_FLAGS);
        return $issueGroupDetail;
    }

    /**
     * The group's entry point as people read it, from what the registry stores (its entry points and the slug of the
     * sample event): `POST /api/admin/orders` (HTTP), the message class and its transport `SendEmailMessage
     * (send_email)` or the transport alone when several messages share it (MESSENGER), the command `app:crons:execute`
     * (CLI), the page route `/orders/[orderId]` (WEB, WEB_SERVER, MOBILE). Null when the group has no entry point or it
     * is unknown (`N/A`: a request no route matched).
     */
    public function describeEntryPoint(IssueGroup $issueGroup): ?string
    {
        $entryPoint = self::selectPrimaryEntryPoint($issueGroup);
        if ($entryPoint === null) {
            return null;
        }
        if (str_starts_with($entryPoint, self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER)) {
            $transportName = substr($entryPoint, strlen(self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER));
            return self::formatMessengerEntryPoint($transportName, $this->findMessageClassesRoutedToTransport(trim($transportName)));
        }
        return self::formatEntryPoint($entryPoint);
    }

    /**
     * The message classes the Messenger routing sends to the transport (by their own routing entry).
     *
     * @return string[]
     */
    public function findMessageClassesRoutedToTransport(string $transportName): array
    {
        if ($transportName === '') {
            return [];
        }
        try {
            /** @var WorkerTransportsService|null $workerTransportsService */
            $workerTransportsService = DDDService::instance()->getService(WorkerTransportsService::class);
        } catch (Throwable) {
            return [];
        }
        if ($workerTransportsService === null) {
            return [];
        }
        $routedMessageClasses = [];
        foreach ($workerTransportsService->getRoutedMessageClasses() as $messageClass) {
            if ($messageClass !== '*'
                && in_array($transportName, $workerTransportsService->getTransportNamesForMessageClass($messageClass), true)) {
                $routedMessageClasses[] = $messageClass;
            }
        }
        return $routedMessageClasses;
    }

    /** The transport of a group reached through a worker message (`MessageHandler:<transport>`), else null */
    public static function findMessageHandlerTransport(IssueGroup $issueGroup): ?string
    {
        $entryPoint = self::selectPrimaryEntryPoint($issueGroup);
        if ($entryPoint === null || !str_starts_with($entryPoint, self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER)) {
            return null;
        }
        $transportName = trim(substr($entryPoint, strlen(self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER)));
        return $transportName !== '' ? $transportName : null;
    }

    /**
     * The entry point the sample event came through (the slug starts with it, for HTTP with the short controller name),
     * else the first one the group was reached through, else the slug's own.
     */
    public static function selectPrimaryEntryPoint(IssueGroup $issueGroup): ?string
    {
        $slugEntryPoint = $issueGroup->slug !== null ? trim(explode('|', $issueGroup->slug, 2)[0]) : '';
        $entryPointNames = [];
        foreach ($issueGroup->entryPoints?->getElements() ?? [] as $issueEntryPoint) {
            if ($issueEntryPoint->name !== '') {
                $entryPointNames[] = $issueEntryPoint->name;
            }
        }
        if ($slugEntryPoint !== '') {
            foreach ($entryPointNames as $entryPointName) {
                if ($entryPointName === $slugEntryPoint || str_ends_with($entryPointName, '\\' . $slugEntryPoint)) {
                    return $entryPointName;
                }
            }
        }
        return $entryPointNames[0] ?? ($slugEntryPoint !== '' ? $slugEntryPoint : null);
    }

    /**
     * An entry point other than a worker message, readable (pure):
     * - route name `<Root>\Presentation\Api\Admin\…\XController:action__POST:/path` → `POST /api/admin/path` (the
     *   audience prefix follows the controller's `Presentation\Api\<Audience>` namespace; a route outside it keeps its path);
     * - an invokable controller's `GET:/path` → `GET /path`;
     * - `CLI:app:crons:execute` → `app:crons:execute`;
     * - `WEB:/orders/[orderId]` (also WEB_SERVER, MOBILE) → `/orders/[orderId]`;
     * - `N/A` or empty → null; anything else as it is.
     */
    public static function formatEntryPoint(string $entryPoint): ?string
    {
        $entryPoint = trim($entryPoint);
        if ($entryPoint === '' || $entryPoint === self::ENTRY_POINT_UNKNOWN) {
            return null;
        }
        if (str_starts_with($entryPoint, self::ENTRY_POINT_PREFIX_CLI)) {
            return substr($entryPoint, strlen(self::ENTRY_POINT_PREFIX_CLI));
        }
        if (str_starts_with($entryPoint, self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER)) {
            return self::formatMessengerEntryPoint(substr($entryPoint, strlen(self::ENTRY_POINT_PREFIX_MESSAGE_HANDLER)), []);
        }
        foreach (IssuesLogService::CLIENT_SECTIONS as $clientSection) {
            if (str_starts_with($entryPoint, $clientSection . ':')) {
                $routePattern = substr($entryPoint, strlen($clientSection) + 1);
                return $routePattern !== '' ? $routePattern : null;
            }
        }
        if (preg_match('/^(?<class>[^:]+):(?<action>[^:]+?)__(?<methods>[A-Z,]*):(?<path>\/.*)$/', $entryPoint, $routeMatch) === 1) {
            $audiencePrefix = preg_match('/\\\\Presentation\\\\Api\\\\([A-Za-z]+)\\\\/', $routeMatch['class'], $audienceMatch) === 1
                ? '/api/' . strtolower($audienceMatch[1])
                : '';
            return self::joinMethodsAndPath($routeMatch['methods'], $audiencePrefix . $routeMatch['path']);
        }
        if (preg_match('/^(?<methods>[A-Z,]+):(?<path>\/.*)$/', $entryPoint, $routeMatch) === 1) {
            return self::joinMethodsAndPath($routeMatch['methods'], $routeMatch['path']);
        }
        return $entryPoint;
    }

    /**
     * A worker message's entry point (pure): `SendEmailMessage (send_email)` when exactly one message class is routed
     * to the transport, else the transport alone.
     *
     * @param string[] $routedMessageClasses
     */
    public static function formatMessengerEntryPoint(string $transportName, array $routedMessageClasses): ?string
    {
        $transportName = trim($transportName);
        if ($transportName === '') {
            return null;
        }
        if (count($routedMessageClasses) !== 1) {
            return $transportName;
        }
        $messageClassParts = explode('\\', (string)reset($routedMessageClasses));
        return end($messageClassParts) . ' (' . $transportName . ')';
    }

    /** The cap of one logged event (`maxEventBytes`): the stack trace shown never exceeds it */
    public function getMaxEventBytes(): int
    {
        return IssuesLogSettings::fromEnvironment()->maxEventBytes;
    }

    protected static function joinMethodsAndPath(string $methods, string $path): string
    {
        return $methods !== '' ? str_replace(',', '|', $methods) . ' ' . $path : $path;
    }

    /** The details without events: the failure in `eventsError`, an agent JSON that names it */
    protected function applyEventsError(IssueGroupDetail $issueGroupDetail, IssueGroup $issueGroup, string $eventsError): IssueGroupDetail
    {
        $issueGroupDetail->eventsError = $eventsError;
        $issueGroupDetail->agentJson = (string)json_encode([
            'environment' => $issueGroup->environment,
            'workspace' => $issueGroup->workspace,
            'groupHash' => $issueGroup->groupHash,
            'eventsError' => $eventsError,
        ], self::AGENT_JSON_FLAGS);
        return $issueGroupDetail;
    }

    /** The command whose JSON is the details' source; a test substitutes its own */
    protected function createIssuesShowCommand(): Command
    {
        return new IssuesShow();
    }

    /** The gap-free series of the window with every point 0 */
    protected function buildEmptyTrendSeries(string $seriesName, TimeInterval $timeInterval): DateTimeValueSequence
    {
        return DateTimeValueSequence::fromValuesByTimestamp(
            $seriesName,
            $timeInterval->startDate,
            $timeInterval->endDate,
            self::resolveTrendStepSeconds($timeInterval),
            []
        );
    }

    /**
     * @throws BadRequestException The value is not a date-time
     */
    protected function parseTrendBound(string $parameterName, ?string $value): ?DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $dateTime = new DateTime(trim($value), new DateTimeZone('UTC'));
        } catch (Throwable) {
            // an unparseable bound is the caller's error: a 400 naming the parameter, never a 500
            throw new BadRequestException(sprintf('The trend bound "%s" is not an ISO 8601 date-time: %s', $parameterName, $value));
        }
        return DateTime::fromTimestamp($dateTime->getTimestamp());
    }

    /**
     * A log backend failure the screen degrades around (an all-zero trend, events replaced by `eventsError`), logged as
     * a WARNING: below the registry's level (error), so it never becomes an issue group of its own. Best effort: a
     * failing logger never turns a degraded view into an error.
     */
    protected function logBackendFailure(Throwable $throwable, string $operation, string $environment, ?string $groupHash): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable($throwable, LogLevel::WARNING, [
                'operation' => $operation,
                'issuesEnvironment' => $environment,
                'groupHash' => $groupHash,
            ]);
        } catch (Throwable) {
            error_log(sprintf('[issues overview] %s failed for %s: %s', $operation, $environment, $throwable->getMessage()));
        }
    }

    /**
     * @param string[] $knownEnvironments
     */
    protected function createUnknownEnvironmentException(string $environment, array $knownEnvironments): BadRequestException
    {
        return new BadRequestException(sprintf(
            'Unknown issues environment "%s". Known environments: %s',
            $environment,
            $knownEnvironments !== [] ? implode(', ', $knownEnvironments) : 'none'
        ));
    }

    protected function getIssueGroupsService(): IssueGroupsService
    {
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = DDDService::instance()->getService(IssueGroupsService::class);
        return $issueGroupsService;
    }

    protected function getIssuesTrendService(): IssuesTrendService
    {
        /** @var IssuesTrendService $issuesTrendService */
        $issuesTrendService = DDDService::instance()->getService(IssuesTrendService::class);
        return $issuesTrendService;
    }

    protected function getIssuesRegistryJobService(): IssuesRegistryJobService
    {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        return $issuesRegistryJobService;
    }
}
