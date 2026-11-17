<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupStatusTotals;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummary;
use DDD\Domain\Common\Entities\Issues\Overview\IssuesRegistryHealthSummary;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesOverviewService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssueGroupDetailGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssueGroupGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssueGroupGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssueGroupsListGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssueGroupsListGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssuesTrendGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Issues\Dtos\IssuesTrendGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The issues endpoints of an admin API: the error groups of every environment grouped by root cause (list, trend,
 * details) and their status (resolve, ignore, reopen). An application mounts them with one controller of its own,
 * which sets the path prefix, the tag and the access:
 *
 *     #[Route('/common/issues')]
 *     #[Tag(group: 'Common', name: 'Issues', description: 'Error groups of the issues registry')]
 *     class AdminIssuesController extends AdminController
 *     {
 *         use IssuesControllerTrait;
 *     }
 *
 * The registry fills itself from the log backend (`app:issues:sync-registry-from-log-backend`); these endpoints read it
 * and set status. The registry tables are readable by admin accounts; error payloads carry stack traces, request
 * parameters and account ids, so an application with admin accounts of lower trust restricts the path further
 * (security `access_control`). The data comes from {@see IssuesOverviewService}.
 */
trait IssuesControllerTrait
{
    /**
     * One page of error groups of one environment, or of every known environment for `environment=all` (each group
     * carries its environment, the counts are summed, the health is the overall one), with the tab counts, the known
     * environments and the job health.
     *
     * @throws BadRequestException When the environment is not known
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Get('/groups/list')]
    #[Summary('Issue Groups List')]
    public function listIssueGroups(
        IssueGroupsListGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesRegistryJobService $issuesRegistryJobService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupsListGetResponseDto {
        $issueGroupStatusCounts = $issueGroupsService->countGroupsByEnvironmentAndStatus();
        $environment = $issuesOverviewService->resolveEnvironment($requestDto->environment, $issueGroupStatusCounts);
        $environmentsToQuery = $issuesOverviewService->resolveEnvironmentsToQuery($environment, $issueGroupStatusCounts);
        $issueGroups = $issuesOverviewService->findGroupsForList(
            environments: $environmentsToQuery,
            status: $requestDto->getStatusFilter(),
            search: $requestDto->search,
            section: $requestDto->section,
            release: $requestDto->release,
            limit: $requestDto->limit,
            offset: $requestDto->offset,
        );

        $responseDto = new IssueGroupsListGetResponseDto();
        $responseDto->groups = $issuesOverviewService->summariseIssueGroups($issueGroups);
        $responseDto->statusCounts = IssueGroupStatusTotals::fromIssueGroupStatusCountsOfEnvironments(
            $issueGroupStatusCounts,
            $environmentsToQuery
        );
        $responseDto->environments = $issuesOverviewService->getKnownEnvironments($issueGroupStatusCounts);
        $responseDto->environment = $environment;
        $responseDto->health = IssuesRegistryHealthSummary::fromIssuesRegistryHealth(
            $issuesRegistryJobService->getHealth(),
            $environment
        );
        return $responseDto;
    }

    /**
     * Error events per time bucket of one environment (or of all: `environment=all`), of one group in it
     * (`groupHash`), or of exactly the groups the list shows for the same filters (`status`, `search`, `section`,
     * `release`): the chart above the list and the sparkline of the details. A filtered trend sums the trends of at
     * most 50 groups with events in the window (most events first) and says so (`isFilteredByGroups`,
     * `matchingGroupCount`, `summedGroupCount`, `isTruncated`). The window defaults to the last 24 hours and is at most
     * 31 days; the step follows the window. An unknown environment is a 400, a group hash with "all" or with a list
     * filter a 400, an unknown group in a known environment a 404. A log backend that is down or not configured answers
     * 200 with every point 0 and `trendError`, never a 500.
     *
     * @throws BadRequestException
     * @throws NotFoundException When the group is not in the environment
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Get('/trend')]
    #[Summary('Issues Trend')]
    public function getIssuesTrend(
        IssuesTrendGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssuesTrendGetResponseDto {
        $environment = $issuesOverviewService->resolveEnvironment($requestDto->environment);
        $timeInterval = $issuesOverviewService->resolveTrendInterval($requestDto->from, $requestDto->to);
        $groupHash = $requestDto->getGroupHashFilter();
        $status = $requestDto->getStatusFilter();
        $hasGroupFilter = IssuesOverviewService::hasGroupFilter($status, $requestDto->search, $requestDto->section, $requestDto->release);
        if ($groupHash !== null) {
            if ($environment === IssuesOverviewService::ENVIRONMENT_ALL) {
                throw new BadRequestException('A group trend needs its environment, not "all".');
            }
            if ($hasGroupFilter) {
                throw new BadRequestException('A group trend takes no list filter (status, search, section, release).');
            }
            if ($issueGroupsService->findByGroupHash($groupHash, $environment) === null) {
                throw new NotFoundException(sprintf('No issue group %s in environment %s', $groupHash, $environment));
            }
        }

        $issuesTrendSelection = $hasGroupFilter
            ? $issuesOverviewService->findTrendOfFilteredGroups(
                seriesName: $environment,
                environments: $issuesOverviewService->resolveEnvironmentsToQuery($environment),
                status: $status,
                search: $requestDto->search,
                section: $requestDto->section,
                release: $requestDto->release,
                timeInterval: $timeInterval,
            )
            : $issuesOverviewService->findTrend($environment, $groupHash, $timeInterval);

        $responseDto = new IssuesTrendGetResponseDto();
        $responseDto->environment = $environment;
        $responseDto->groupHash = $groupHash;
        $responseDto->from = gmdate(DateTime::DATE_RFC3339_ZULU, $timeInterval->startDate->getTimestamp());
        $responseDto->to = gmdate(DateTime::DATE_RFC3339_ZULU, $timeInterval->endDate->getTimestamp());
        $responseDto->stepSeconds = (int)$issuesTrendSelection->series->stepSeconds;
        $responseDto->series = $issuesTrendSelection->series;
        $responseDto->total = (int)round($issuesTrendSelection->series->getTotal());
        $responseDto->trendError = $issuesTrendSelection->trendError;
        $responseDto->isFilteredByGroups = $issuesTrendSelection->isFilteredByGroups;
        $responseDto->matchingGroupCount = $issuesTrendSelection->matchingGroupCount;
        $responseDto->summedGroupCount = $issuesTrendSelection->summedGroupCount;
        $responseDto->isTruncated = $issuesTrendSelection->isTruncated;
        return $responseDto;
    }

    /**
     * One error group with its issues (entry points, most events first), its newest events and the agent payload.
     * An unreachable log backend yields no events and `eventsError`, never a 500.
     *
     * @throws BadRequestException When the environment is not known
     * @throws NotFoundException When the group is not in the environment
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Get('/groups/{environment}/{groupHash}', requirements: IssueGroupGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('Issue Group Details')]
    public function getIssueGroupDetail(
        IssueGroupGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupDetailGetResponseDto {
        $issueGroup = $this->findIssueGroupOfRequestOrThrow($requestDto, $issueGroupsService, $issuesOverviewService);
        return IssueGroupDetailGetResponseDto::fromIssueGroupDetail($issuesOverviewService->findIssueGroupDetail($issueGroup));
    }

    /**
     * Marks the group fixed: only an event after now reopens it (as a regression). Resolving a resolved group returns
     * it unchanged.
     *
     * @throws BadRequestException When the environment is not known
     * @throws NotFoundException When the group is not in the environment
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Post('/groups/{environment}/{groupHash}/resolve', requirements: IssueGroupGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('Issue Group Resolve')]
    public function resolveIssueGroup(
        IssueGroupGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupGetResponseDto {
        $issueGroup = $this->findIssueGroupOfRequestOrThrow($requestDto, $issueGroupsService, $issuesOverviewService);
        return $this->buildIssueGroupResponse($issuesOverviewService->resolveGroup($issueGroup), $issuesOverviewService);
    }

    /**
     * Ignores the group: it never alerts again, also not as a regression. Ignoring an ignored group returns it
     * unchanged.
     *
     * @throws BadRequestException When the environment is not known
     * @throws NotFoundException When the group is not in the environment
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Post('/groups/{environment}/{groupHash}/ignore', requirements: IssueGroupGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('Issue Group Ignore')]
    public function ignoreIssueGroup(
        IssueGroupGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupGetResponseDto {
        $issueGroup = $this->findIssueGroupOfRequestOrThrow($requestDto, $issueGroupsService, $issuesOverviewService);
        return $this->buildIssueGroupResponse($issuesOverviewService->ignoreGroup($issueGroup), $issuesOverviewService);
    }

    /**
     * Reopens a resolved or ignored group (status OPEN, resolvedAt cleared). Reopening an open group returns it
     * unchanged.
     *
     * @throws BadRequestException When the environment is not known
     * @throws NotFoundException When the group is not in the environment
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Post('/groups/{environment}/{groupHash}/reopen', requirements: IssueGroupGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('Issue Group Reopen')]
    public function reopenIssueGroup(
        IssueGroupGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupGetResponseDto {
        $issueGroup = $this->findIssueGroupOfRequestOrThrow($requestDto, $issueGroupsService, $issuesOverviewService);
        return $this->buildIssueGroupResponse($issuesOverviewService->reopenGroup($issueGroup), $issuesOverviewService);
    }

    /**
     * The group of the path's environment and hash: an unknown environment is a 400, an unknown hash in a known
     * environment a 404.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findIssueGroupOfRequestOrThrow(
        IssueGroupGetRequestDto $requestDto,
        IssueGroupsService $issueGroupsService,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroup {
        $issuesOverviewService->assertKnownEnvironment($requestDto->environment);
        return $issueGroupsService->findByGroupHash($requestDto->groupHash, $requestDto->environment)
            ?? throw new NotFoundException(sprintf(
                'No issue group %s in environment %s',
                $requestDto->groupHash,
                $requestDto->environment
            ));
    }

    protected function buildIssueGroupResponse(
        IssueGroup $issueGroup,
        IssuesOverviewService $issuesOverviewService
    ): IssueGroupGetResponseDto {
        $responseDto = new IssueGroupGetResponseDto();
        $responseDto->group = IssueGroupSummary::fromIssueGroup($issueGroup, $issuesOverviewService->describeEntryPoint($issueGroup));
        return $responseDto;
    }
}
