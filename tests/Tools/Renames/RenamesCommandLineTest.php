<?php

declare(strict_types=1);

namespace DDD\Tests\Tools\Renames;

use DDD\Tools\Renames\RenamesCommandLine;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/Renames/autoload.php';

/**
 * `vendor/bin/ddd-renames` over the fixture application (`Fixtures/app`) with the fixture packages
 * (`Fixtures/packages`) installed: dry run, apply, check, state, version ranges, include / exclude, JSON output.
 */
class RenamesCommandLineTest extends TestCase
{
    protected ?string $applicationDirectory = null;

    protected function tearDown(): void
    {
        if ($this->applicationDirectory !== null) {
            exec('rm -rf ' . escapeshellarg($this->applicationDirectory));
            $this->applicationDirectory = null;
        }
    }

    public function testDryRunListsHitsAndWritesNothing(): void
    {
        $this->createApplication(['acme/agents' => 'v2.1.0', 'acme/settings' => '1.1.0']);
        $filesBefore = $this->readApplicationFiles();
        [$exitCode, $output] = $this->runBin(['--exclude', 'legacy']);
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $exitCode);
        self::assertSame($filesBefore, $this->readApplicationFiles(), 'a dry run writes nothing');
        self::assertFileDoesNotExist($this->applicationDirectory . '/' . RenamesCommandLine::STATE_FILE_NAME);
        self::assertStringContainsString('dry run: nothing written', $output);
        self::assertStringContainsString('acme/agents (nothing recorded) -> 2.1.0: 7 renames pending', $output);
        self::assertStringContainsString('src/Agent/ResearchAgent.php:9:5  Acme\\Agents\\Tools\\WebTool -> Acme\\Agents\\Tools\\ResearchTool', $output);
        self::assertStringContainsString('Ambiguous (never written, check by hand):', $output);
        self::assertStringContainsString('skills/Agent/Web.md  (skillName Agent.Web -> Web.Research', $output);
        self::assertStringNotContainsString('AGENT_WEB_KEY', $output, 'a rename newer than the installed version is not pending');
    }

    public function testApplyWritesCertainHitsChainsVersionsAndRecordsTheState(): void
    {
        $this->createApplication(['acme/agents' => 'v2.1.0', 'acme/settings' => '1.1.0']);
        $nodeModuleBefore = file_get_contents($this->applicationDirectory . '/node_modules/acme/index.js');
        [$exitCode, $output] = $this->runBin(['--apply', '--exclude', 'legacy']);
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $exitCode, $output);

        $agentSource = $this->readApplicationFile('src/Agent/ResearchAgent.php');
        self::assertStringContainsString('use Acme\\Agents\\Classic\\Memory;', $agentSource);
        self::assertStringContainsString('use Acme\\Agents\\Tools\\ResearchTool;', $agentSource);
        self::assertStringContainsString('class ResearchAgent extends ResearchTool', $agentSource);
        self::assertStringContainsString("['Web.Search', 'Agent.Web.Search', AgentSkills::WEB_RESEARCH, AgentSkills::WEB_PAGES]", $agentSource, 'chained across versions: Agent.Web -> Web.Research -> Web.Search');
        self::assertStringContainsString('public function createTool(): ResearchTool', $agentSource);
        self::assertStringContainsString('$webTool = new ResearchTool(Memory::class, Config::get(\'Settings.research.enabled\'));', $agentSource);
        self::assertStringContainsString('$webTool->WebTool = self::WEB;', $agentSource, 'a property and an inherited constant of another parent stay');

        self::assertSame(
            "services:\n    Acme\\Agents\\Tools\\ResearchTool:\n        arguments: ['@Acme\\Agents\\Classic\\Memory', '%env(SETTINGS_ENDPOINT)%', '%env(SETTINGS_URL_BACKUP)%']\n"
            . "    app.skills: [!php/const Acme\\Agents\\AgentSkills::WEB_RESEARCH, 'acme.web.research', 'acme.agent.web.extra']\n",
            $this->readApplicationFile('config/services.yaml')
        );
        self::assertSame(
            "{ \"tool\": \"Acme\\\\Agents\\\\Tools\\\\ResearchTool\", \"toolCase\": \"Acme\\\\Agents\\\\Tools\\\\WebToolCase\", \"skill\": \"Web.Search\" }\n",
            $this->readApplicationFile('tests/skills.json')
        );
        self::assertSame(
            "{# Runs app:agents:research nightly; app:agents:web:all stays #}\n{{ constant('Acme\\\\Agents\\\\AgentSkills::WEB_RESEARCH') }}\n",
            $this->readApplicationFile('templates/agent.html.twig')
        );
        self::assertSame(
            "# Agent\n\nLoad the skill Web.Search. Agent.Web.Search is another skill.\nCall `AgentSkills::WEB` from code; set SETTINGS_ENDPOINT.\n",
            $this->readApplicationFile('docs/agent.md'),
            'the short constant reference in prose is ambiguous and stays'
        );
        self::assertSame("expect:\n  loadedSkills: [Web.Search]\n", $this->readApplicationFile('evals/fixtures/case.yaml'));
        self::assertSame("Agent.Web in an excluded folder.\n", $this->readApplicationFile('legacy/old.md'));
        self::assertSame($nodeModuleBefore, file_get_contents($this->applicationDirectory . '/node_modules/acme/index.js'));
        self::assertStringContainsString("AGENT_WEB_KEY=secret\n", $this->readApplicationFile('.env'), 'the 3.0.0 rename is not installed yet');
        self::assertStringContainsString("SETTINGS_ENDPOINT=https://settings.example\n", $this->readApplicationFile('.env'));

        $state = json_decode($this->readApplicationFile(RenamesCommandLine::STATE_FILE_NAME), true);
        self::assertSame(['acme/agents' => ['appliedThroughVersion' => '2.1.0'], 'acme/settings' => ['appliedThroughVersion' => '1.1.0']], $state['packages']);

        $filesAfterApply = $this->readApplicationFiles();
        [$exitCode, $output] = $this->runBin(['--apply', '--exclude', 'legacy']);
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $exitCode);
        self::assertStringContainsString('0 certain hits', $output);
        self::assertSame($filesAfterApply, $this->readApplicationFiles(), 'a second apply changes nothing');
    }

    public function testCheckFailsWhilePendingAndOnlyNewerRenamesApplyAfterAnUpdate(): void
    {
        $this->createApplication(['acme/agents' => '2.1.0', 'acme/settings' => '1.1.0']);
        [$exitCode, $output] = $this->runBin(['--check']);
        self::assertSame(RenamesCommandLine::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('renames pending: run vendor/bin/ddd-renames --apply', $output);

        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $this->runBin(['--apply'])[0]);
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $this->runBin(['--check'])[0]);

        $this->writeInstalledPackages(['acme/agents' => '3.0.0', 'acme/settings' => '1.1.0']);
        [$exitCode, $output] = $this->runBin(['--check', '--format=json']);
        self::assertSame(RenamesCommandLine::EXIT_FAILURE, $exitCode);
        $report = json_decode($output, true);
        self::assertTrue($report['pending']);
        self::assertSame([['package' => 'acme/agents', 'version' => '3.0.0', 'kind' => 'envVar', 'old' => 'AGENT_WEB_KEY', 'new' => 'AGENT_SEARCH_KEY', 'note' => null]], $report['renames']);
        self::assertSame(['.env'], array_values(array_unique(array_column($report['hits'], 'file'))));
        self::assertSame(1, $report['summary']['certainHits']);

        $this->runBin(['--apply']);
        self::assertStringContainsString("AGENT_SEARCH_KEY=secret\n", $this->readApplicationFile('.env'));
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $this->runBin(['--check'])[0]);
    }

    public function testFromVersionMarkAppliedAndIncludeNarrowTheRun(): void
    {
        $this->createApplication(['acme/agents' => '2.1.0', 'acme/settings' => '1.1.0']);
        [, $output] = $this->runBin(['--from-version', 'acme/agents=2.0.0', '--include', 'templates', '--format=json']);
        $report = json_decode($output, true);
        self::assertSame(['consoleCommand', 'skillName'], array_values(array_unique(array_column(array_filter($report['renames'], static fn(array $rename): bool => $rename['package'] === 'acme/agents'), 'kind'))));
        self::assertSame(['templates/agent.html.twig'], $report['changedFiles']);

        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $this->runBin(['--mark-applied'])[0]);
        self::assertStringContainsString("'Agent.Web'", $this->readApplicationFile('src/Agent/ResearchAgent.php'), 'mark-applied writes no file');
        self::assertSame(RenamesCommandLine::EXIT_SUCCESS, $this->runBin(['--check'])[0]);

        file_put_contents($this->applicationDirectory . '/' . RenamesCommandLine::STATE_FILE_NAME, json_encode(['format' => 1, 'include' => ['docs'], 'exclude' => [], 'packages' => []]));
        [, $output] = $this->runBin(['--format=json']);
        $includeHits = json_decode($output, true)['hits'];
        self::assertNotEmpty($includeHits);
        self::assertSame(['docs/agent.md'], array_values(array_unique(array_column($includeHits, 'file'))), 'include from the state file');

        self::assertSame(RenamesCommandLine::EXIT_ERROR, $this->runBin(['--apply', '--check'])[0]);
        self::assertSame(RenamesCommandLine::EXIT_ERROR, $this->runBin(['--from-version', 'acme/agents'])[0]);
    }

    /**
     * Copies the fixture application into a temporary folder, installs the fixture packages into its vendor folder.
     *
     * @param array<string, string> $installedVersionsByPackage
     */
    protected function createApplication(array $installedVersionsByPackage): void
    {
        $this->applicationDirectory = sys_get_temp_dir() . '/ddd-renames-app-' . bin2hex(random_bytes(6));
        exec('cp -R ' . escapeshellarg(__DIR__ . '/Fixtures/app') . ' ' . escapeshellarg($this->applicationDirectory));
        file_put_contents($this->applicationDirectory . '/.env', "AGENT_WEB_KEY=secret\nSETTINGS_URL=https://settings.example\n");
        mkdir($this->applicationDirectory . '/vendor/composer', 0775, true);
        mkdir($this->applicationDirectory . '/vendor/acme', 0775, true);
        foreach (['agents', 'settings'] as $packageFolder) {
            exec('cp -R ' . escapeshellarg(__DIR__ . "/Fixtures/packages/acme-{$packageFolder}") . ' ' . escapeshellarg($this->applicationDirectory . "/vendor/acme/{$packageFolder}"));
            file_put_contents($this->applicationDirectory . "/vendor/acme/{$packageFolder}/Untouched.md", "Agent.Web stays in vendor\n");
        }
        $this->writeInstalledPackages($installedVersionsByPackage);
    }

    /**
     * @param array<string, string> $installedVersionsByPackage
     */
    protected function writeInstalledPackages(array $installedVersionsByPackage): void
    {
        $packages = [];
        foreach ($installedVersionsByPackage as $packageName => $version) {
            $packages[] = [
                'name' => $packageName,
                'version' => $version,
                'version_normalized' => ltrim($version, 'v') . '.0',
                'install-path' => '../' . $packageName,
            ];
        }
        $packages[] = ['name' => 'acme/no-map', 'version' => '1.0.0', 'install-path' => '../acme/no-map'];
        file_put_contents($this->applicationDirectory . '/vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev' => true]));
    }

    protected function readApplicationFile(string $relativePath): string
    {
        return (string)file_get_contents($this->applicationDirectory . '/' . $relativePath);
    }

    /**
     * @return array<string, string> relative path => content of every file, vendor included
     */
    protected function readApplicationFiles(): array
    {
        $contents = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->applicationDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            $contents[substr($fileInfo->getPathname(), strlen($this->applicationDirectory) + 1)] = (string)file_get_contents($fileInfo->getPathname());
        }
        ksort($contents);
        return $contents;
    }

    /**
     * @param string[] $arguments
     * @return array{0: int, 1: string}
     */
    protected function runBin(array $arguments): array
    {
        $command = 'cd ' . escapeshellarg((string)$this->applicationDirectory) . ' && php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/ddd-renames') . ' '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        return [$exitCode, implode("\n", $outputLines)];
    }
}
