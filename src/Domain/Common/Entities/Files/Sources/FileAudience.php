<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

/**
 * Who may see a file where it lies. A source states it per file ({@see FileFacts::$audience}); a destination has one
 * as well ({@see FileFlowTarget::$audience}). The flow rule of the application
 * ({@see \DDD\Domain\Common\Services\Files\FileFlowPolicyService}) decides from the two whether a file may be copied.
 */
class FileAudience
{
    /** @var string Anyone may see it (a public media library, anything shown in an app or on a website) */
    public const string PUBLIC = 'PUBLIC';

    /** @var string People outside the operating team see it (a space shared with a partner) */
    public const string SHARED = 'SHARED';

    /** @var string The operating team only */
    public const string INTERNAL = 'INTERNAL';

    /** @var string A person's own data (a customer's upload): only where the caller's area is open, never copied wider */
    public const string PERSONAL = 'PERSONAL';

    /** @var string[] Every audience, from the widest to the narrowest */
    public const array AUDIENCES = [self::PUBLIC, self::SHARED, self::INTERNAL, self::PERSONAL];

    public static function isKnown(?string $audience): bool
    {
        return $audience !== null && in_array($audience, static::AUDIENCES, true);
    }

    /**
     * How narrow an audience is: 0 for PUBLIC up to 3 for PERSONAL. An unknown audience counts as the narrowest.
     */
    public static function getRestrictionRank(?string $audience): int
    {
        $rank = array_search($audience, static::AUDIENCES, true);
        return $rank === false ? count(static::AUDIENCES) - 1 : $rank;
    }
}
