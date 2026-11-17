<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One issue of a group: the group's error reached through one entry point (route, CLI command or message handler).
 */
class IssueSummary extends ValueObject
{
    /** @var string The issue hash (error.issue_hash) */
    #[Required]
    public string $issueHash = '';

    /** @var string|null Route, CLI:command or MessageHandler:transport */
    #[Required]
    public ?string $entryPoint = null;

    /** @var int Events counted through this entry point */
    #[Required]
    public int $eventCount = 0;

    /** @var DateTime|null Latest event through this entry point */
    #[Required]
    public ?DateTime $lastSeenAt = null;

    public static function fromIssue(Issue $issue): static
    {
        $issueSummary = new static();
        $issueSummary->issueHash = $issue->issueHash;
        $issueSummary->entryPoint = $issue->entryPoint;
        $issueSummary->eventCount = $issue->eventCount;
        $issueSummary->lastSeenAt = $issue->lastSeenAt;
        return $issueSummary;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->issueHash);
    }
}
