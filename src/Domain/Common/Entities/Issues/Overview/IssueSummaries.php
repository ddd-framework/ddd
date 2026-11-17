<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Entities\Issues\Issues;

/**
 * The issues of one group ("Where it happens"), most events first.
 *
 * @property IssueSummary[] $elements
 * @method IssueSummary first()
 * @method IssueSummary[] getElements()
 */
class IssueSummaries extends ObjectSet
{
    public static function fromIssues(Issues $issues): static
    {
        $issueSummaries = new static();
        $sortedIssues = $issues->getElements();
        // most events first: the entry point that matters most leads
        usort($sortedIssues, static fn(Issue $left, Issue $right): int => $right->eventCount <=> $left->eventCount);
        foreach ($sortedIssues as $issue) {
            $issueSummary = IssueSummary::fromIssue($issue);
            $issueSummaries->add($issueSummary);
        }
        return $issueSummaries;
    }
}
