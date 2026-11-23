<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Infrastructure\Services\UntrustedInputRuntimeCheckService;
use DDD\Infrastructure\Untrusted\UntrustedInputRuntimeCheck;
use PHPUnit\Framework\TestCase;

class UntrustedInputRuntimeCheckServiceTest extends TestCase
{
    public function testReportsEveryInterpreterCoderGhostscriptAndTheRasterCoders(): void
    {
        $untrustedInputRuntimeChecks = (new UntrustedInputRuntimeCheckService())->checkRuntime();
        $statusesByName = [];
        foreach ($untrustedInputRuntimeChecks->getElements() as $untrustedInputRuntimeCheck) {
            $statusesByName[$untrustedInputRuntimeCheck->name] = $untrustedInputRuntimeCheck->status;
        }
        foreach (['ps', 'eps', 'pdf', 'svg', 'mvg', 'msl', 'text'] as $coder) {
            $this->assertArrayHasKey('imagemagick-reads-' . $coder, $statusesByName);
        }
        $this->assertArrayHasKey('gs-on-path', $statusesByName);
        $this->assertSame(UntrustedInputRuntimeCheck::STATUS_SAFE, $statusesByName['raster-decode-and-encode']);
        $this->assertSame(!in_array(UntrustedInputRuntimeCheck::STATUS_UNSAFE, $statusesByName, true) && !in_array(UntrustedInputRuntimeCheck::STATUS_BROKEN, $statusesByName, true), $untrustedInputRuntimeChecks->isSafe());
    }
}
