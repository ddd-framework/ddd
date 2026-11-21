<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\HiddenExpand;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/** One visible and one hidden lazy relation to the same item set */
class HiddenExpandHolder extends Entity
{
    use QueryOptionsTrait;

    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadItems')]
    public ?HiddenExpandItems $items = null;

    #[HideProperty]
    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadItems')]
    public ?HiddenExpandItems $internalItems = null;

    public function loadItems(): HiddenExpandItems
    {
        return new HiddenExpandItems();
    }
}
