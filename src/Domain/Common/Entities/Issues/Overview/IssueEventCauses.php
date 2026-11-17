<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The errors an event wraps (the getPrevious() chain), outermost cause first.
 *
 * @property IssueEventCause[] $elements
 * @method IssueEventCause first()
 * @method IssueEventCause[] getElements()
 */
class IssueEventCauses extends ObjectSet
{
}
