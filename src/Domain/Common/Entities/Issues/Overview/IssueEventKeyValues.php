<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Named values of a logged event (route parameters, message keys), in the logged order.
 *
 * @property IssueEventKeyValue[] $elements
 * @method IssueEventKeyValue first()
 * @method IssueEventKeyValue[] getElements()
 */
class IssueEventKeyValues extends ObjectSet
{
}
