<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One error group for a details view: the group, where it happens (its issues), its newest events from the log
 * backend, a link into the log backend and the agent payload (the JSON of `app:issues:show`).
 */
class IssueGroupDetail extends ValueObject
{
    /** @var IssueGroupSummary The group */
    #[Required]
    public IssueGroupSummary $group;

    /** @var IssueSummaries The group's issues: one per entry point, most events first */
    #[Required]
    public IssueSummaries $issues;

    /** @var IssueEventDetails The newest events from the log backend, newest first */
    #[Required]
    public IssueEventDetails $events;

    /** @var string|null Why the events could not be read from the log backend; null when they were */
    #[Required]
    public ?string $eventsError = null;

    /** @var string|null Link to the group in the log backend (`alertLinkTemplate` of the issues registry); null when not configured */
    #[Required]
    public ?string $logBackendUrl = null;

    /** @var string The group, its issues and newest events as JSON, byte for byte what `app:issues:show --format=json` prints */
    #[Required]
    public string $agentJson = '';

    public function __construct()
    {
        $this->issues = new IssueSummaries();
        $this->events = new IssueEventDetails();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
