<?php

declare(strict_types=1);

namespace DDD\Tests\Tools\SkillLinks;

use DDD\Tools\SkillLinks\VendorSkillLinker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/SkillLinks/VendorSkillLinker.php';

/**
 * The vendor skill links tool against the fixture layouts shared with `ddd-web link-skills` (same scenarios in the
 * npm package's test suite): (a) nested app with real .agents/skills, (b) .codex/skills a symlink to .claude/skills,
 * (c) .claude a symlink to .codex, (d) removed package, (e) clash with an app skill, (f) edited vendor skill,
 * (g) hand-made tracked links adopted and reported.
 */
class VendorSkillLinkerTest extends TestCase
{
    protected ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            $this->removeDirectory($this->fixtureRoot);
            $this->fixtureRoot = null;
        }
    }

    public function testNestedAppLinksVendorSkillsAndMirrorsIntoRealAgentsFolder(): void
    {
        $root = $this->createNestedAppFixture();

        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root)->run());

        $this->assertSame('../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', readlink("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertSame('../../web/node_modules/@mgamadeus/ddd-web/.claude/skills/ddd-web-specialist', readlink("{$root}/.claude/skills/ddd-web-specialist"));
        $this->assertFileExists("{$root}/.claude/skills/ddd-web-specialist/SKILL.md");
        $this->assertSame('../../.claude/skills/ddd-entity-specialist', readlink("{$root}/.agents/skills/ddd-entity-specialist"));
        $this->assertSame('../../.claude/skills/ddd-web-specialist', readlink("{$root}/.agents/skills/ddd-web-specialist"));
        $this->assertFalse(is_link("{$root}/.claude/skills/app-own-skill"));
        $this->assertSame('../../.claude/skills/app-own-skill', readlink("{$root}/.agents/skills/app-own-skill"));
        $this->assertFileDoesNotExist("{$root}/.codex");

        $manifest = json_decode((string)file_get_contents("{$root}/.claude/skills/.ddd-skills.json"), true);
        $this->assertSame(1, $manifest['format']);
        $this->assertSame(['ddd-entity-specialist', 'ddd-service-specialist', 'ddd-web-specialist'], array_keys($manifest['skills']));
        $entity = $manifest['skills']['ddd-entity-specialist'];
        $this->assertSame('mgamadeus/ddd', $entity['package']);
        $this->assertSame('3.36.0', $entity['version']);
        $this->assertSame('app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', $entity['source']);
        $this->assertSame(hash('sha256', "# ddd-entity-specialist\n"), $entity['sha256']);
        $this->assertSame(['.agents/skills'], $entity['mirrors']);
        $this->assertSame('@mgamadeus/ddd-web', $manifest['skills']['ddd-web-specialist']['package']);
        $this->assertSame('0.17.0', $manifest['skills']['ddd-web-specialist']['version']);

        $primaryGitignore = (string)file_get_contents("{$root}/.claude/skills/.gitignore");
        $this->assertStringStartsWith("/own-ignored-file\n\n# ddd-skills:start\n", $primaryGitignore);
        $this->assertStringContainsString("/.ddd-skills.json\n/ddd-entity-specialist\n/ddd-service-specialist\n/ddd-web-specialist\n# ddd-skills:end\n", $primaryGitignore);
        $mirrorGitignore = (string)file_get_contents("{$root}/.agents/skills/.gitignore");
        $this->assertStringContainsString("/ddd-entity-specialist\n/ddd-service-specialist\n/ddd-web-specialist\n# ddd-skills:end\n", $mirrorGitignore);
        $this->assertStringNotContainsString('app-own-skill', $mirrorGitignore);

        // idempotent: the second run changes nothing, the check passes
        $secondRun = $this->runLinker($root);
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $secondRun->run());
        $this->assertContains('3 skill(s) from 2 package(s), 0 change(s)', $secondRun->getPrintedLines());
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testRootIsFoundFromTheComposerSubfolder(): void
    {
        $root = $this->createNestedAppFixture();
        $this->assertSame(realpath($root), VendorSkillLinker::findRepositoryRoot("{$root}/app"));
        $this->assertSame(realpath($root), VendorSkillLinker::findRepositoryRoot("{$root}/web/node_modules"));
    }

    public function testCheckAndDryRunChangeNothing(): void
    {
        $root = $this->createNestedAppFixture();
        $checkRun = $this->runLinker($root, checkOnly: true);
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $checkRun->run());
        $this->assertNotEmpty(array_filter($checkRun->getProblems(), static fn(string $problem): bool => str_starts_with($problem, 'missing')));
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, dryRun: true)->run());
        $this->assertFalse(is_link("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertFileDoesNotExist("{$root}/.claude/skills/.ddd-skills.json");
    }

    public function testCodexSkillsSymlinkedToClaudeSkillsGetsLinksOnce(): void
    {
        $root = $this->createNestedAppFixture(withAgentsFolder: false);
        mkdir("{$root}/.codex");
        symlink('../.claude/skills', "{$root}/.codex/skills");

        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root)->run());

        $this->assertTrue(is_link("{$root}/.codex/skills"));
        $this->assertSame('../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', readlink("{$root}/.claude/skills/ddd-entity-specialist"));
        $manifest = json_decode((string)file_get_contents("{$root}/.claude/skills/.ddd-skills.json"), true);
        $this->assertSame([], $manifest['skills']['ddd-entity-specialist']['mirrors']);
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testClaudeSymlinkedToCodexWritesIntoTheRealFolder(): void
    {
        $root = $this->createNestedAppFixture(withAgentsFolder: false, withClaudeFolder: false);
        mkdir("{$root}/.codex/skills", 0777, true);
        symlink('.codex', "{$root}/.claude");

        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root)->run());

        $this->assertTrue(is_link("{$root}/.claude"));
        $this->assertSame('../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', readlink("{$root}/.codex/skills/ddd-entity-specialist"));
        $this->assertFileExists("{$root}/.codex/skills/ddd-entity-specialist/SKILL.md");
        $this->assertFileExists("{$root}/.codex/skills/.ddd-skills.json");
        $this->assertStringContainsString('/ddd-entity-specialist', (string)file_get_contents("{$root}/.codex/skills/.gitignore"));
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testRemovedPackageLosesItsLinksAndAppSkillsStay(): void
    {
        $root = $this->createNestedAppFixture();
        $this->runLinker($root)->run();
        $this->removeDirectory("{$root}/web/node_modules/@mgamadeus/ddd-web");

        $checkRun = $this->runLinker($root, checkOnly: true);
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $checkRun->run());
        $this->assertNotEmpty(array_filter($checkRun->getProblems(), static fn(string $problem): bool => str_starts_with($problem, 'broken')));

        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root)->run());
        $this->assertFalse(is_link("{$root}/.claude/skills/ddd-web-specialist"));
        $this->assertFalse(is_link("{$root}/.agents/skills/ddd-web-specialist"));
        $this->assertTrue(is_link("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertFileExists("{$root}/.claude/skills/app-own-skill/SKILL.md");
        $this->assertTrue(is_link("{$root}/.agents/skills/app-own-skill"));
        $manifest = json_decode((string)file_get_contents("{$root}/.claude/skills/.ddd-skills.json"), true);
        $this->assertArrayNotHasKey('ddd-web-specialist', $manifest['skills']);
        $this->assertStringNotContainsString('ddd-web-specialist', (string)file_get_contents("{$root}/.claude/skills/.gitignore"));
        $this->assertStringNotContainsString('ddd-web-specialist', (string)file_get_contents("{$root}/.agents/skills/.gitignore"));
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testAppSkillOfTheSameNameIsAClashAndStaysUntouched(): void
    {
        $root = $this->createNestedAppFixture();
        mkdir("{$root}/.claude/skills/ddd-entity-specialist");
        file_put_contents("{$root}/.claude/skills/ddd-entity-specialist/SKILL.md", "# app version\n");

        $linkRun = $this->runLinker($root);
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $linkRun->run());
        $this->assertNotEmpty(array_filter($linkRun->getProblems(), static fn(string $problem): bool => str_starts_with($problem, 'clash') && str_contains($problem, 'ddd-entity-specialist')));
        $this->assertFalse(is_link("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertSame("# app version\n", file_get_contents("{$root}/.claude/skills/ddd-entity-specialist/SKILL.md"));
        $this->assertStringNotContainsString('/ddd-entity-specialist', (string)file_get_contents("{$root}/.claude/skills/.gitignore"));
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testSameSkillFromTwoPackagesKeepsTheFirst(): void
    {
        $root = $this->createNestedAppFixture();
        $this->createSkill("{$root}/app/vendor/mgamadeus/ddd-ai", 'ddd-entity-specialist');

        $linkRun = $this->runLinker($root);
        $linkRun->run();
        $this->assertSame('../../app/vendor/mgamadeus/ddd-ai/.claude/skills/ddd-entity-specialist', readlink("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertNotEmpty(array_filter($linkRun->getProblems(), static fn(string $problem): bool => str_contains($problem, 'shipped by mgamadeus/ddd-ai')));
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testEditedVendorSkillIsCaughtByCheck(): void
    {
        $root = $this->createNestedAppFixture();
        $this->runLinker($root)->run();
        file_put_contents("{$root}/app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist/SKILL.md", "# edited in vendor\n");

        $checkRun = $this->runLinker($root, checkOnly: true);
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $checkRun->run());
        $this->assertNotEmpty(array_filter($checkRun->getProblems(), static fn(string $problem): bool => str_starts_with($problem, 'edited') && str_contains($problem, 'ddd-entity-specialist')));
        // a normal run warns but keeps the recorded hash, so the check keeps failing until the package is reinstalled
        $this->runLinker($root)->run();
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $this->runLinker($root, checkOnly: true)->run());
    }

    public function testHandMadeTrackedLinksAreAdoptedAndReported(): void
    {
        if (!$this->isGitAvailable()) {
            $this->markTestSkipped('git is not available');
        }
        $root = $this->createNestedAppFixture(withGitFolder: false);
        $this->runGit($root, 'init', '-q');
        symlink('../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', "{$root}/.claude/skills/ddd-entity-specialist");
        $this->runGit($root, 'add', '.claude/skills/ddd-entity-specialist');

        $linkRun = $this->runLinker($root);
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $linkRun->run());
        $this->assertSame('../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist', readlink("{$root}/.claude/skills/ddd-entity-specialist"));
        $this->assertNotContains(
            'linked       .claude/skills/ddd-entity-specialist -> ../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist',
            $linkRun->getPrintedLines()
        );
        $trackedProblems = array_values(array_filter($linkRun->getProblems(), static fn(string $problem): bool => str_starts_with($problem, 'tracked')));
        $this->assertCount(1, $trackedProblems);
        $this->assertStringContainsString('git rm --cached -- .claude/skills/ddd-entity-specialist', $trackedProblems[0]);
        $this->assertSame(VendorSkillLinker::EXIT_FAILURE, $this->runLinker($root, checkOnly: true)->run());

        $this->runGit($root, 'rm', '-q', '--cached', '--', '.claude/skills/ddd-entity-specialist');
        $this->assertSame(VendorSkillLinker::EXIT_SUCCESS, $this->runLinker($root, checkOnly: true)->run());
    }

    /**
     * Fixture (a): repository root with `app/` (Composer root) and `web/` (frontend), `.claude/skills` a real folder
     * with an app skill, `.agents/skills` a real folder with a per-skill link, no `.codex`.
     */
    protected function createNestedAppFixture(bool $withAgentsFolder = true, bool $withClaudeFolder = true, bool $withGitFolder = true): string
    {
        $root = sys_get_temp_dir() . '/ddd-skill-links-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $root = (string)realpath($root);
        $this->fixtureRoot = $root;
        if ($withGitFolder) {
            mkdir("{$root}/.git");
        }
        $this->createSkill("{$root}/app/vendor/mgamadeus/ddd", 'ddd-entity-specialist');
        $this->createSkill("{$root}/app/vendor/mgamadeus/ddd", 'ddd-service-specialist');
        mkdir("{$root}/app/vendor/mgamadeus/ddd-common-money", 0777, true);
        mkdir("{$root}/app/vendor/composer", 0777, true);
        file_put_contents("{$root}/app/vendor/composer/installed.json", json_encode(['packages' => [
            ['name' => 'mgamadeus/ddd', 'version' => '3.36.0'],
            ['name' => 'mgamadeus/ddd-ai', 'version' => '1.9.0'],
        ]]));
        $this->createSkill("{$root}/web/node_modules/@mgamadeus/ddd-web", 'ddd-web-specialist');
        file_put_contents("{$root}/web/node_modules/@mgamadeus/ddd-web/package.json", json_encode(['name' => '@mgamadeus/ddd-web', 'version' => '0.17.0']));
        if ($withClaudeFolder) {
            mkdir("{$root}/.claude/skills/app-own-skill", 0777, true);
            file_put_contents("{$root}/.claude/skills/app-own-skill/SKILL.md", "# app-own-skill\n");
            file_put_contents("{$root}/.claude/skills/.gitignore", "/own-ignored-file\n");
        }
        if ($withAgentsFolder) {
            mkdir("{$root}/.agents/skills", 0777, true);
            symlink('../../.claude/skills/app-own-skill', "{$root}/.agents/skills/app-own-skill");
        }
        return $root;
    }

    protected function createSkill(string $packageDirectory, string $skillName): void
    {
        mkdir("{$packageDirectory}/.claude/skills/{$skillName}", 0777, true);
        file_put_contents("{$packageDirectory}/.claude/skills/{$skillName}/SKILL.md", "# {$skillName}\n");
    }

    protected function runLinker(string $root, bool $checkOnly = false, bool $dryRun = false): VendorSkillLinker
    {
        return new VendorSkillLinker($root, $checkOnly, $dryRun, false, null, null);
    }

    protected function isGitAvailable(): bool
    {
        exec('git --version 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    protected function runGit(string $root, string ...$arguments): void
    {
        $command = 'git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    protected function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff((array)scandir($directory), ['.', '..']) as $entryName) {
            $this->removeDirectory($directory . '/' . $entryName);
        }
        rmdir($directory);
    }
}
