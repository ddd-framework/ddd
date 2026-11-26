<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\EventListeners;

use DDD\Symfony\EventListeners\TooManyRequestsJsonResponseListener;

/** A {@see TooManyRequestsJsonResponseListener} whose path prefixes are the ones the test hands over, not the config's. */
class FixedPathPrefixesTooManyRequestsJsonResponseListener extends TooManyRequestsJsonResponseListener
{
    /**
     * @param string[] $pathPrefixes
     */
    public function __construct(protected array $pathPrefixes)
    {
    }

    protected function getPathPrefixes(): array
    {
        return $this->pathPrefixes;
    }
}
