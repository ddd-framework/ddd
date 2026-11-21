<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Fixtures\ParentKeyedElements;

use DDD\Domain\Base\Entities\ValueObject;

/** An element whose unique key is built from its parent's: without a parent every instance has the same key */
class ParentKeyedElement extends ValueObject
{
    public ?string $content = null;

    public function uniqueKey(): string
    {
        $key = '';
        if ($this->getParent()) {
            $key = $this->getParent()->uniqueKey() . ($this->content ?? '');
        }
        return static::uniqueKeyStatic(md5($key));
    }
}
