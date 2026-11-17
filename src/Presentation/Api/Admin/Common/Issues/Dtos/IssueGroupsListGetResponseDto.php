<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupStatusTotals;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummaries;
use DDD\Domain\Common\Entities\Issues\Overview\IssuesRegistryHealthSummary;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The issues list: one page of groups, the tab counts, the environments for the switch and the job health.
 */
class IssueGroupsListGetResponseDto extends RestResponseDto
{
    /** @var IssueGroupSummaries The groups, last seen first (across environments for "all") */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroupSummaries $groups;

    /** @var IssueGroupStatusTotals Group counts per status in the selected environment, summed for "all" */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroupStatusTotals $statusCounts;

    /** @var string[] The environments the registry knows (configured ones first, then any others with groups) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $environments = [];

    /** @var string The environment this page was resolved to: the request's ("all" included), or the default */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $environment;

    /** @var IssuesRegistryHealthSummary Health of the registry job and the collector for the selected environment (overall for "all") */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssuesRegistryHealthSummary $health;
}
