<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The placeholders of an SQL text, in the order they appear.
 *
 * @property DatabaseStatementPlaceholder[] $elements
 * @method DatabaseStatementPlaceholder first()
 * @method DatabaseStatementPlaceholder[] getElements()
 */
class DatabaseStatementPlaceholders extends ObjectSet
{
}
