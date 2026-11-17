<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The window and scope of the issues trend: the errors of one environment (or of all) per time bucket, optionally of
 * one group (the sparkline of a details view), or of exactly the groups the list shows for the same filters (status,
 * search, section, release). Without a list filter (status ALL, the default, and no search / section / release) the
 * trend counts every error event of the scope in the log backend; with one, the registry selects the groups and their
 * trends are summed.
 *
 * The bounds are ISO 8601 strings, so an unparseable value answers 400 naming the parameter.
 */
class IssuesTrendGetRequestDto extends RequestDto
{
    /**
     * @var string|null The registry environment, or "all" (every watched environment summed). Omitted or empty
     * resolves to the first known environment, like the list; an explicitly sent unknown value answers 400.
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 64)]
    public ?string $environment = null;

    /**
     * @var string|null One error group (error.group_hash); omitted = every error of the environment. Needs a single
     * environment (not "all") and excludes the list filters.
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 128)]
    public ?string $groupHash = null;

    /** @var string The list's status tab: OPEN, RESOLVED, IGNORED or ALL (default: no status filter) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: IssueGroupsListGetRequestDto::STATUSES)]
    public string $status = IssueGroupsListGetRequestDto::STATUS_ALL;

    /** @var string|null The list's search over message, exception type and origin */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 200)]
    public ?string $search = null;

    /** @var string|null The list's section filter: HTTP, CLI, MESSENGER, WEB, WEB_SERVER or MOBILE */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 16)]
    public ?string $section = null;

    /** @var string|null The list's release filter: first or last seen in this release */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 128)]
    public ?string $release = null;

    /** @var string|null Window start as an ISO 8601 date-time (e.g. 2026-10-28T10:00:00Z); omitted = 24 hours before the end */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 40)]
    public ?string $from = null;

    /** @var string|null Window end as an ISO 8601 date-time; omitted = now. After the start; the window is at most 31 days */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 40)]
    public ?string $to = null;

    /** The status filter of the registry query: null for ALL */
    public function getStatusFilter(): ?string
    {
        return $this->status === IssueGroupsListGetRequestDto::STATUS_ALL ? null : $this->status;
    }

    /** The group hash, trimmed; null when none was sent */
    public function getGroupHashFilter(): ?string
    {
        return $this->groupHash !== null && trim($this->groupHash) !== '' ? trim($this->groupHash) : null;
    }
}
