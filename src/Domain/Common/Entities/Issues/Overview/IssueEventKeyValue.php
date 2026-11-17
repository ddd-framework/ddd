<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One named value of a logged event: a route parameter (orderId: 5) or a message key of a failed worker message
 * (orderId: 881). The log line carries them as a map; the wire carries a list, in the logged order.
 */
class IssueEventKeyValue extends ValueObject
{
    /** @var string The name, e.g. orderId */
    #[Required]
    public string $key = '';

    /** @var string|null The value as text (booleans as true / false); null when it was logged as null */
    #[Required]
    public ?string $value = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
