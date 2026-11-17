<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One error group (one root cause in one environment) as an admin screen shows it: identity, the error, timing, the
 * new / regression flags, when it was resolved and its entry point in readable form.
 */
class IssueGroupSummary extends ValueObject
{
    /** @var string The registry environment: e.g. prod, testing or a dev workspace code */
    #[Required]
    public string $environment = '';

    /** @var string The root-cause hash (error.group_hash) */
    #[Required]
    public string $groupHash = '';

    /** @var string OPEN, RESOLVED or IGNORED */
    #[Required]
    public string $status = IssueGroup::STATUS_OPEN;

    /** @var string|null Exception class of the sample event */
    #[Required]
    public ?string $exceptionType = null;

    /** @var string|null Message of the sample event */
    #[Required]
    public ?string $sampleMessage = null;

    /** @var string|null Readable slug of the sample event */
    #[Required]
    public ?string $slug = null;

    /** @var string|null file:line of the sample event */
    #[Required]
    public ?string $logOrigin = null;

    /** @var string|null HTTP, CLI or MESSENGER (backend); WEB, WEB_SERVER or MOBILE (client errors) */
    #[Required]
    public ?string $section = null;

    /**
     * @var string|null The entry point, readable: method and route (HTTP, e.g. POST /api/admin/orders), message class
     * and transport (MESSENGER, e.g. SendEmailMessage (send_email); the transport alone when several messages share
     * it), the command (CLI, e.g. app:crons:execute) or the page route (WEB, WEB_SERVER, MOBILE, e.g. /orders/[orderId]).
     * Null when unknown.
     */
    #[Required]
    public ?string $routePattern = null;

    /** @var DateTime|null First event in the log backend */
    #[Required]
    public ?DateTime $firstSeenAt = null;

    /** @var DateTime|null Latest event counted */
    #[Required]
    public ?DateTime $lastSeenAt = null;

    /** @var int Events counted (approximate) */
    #[Required]
    public int $eventCount = 0;

    /** @var string|null Release the group was first seen in */
    #[Required]
    public ?string $releaseFirstSeen = null;

    /** @var string|null Release of the latest sample */
    #[Required]
    public ?string $releaseLastSeen = null;

    /** @var bool Created as a new root cause (not during the seed period) */
    #[Required]
    public bool $isNew = false;

    /** @var bool Reopened by an event after it was resolved */
    #[Required]
    public bool $isRegression = false;

    /** @var DateTime|null When it was marked resolved */
    #[Required]
    public ?DateTime $resolvedAt = null;

    /**
     * @param string|null $routePattern The readable entry point ({@see \DDD\Domain\Common\Services\IssuesOverviewService::describeEntryPoint()})
     */
    public static function fromIssueGroup(IssueGroup $issueGroup, ?string $routePattern = null): static
    {
        $issueGroupSummary = new static();
        $issueGroupSummary->environment = (string)$issueGroup->environment;
        $issueGroupSummary->groupHash = $issueGroup->groupHash;
        $issueGroupSummary->status = $issueGroup->status;
        $issueGroupSummary->exceptionType = $issueGroup->exceptionType;
        $issueGroupSummary->sampleMessage = $issueGroup->sampleMessage;
        $issueGroupSummary->slug = $issueGroup->slug;
        $issueGroupSummary->logOrigin = $issueGroup->logOrigin;
        $issueGroupSummary->section = $issueGroup->section;
        $issueGroupSummary->routePattern = $routePattern;
        $issueGroupSummary->firstSeenAt = $issueGroup->firstSeenAt;
        $issueGroupSummary->lastSeenAt = $issueGroup->lastSeenAt;
        $issueGroupSummary->eventCount = $issueGroup->eventCount;
        $issueGroupSummary->releaseFirstSeen = $issueGroup->releaseFirstSeen;
        $issueGroupSummary->releaseLastSeen = $issueGroup->releaseLastSeen;
        $issueGroupSummary->isNew = $issueGroup->isNew;
        $issueGroupSummary->isRegression = $issueGroup->isRegression;
        $issueGroupSummary->resolvedAt = $issueGroup->resolvedAt;
        return $issueGroupSummary;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->environment . '|' . $this->groupHash);
    }
}
