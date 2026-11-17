<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The error groups of one list page, last seen first (of every environment, for the environment "all").
 *
 * @property IssueGroupSummary[] $elements
 * @method IssueGroupSummary first()
 * @method IssueGroupSummary[] getElements()
 */
class IssueGroupSummaries extends ObjectSet
{
}
