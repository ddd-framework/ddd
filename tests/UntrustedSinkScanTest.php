<?php

declare(strict_types=1);

namespace DDD\Tests;

use DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase;

/**
 * Core's own sink scan: every raw sink call goes through a helper of DDD\Infrastructure\Untrusted or is listed in
 * config/untrusted-sinks-allowlist.php.
 */
class UntrustedSinkScanTest extends UntrustedSinkScanTestCase
{
    protected function getPackageRootDirectory(): string
    {
        return dirname(__DIR__);
    }
}
