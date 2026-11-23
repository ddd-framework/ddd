<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Infrastructure\Untrusted\ContainedPath;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use PHPUnit\Framework\TestCase;

class ContainedPathTest extends TestCase
{
    protected string $rootDirectory;

    protected function setUp(): void
    {
        $this->rootDirectory = IsolatedProcess::createWorkingDirectory('contained-path');
        mkdir($this->rootDirectory . '/img');
        file_put_contents($this->rootDirectory . '/img/logo.svg', '<svg/>');
        file_put_contents($this->rootDirectory . '/secret.env', 'KEY=1');
        symlink($this->rootDirectory . '/secret.env', $this->rootDirectory . '/img/link.svg');
    }

    protected function tearDown(): void
    {
        IsolatedProcess::removeWorkingDirectory($this->rootDirectory);
    }

    public function testResolvesAFileInsideTheRoot(): void
    {
        $this->assertSame(
            realpath($this->rootDirectory . '/img/logo.svg'),
            ContainedPath::resolveExistingFile($this->rootDirectory . '/img', 'logo.svg', ['svg'])
        );
    }

    public function testRefusesTraversalEncodingAbsoluteAndLinksOut(): void
    {
        foreach (['../secret.env', '..%2fsecret.env', '%2e%2e/secret.env', '/etc/passwd', "logo.svg\0.png", 'a\\..\\secret.env', 'link.svg', 'logo.SVG.php'] as $refusedPath) {
            try {
                ContainedPath::resolveExistingFile($this->rootDirectory . '/img', $refusedPath, ['svg']);
                $this->fail("{$refusedPath} was resolved");
            } catch (UntrustedContentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testResolvesANewFileOnlyInsideTheRoot(): void
    {
        $this->assertSame(realpath($this->rootDirectory . '/img') . '/new.svg', ContainedPath::resolveNewFile($this->rootDirectory . '/img', 'new.svg'));
        $this->expectException(UntrustedContentException::class);
        ContainedPath::resolveNewFile($this->rootDirectory . '/img', '../new.svg');
    }
}
