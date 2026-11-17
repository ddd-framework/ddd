<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Filter of the registry list: every set criterion must match.
 */
class IssueGroupsFilter extends ValueObject
{
    /** Last seen first (the list) */
    public const string ORDER_LAST_SEEN_FIRST = 'LAST_SEEN_FIRST';

    /** Most events first, then last seen (the groups a filtered trend sums) */
    public const string ORDER_MOST_EVENTS_FIRST = 'MOST_EVENTS_FIRST';

    public function __construct(
        /** @var string|null OPEN, RESOLVED or IGNORED */
        public readonly ?string $status = null,
        /** @var DateTime|null Registered at or after */
        public readonly ?DateTime $newSince = null,
        /** @var string|null First or last seen in this release */
        public readonly ?string $release = null,
        public readonly ?string $section = null,
        public readonly ?string $environment = null,
        public readonly int $limit = 50,
        /** @var string[]|null One of these environments; an empty list matches no group */
        public readonly ?array $environments = null,
        /** @var string|null Case-insensitive part of the sample message, the exception type or the origin */
        public readonly ?string $search = null,
        /** @var int Groups to skip (paging) */
        public readonly int $offset = 0,
        /** @var DateTime|null Last seen at or after (an event inside a window that starts here) */
        public readonly ?DateTime $lastSeenSince = null,
        /** @var DateTime|null First seen at or before, or not known (an event inside a window that ends here) */
        public readonly ?DateTime $firstSeenUntil = null,
        /** @var string {@see self::ORDER_LAST_SEEN_FIRST} or {@see self::ORDER_MOST_EVENTS_FIRST} */
        public readonly string $order = self::ORDER_LAST_SEEN_FIRST,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
