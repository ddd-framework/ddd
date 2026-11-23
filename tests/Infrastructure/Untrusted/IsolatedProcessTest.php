<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use PHPUnit\Framework\TestCase;

class IsolatedProcessTest extends TestCase
{
    public function testTheChildSeesNoInheritedVariableAndNoShell(): void
    {
        putenv('DDD_SECRET_FOR_TEST=leaked');
        $_ENV['DDD_SECRET_FOR_TEST'] = 'leaked';
        $workingDirectory = IsolatedProcess::createWorkingDirectory('env-test');
        try {
            $process = IsolatedProcess::create('env', [], $workingDirectory, 10, null);
            $process->mustRun();
            $this->assertStringNotContainsString('DDD_SECRET_FOR_TEST', $process->getOutput());
            $this->assertStringContainsString('PATH=' . IsolatedProcess::SAFE_PATH, $process->getOutput());

            $echoProcess = IsolatedProcess::create('echo', ['$(id)', '; rm -rf /'], $workingDirectory, 10, null);
            $echoProcess->mustRun();
            $this->assertSame("\$(id) ; rm -rf /\n", $echoProcess->getOutput());
        } finally {
            putenv('DDD_SECRET_FOR_TEST');
            unset($_ENV['DDD_SECRET_FOR_TEST']);
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    public function testABinaryNameWithShellCharactersIsRefused(): void
    {
        $this->expectException(InternalErrorException::class);
        IsolatedProcess::resolveBinary('ls; id');
    }
}
