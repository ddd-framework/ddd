<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * Group counts per status in the selected environment (summed over every environment for "all"): the badges of the
 * status tabs.
 */
class IssueGroupStatusTotals extends ValueObject
{
    /** @var int Open groups */
    #[Required]
    public int $open = 0;

    /** @var int Resolved groups */
    #[Required]
    public int $resolved = 0;

    /** @var int Ignored groups */
    #[Required]
    public int $ignored = 0;

    /**
     * Counts summed over the environments
     *
     * @param string[] $environments
     */
    public static function fromIssueGroupStatusCountsOfEnvironments(
        IssueGroupStatusCounts $issueGroupStatusCounts,
        array $environments
    ): static {
        $issueGroupStatusTotals = new static();
        foreach (array_unique($environments) as $environment) {
            $issueGroupStatusTotals->open += $issueGroupStatusCounts->getCount($environment, IssueGroup::STATUS_OPEN);
            $issueGroupStatusTotals->resolved += $issueGroupStatusCounts->getCount($environment, IssueGroup::STATUS_RESOLVED);
            $issueGroupStatusTotals->ignored += $issueGroupStatusCounts->getCount($environment, IssueGroup::STATUS_IGNORED);
        }
        return $issueGroupStatusTotals;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
