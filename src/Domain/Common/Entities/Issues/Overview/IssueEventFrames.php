<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The stack frames of one event or cause, innermost first.
 *
 * @property IssueEventFrame[] $elements
 * @method IssueEventFrame first()
 * @method IssueEventFrame[] getElements()
 */
class IssueEventFrames extends ObjectSet
{
}
