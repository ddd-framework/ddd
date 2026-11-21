<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\HiddenExpand;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property HiddenExpandItem[] $elements
 * @method HiddenExpandItem[] getElements()
 */
class HiddenExpandItems extends EntitySet
{
    use QueryOptionsTrait;
}
