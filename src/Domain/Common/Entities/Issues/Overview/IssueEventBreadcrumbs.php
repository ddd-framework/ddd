<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * What happened in a client before its error, oldest first.
 *
 * @property IssueEventBreadcrumb[] $elements
 * @method IssueEventBreadcrumb first()
 * @method IssueEventBreadcrumb[] getElements()
 */
class IssueEventBreadcrumbs extends ObjectSet
{
}
