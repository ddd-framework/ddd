<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\HiddenExpand;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/** An item with a hidden relation of its own, for a nested expand */
class HiddenExpandItem extends Entity
{
    use QueryOptionsTrait;

    public ?string $name = null;

    #[HideProperty]
    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadSecret')]
    public ?HiddenExpandHolder $secretHolder = null;

    public function loadSecret(): HiddenExpandHolder
    {
        return new HiddenExpandHolder();
    }
}
