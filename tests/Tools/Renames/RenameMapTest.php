<?php

declare(strict_types=1);

namespace DDD\Tests\Tools\Renames;

use DDD\Tools\Renames\RenameMap;
use DDD\Tools\Renames\RenamesCommandLine;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/Renames/autoload.php';

/**
 * The rename map of a package and its lint (`ddd-renames validate`): shape, versions, kinds, old != new, constants
 * keeping their class, no duplicate, chain or swap within a version; history across versions allowed.
 */
class RenameMapTest extends TestCase
{
    protected ?string $packageDirectory = null;

    protected function tearDown(): void
    {
        if ($this->packageDirectory !== null) {
            exec('rm -rf ' . escapeshellarg($this->packageDirectory));
            $this->packageDirectory = null;
        }
    }

    public function testTheFixtureMapsAreValidAndSortedByVersion(): void
    {
        foreach (['acme-agents' => 'acme/agents', 'acme-settings' => 'acme/settings'] as $folder => $packageName) {
            $renameMap = RenameMap::loadFromFile(__DIR__ . "/Fixtures/packages/{$folder}/renames.json", $packageName);
            self::assertSame([], $renameMap->problems, $folder);
        }
        $renameMap = RenameMap::loadFromFile(__DIR__ . '/Fixtures/packages/acme-agents/renames.json', 'acme/agents');
        self::assertSame(['2.0.0', '2.1.0', '3.0.0'], array_keys($renameMap->getEntriesByVersion(null, null)));
        self::assertSame(['2.1.0'], array_keys($renameMap->getEntriesByVersion('2.0.0', '2.1.0')), 'newer than the record, not newer than installed');
        self::assertSame('3.0.0', $renameMap->getHighestVersion());
    }

    public function testShapeVersionKindAndSpellingProblemsAreReported(): void
    {
        $renameMap = $this->readMap([
            'format' => 1,
            'extra' => true,
            'renames' => [
                ['version' => '2.0', 'kind' => 'class', 'old' => 'A\\B', 'new' => 'A\\C'],
                ['version' => '2.0.0', 'kind' => 'table', 'old' => 'a', 'new' => 'b'],
                ['version' => '2.0.0', 'kind' => 'class', 'old' => 'A\\Same', 'new' => 'A\\Same'],
                ['version' => '2.0.0', 'kind' => 'constant', 'old' => 'A\\Skills::WEB', 'new' => 'A\\Other::WEB'],
                ['version' => '2.0.0', 'kind' => 'constant', 'old' => 'WEB', 'new' => 'A\\Skills::SEARCH'],
                ['version' => '2.0.0', 'kind' => 'skillName', 'old' => 'Agent Web', 'new' => 'Agent.Search'],
                ['version' => '2.0.0', 'kind' => 'consoleCommand', 'old' => 'app:x', 'new' => 'app:y', 'color' => 'red'],
                ['version' => '2.0.0', 'kind' => 'envVar', 'old' => 'X_KEY'],
            ],
        ]);
        $problems = implode("\n", $renameMap->problems);
        self::assertStringContainsString('unknown key "extra"', $problems);
        self::assertStringContainsString('version "2.0" is no regular', $problems);
        self::assertStringContainsString('unknown kind', $problems);
        self::assertStringContainsString('old and new are the same', $problems);
        self::assertStringContainsString('a constant entry keeps its class', $problems);
        self::assertStringContainsString('"old" "WEB" is no valid constant', $problems);
        self::assertStringContainsString('"old" "Agent Web" is no valid skillName', $problems);
        self::assertStringContainsString('unknown key "color"', $problems);
        self::assertStringContainsString('"new" must be a non-empty string', $problems);
        self::assertSame([], $renameMap->renameEntries, 'an entry with a problem is left out');
    }

    public function testChainsSwapsAndDuplicatesWithinAVersionAreProblemsHistoryAcrossVersionsIsNot(): void
    {
        $renameMap = $this->readMap(['format' => 1, 'renames' => [
            ['version' => '2.0.0', 'kind' => 'skillName', 'old' => 'A.One', 'new' => 'A.Two'],
            ['version' => '2.0.0', 'kind' => 'skillName', 'old' => 'A.Two', 'new' => 'A.Three'],
            ['version' => '2.0.0', 'kind' => 'envVar', 'old' => 'X_ONE', 'new' => 'X_TWO'],
            ['version' => '2.0.0', 'kind' => 'envVar', 'old' => 'X_TWO', 'new' => 'X_ONE'],
            ['version' => '2.0.0', 'kind' => 'consoleCommand', 'old' => 'app:a', 'new' => 'app:b'],
            ['version' => '2.0.0', 'kind' => 'consoleCommand', 'old' => 'app:a', 'new' => 'app:c'],
            ['version' => '2.0.0', 'kind' => 'class', 'old' => 'Old\\Tool', 'new' => 'Moved\\Tool'],
            ['version' => '2.0.0', 'kind' => 'namespace', 'old' => 'Moved', 'new' => 'Final'],
        ]]);
        $problems = implode("\n", $renameMap->problems);
        self::assertStringContainsString('chain — skillName A.One -> A.Two', $problems);
        self::assertStringContainsString('chain — envVar X_ONE -> X_TWO', $problems, 'a swap is a chain both ways');
        self::assertStringContainsString('consoleCommand app:a is renamed twice', $problems);
        self::assertStringContainsString('chain — class Old\\Tool -> Moved\\Tool', $problems, 'a class moved into a namespace renamed in the same version');

        $historyMap = $this->readMap(['format' => 1, 'renames' => [
            ['version' => '2.1.0', 'kind' => 'skillName', 'old' => 'A.Two', 'new' => 'A.Three'],
            ['version' => '2.0.0', 'kind' => 'skillName', 'old' => 'A.One', 'new' => 'A.Two'],
            ['version' => '3.0.0', 'kind' => 'skillName', 'old' => 'A.Three', 'new' => 'A.Two'],
        ]]);
        self::assertSame([], $historyMap->problems);
        self::assertSame(['2.0.0', '2.1.0', '3.0.0'], array_map(static fn($renameEntry): string => $renameEntry->version, $historyMap->renameEntries));
        self::assertStringContainsString('reverts skillName A.Two -> A.Three', implode("\n", $historyMap->warnings));
    }

    public function testValidateCommandExitsOneOnProblemsAndZeroOnAValidMap(): void
    {
        $this->packageDirectory = sys_get_temp_dir() . '/ddd-renames-validate-' . bin2hex(random_bytes(4));
        mkdir($this->packageDirectory);
        file_put_contents($this->packageDirectory . '/composer.json', json_encode(['name' => 'acme/orders', 'version' => '2.0.0']));
        file_put_contents($this->packageDirectory . '/renames.json', json_encode(['format' => 1, 'package' => 'acme/orders', 'renames' => [
            ['version' => '2.0.0', 'kind' => 'class', 'old' => 'Acme\\Basket', 'new' => 'Acme\\Cart'],
            ['version' => '2.1.0', 'kind' => 'envVar', 'old' => 'ORDERS_URL', 'new' => 'ORDERS_ENDPOINT'],
        ]]));
        [$exitCode, $output] = $this->runBin(['validate', '--package-dir', $this->packageDirectory, '--format=json']);
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $exitCode);
        $report = json_decode($output, true);
        self::assertTrue($report['valid']);
        self::assertSame(2, $report['renames']);
        self::assertStringContainsString('newer than the package version 2.0.0', implode("\n", $report['warnings']));

        file_put_contents($this->packageDirectory . '/renames.json', json_encode(['format' => 1, 'package' => 'acme/other', 'renames' => []]));
        [$exitCode, $output] = $this->runBin(['validate', '--package-dir', $this->packageDirectory]);
        self::assertSame(RenamesCommandLine::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('"package" is "acme/other", the package is acme/orders', $output);

        unlink($this->packageDirectory . '/renames.json');
        self::assertSame(RenamesCommandLine::EXIT_ERROR, $this->runBin(['validate', '--package-dir', $this->packageDirectory])[0]);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function readMap(array $document): RenameMap
    {
        $renameMap = new RenameMap('acme/test');
        $renameMap->readDocument(json_decode(json_encode($document), true));
        return $renameMap;
    }

    /**
     * @param string[] $arguments
     * @return array{0: int, 1: string}
     */
    protected function runBin(array $arguments): array
    {
        $command = 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/ddd-renames') . ' '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        return [$exitCode, implode("\n", $outputLines)];
    }
}
