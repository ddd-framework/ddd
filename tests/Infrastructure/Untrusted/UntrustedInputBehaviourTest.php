<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Testing\UntrustedInput\UntrustedInputBehaviourTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * The reusable behaviour tests of the untrusted-input rule, on Core itself.
 */
class UntrustedInputBehaviourTest extends TestCase
{
    use UntrustedInputBehaviourTestTrait;
}
