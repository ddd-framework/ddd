<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The statements that write of one audited call, in execution order; a statement issued twice is two elements.
 *
 * @property DatabaseWriteStatement[] $elements
 * @method DatabaseWriteStatement first()
 * @method DatabaseWriteStatement[] getElements()
 */
class DatabaseWriteStatements extends ObjectSet
{
}
