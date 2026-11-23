<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Router\RouteAttributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RequestCache
{
    /**
     * A cache hit answers before the controller runs, so before any access check inside the controller. The key
     * therefore varies by the signed-in account by default: one account never gets an answer produced for another.
     * Set `considerCurrentAuthAccountForCacheKey: false` only for a route whose answer is the same for every account
     * and whose access is decided entirely by the firewall (`access_control`).
     *
     * @param int $ttl seconds to live
     * @param string[] $headersToConsiderForCacheKey whitelist of allowed headers for caching
     * @param bool $considerCurrentAuthAccountForCacheKey vary the key by the signed-in account (anonymous requests share one key)
     */
    public function __construct(
        public int $ttl,
        public array $headersToConsiderForCacheKey = [],
        public bool $considerCurrentAuthAccountForCacheKey = true,
    ) {}
}
