<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Domain\Common\Entities\Issues\Overview\IssueEventDetails;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupDetail;
use DDD\Domain\Common\Entities\Issues\Overview\IssueGroupSummary;
use DDD\Domain\Common\Entities\Issues\Overview\IssueSummaries;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The details of one error group: the group, where it happens, its newest events, a link into the log backend and the
 * agent payload.
 */
class IssueGroupDetailGetResponseDto extends RestResponseDto
{
    /** @var IssueGroupSummary The group */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueGroupSummary $group;

    /** @var IssueSummaries The group's issues: one per entry point, most events first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueSummaries $issues;

    /** @var IssueEventDetails The newest events from the log backend, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public IssueEventDetails $events;

    /** @var string|null Why the events could not be read from the log backend; null when they were */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $eventsError = null;

    /** @var string|null Link to the group in the log backend (the registry's alert link template); null when not configured */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $logBackendUrl = null;

    /** @var string The group, its issues and newest events as JSON, in the shape of `app:issues:show --format=json` */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $agentJson = '';

    public static function fromIssueGroupDetail(IssueGroupDetail $issueGroupDetail): static
    {
        $issueGroupDetailGetResponseDto = new static();
        $issueGroupDetailGetResponseDto->group = $issueGroupDetail->group;
        $issueGroupDetailGetResponseDto->issues = $issueGroupDetail->issues;
        $issueGroupDetailGetResponseDto->events = $issueGroupDetail->events;
        $issueGroupDetailGetResponseDto->eventsError = $issueGroupDetail->eventsError;
        $issueGroupDetailGetResponseDto->logBackendUrl = $issueGroupDetail->logBackendUrl;
        $issueGroupDetailGetResponseDto->agentJson = $issueGroupDetail->agentJson;
        return $issueGroupDetailGetResponseDto;
    }
}
