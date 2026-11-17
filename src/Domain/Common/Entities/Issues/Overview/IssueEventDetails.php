<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest events of one group, newest first.
 *
 * @property IssueEventDetail[] $elements
 * @method IssueEventDetail first()
 * @method IssueEventDetail[] getElements()
 */
class IssueEventDetails extends ObjectSet
{
}
