<?php

declare(strict_types=1);

namespace DDD\Tests\Tools\Renames;

use DDD\Tools\Renames\RenameEntry;
use DDD\Tools\Renames\RenameHit;
use DDD\Tools\Renames\RenameMatcher;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/Renames/autoload.php';

/**
 * The boundary rule of every kind: whole names only, never a substring; certain versus ambiguous hits.
 */
class RenameMatcherTest extends TestCase
{
    public function testSkillNameMatchesTheWholeNameOnly(): void
    {
        $entry = $this->createEntry(RenameEntry::KIND_SKILL_NAME, 'Agent.Web', 'Web.Search');
        $content = "load 'Agent.Web', \"Agent.Web\", `Agent.Web`, [Agent.Web], Agent.Web. End\n"
            . "Agent.Web.Search x.Agent.Web Agent.Web-x Agent.Webs Agent.Web/x Agent.Web.md\n";
        self::assertSame(
            "load 'Web.Search', \"Web.Search\", `Web.Search`, [Web.Search], Web.Search. End\n"
            . "Agent.Web.Search x.Agent.Web Agent.Web-x Agent.Webs Agent.Web/x Agent.Web.md\n",
            $this->applyCertainHits($content, [$entry], 'docs/skills.md')
        );
        $deepEntry = $this->createEntry(RenameEntry::KIND_SKILL_NAME, 'Agent.Web.Search', 'Web.Deep');
        self::assertSame('Web.Deep and Web.Search', $this->applyCertainHits('Agent.Web.Search and Agent.Web', [$entry, $deepEntry], 'a.md'), 'both listed: each whole name on its own');
    }

    public function testKebabSkillNamesAndSingleWordsOnlyInQuotes(): void
    {
        $kebabEntry = $this->createEntry(RenameEntry::KIND_SKILL_NAME, 'ddd-web', 'ddd-research');
        self::assertSame('ddd-research, ddd-web-specialist, x-ddd-web', $this->applyCertainHits('ddd-web, ddd-web-specialist, x-ddd-web', [$kebabEntry], 'a.md'));

        $wordEntry = $this->createEntry(RenameEntry::KIND_SKILL_NAME, 'Search', 'Research');
        $hits = RenameMatcher::findHitsInContent("skills: ['Search']\nSearch the web\n", 'a.yaml', [$wordEntry]);
        self::assertCount(2, $hits);
        self::assertTrue($hits[0]->isCertain, 'a single word in quotes');
        self::assertFalse($hits[1]->isCertain, 'a single word in prose is ambiguous');
        self::assertSame(2, $hits[1]->lineNumber);
    }

    public function testClassMatchesFullyQualifiedEscapedAndImportedShortNames(): void
    {
        $entry = $this->createEntry(RenameEntry::KIND_CLASS, 'Acme\\Tools\\WebTool', 'Acme\\Tools\\ResearchTool');
        $content = <<<'PHP'
<?php
namespace App;

use Acme\Tools\WebTool;
use Acme\Tools\WebToolCase;

class A extends WebTool
{
    public function make(): WebTool
    {
        $WebTool = new \Acme\Tools\WebTool();
        $json = "Acme\\Tools\\WebTool";
        $other = [Other\Acme\Tools\WebTool::class, Acme\Tools\WebTool\Sub::class, WebToolCase::class, $this->WebTool, self::WebTool];
        return new WebTool();
    }
}
PHP;
        $expected = <<<'PHP'
<?php
namespace App;

use Acme\Tools\ResearchTool;
use Acme\Tools\WebToolCase;

class A extends ResearchTool
{
    public function make(): ResearchTool
    {
        $WebTool = new \Acme\Tools\ResearchTool();
        $json = "Acme\\Tools\\ResearchTool";
        $other = [Other\Acme\Tools\WebTool::class, Acme\Tools\WebTool\Sub::class, WebToolCase::class, $this->WebTool, self::WebTool];
        return new ResearchTool();
    }
}
PHP;
        self::assertSame($expected, $this->applyCertainHits($content, [$entry], 'src/A.php'));

        $aliased = "<?php\nnamespace App;\nuse Acme\\Tools\\WebTool as Tool;\nnew Tool(); new WebTool();\n";
        self::assertSame("<?php\nnamespace App;\nuse Acme\\Tools\\ResearchTool as Tool;\nnew Tool(); new WebTool();\n", $this->applyCertainHits($aliased, [$entry], 'src/B.php'), 'an alias stays');

        $notImported = "<?php\nnamespace App;\nnew WebTool();\n";
        self::assertSame($notImported, $this->applyCertainHits($notImported, [$entry], 'src/C.php'), 'a short name without import is another class');

        $movedEntry = $this->createEntry(RenameEntry::KIND_CLASS, 'Acme\\Tools\\WebTool', 'Acme\\Research\\ResearchTool');
        $hits = RenameMatcher::findHitsInContent("<?php\nnamespace Acme\\Tools;\nnew WebTool();\n", 'src/D.php', [$movedEntry]);
        self::assertCount(1, $hits);
        self::assertFalse($hits[0]->isCertain, 'same namespace, moved class: needs an import');

        $clash = "<?php\nnamespace App;\nuse Acme\\Tools\\WebTool;\nuse Other\\ResearchTool;\nnew WebTool();\n";
        $clashHits = RenameMatcher::findHitsInContent($clash, 'src/E.php', [$entry]);
        self::assertFalse($clashHits[1]->isCertain, 'the new short name is taken by another import');
    }

    public function testNamespaceMatchesAsPrefixAtSeparatorsOnly(): void
    {
        $entry = $this->createEntry(RenameEntry::KIND_NAMESPACE, 'Acme\\Legacy', 'Acme\\Classic');
        $content = "use Acme\\Legacy\\Memory;\nnamespace Acme\\Legacy;\n'@Acme\\Legacy\\Memory' \"Acme\\\\Legacy\\\\Memory\" Acme\\LegacyTools\\X Other\\Acme\\Legacy\\Y \\Acme\\Legacy\\Z\n";
        $expected = "use Acme\\Classic\\Memory;\nnamespace Acme\\Classic;\n'@Acme\\Classic\\Memory' \"Acme\\\\Classic\\\\Memory\" Acme\\LegacyTools\\X Other\\Acme\\Legacy\\Y \\Acme\\Classic\\Z\n";
        self::assertSame($expected, $this->applyCertainHits($content, [$entry], 'src/A.php'));
    }

    public function testRenamedClassWinsOverItsRenamedNamespace(): void
    {
        $classEntry = $this->createEntry(RenameEntry::KIND_CLASS, 'Acme\\Legacy\\Memory', 'Acme\\Memory\\Store');
        $namespaceEntry = $this->createEntry(RenameEntry::KIND_NAMESPACE, 'Acme\\Legacy', 'Acme\\Classic');
        self::assertSame(
            'Acme\\Memory\\Store Acme\\Classic\\Other',
            $this->applyCertainHits('Acme\\Legacy\\Memory Acme\\Legacy\\Other', [$namespaceEntry, $classEntry], 'a.md')
        );
    }

    public function testConstantMatchesWhereTheFileProvesTheClass(): void
    {
        $entry = $this->createEntry(RenameEntry::KIND_CONSTANT, 'Acme\\AgentSkills::WEB', 'Acme\\AgentSkills::WEB_RESEARCH');
        $content = <<<'PHP'
<?php
namespace App;

use Acme\AgentSkills;
use Acme\AgentSkills as Skills;

class A
{
    public array $skills = [AgentSkills::WEB, Skills::WEB, \Acme\AgentSkills::WEB, AgentSkills::WEB_PAGES, Other::WEB, AgentSkills::WEB()];
}
PHP;
        $expected = str_replace(
            '[AgentSkills::WEB, Skills::WEB, \Acme\AgentSkills::WEB,',
            '[AgentSkills::WEB_RESEARCH, Skills::WEB_RESEARCH, \Acme\AgentSkills::WEB_RESEARCH,',
            $content
        );
        self::assertSame($expected, $this->applyCertainHits($content, [$entry], 'src/A.php'));

        $subclass = "<?php\nnamespace App;\nuse Acme\\AgentSkills;\nclass MySkills extends AgentSkills { public array \$x = [self::WEB, static::WEB, parent::WEB]; }\n";
        self::assertSame(
            "<?php\nnamespace App;\nuse Acme\\AgentSkills;\nclass MySkills extends AgentSkills { public array \$x = [self::WEB_RESEARCH, static::WEB_RESEARCH, parent::WEB_RESEARCH]; }\n",
            $this->applyCertainHits($subclass, [$entry], 'src/MySkills.php')
        );

        $ownConstant = "<?php\nnamespace App;\nclass Plain { public const WEB = 1; public int \$x = self::WEB; }\n";
        self::assertSame([], RenameMatcher::findHitsInContent($ownConstant, 'src/Plain.php', [$entry]), 'a constant of the class itself');

        $otherParent = "<?php\nnamespace App;\nclass Deep extends MySkills { public array \$x = [self::WEB]; }\n";
        $hits = RenameMatcher::findHitsInContent($otherParent, 'src/Deep.php', [$entry]);
        self::assertCount(1, $hits);
        self::assertFalse($hits[0]->isCertain, 'inherited through another parent: ambiguous');

        $notImported = "<?php\nnamespace App;\n\$x = AgentSkills::WEB;\n";
        self::assertFalse(RenameMatcher::findHitsInContent($notImported, 'src/F.php', [$entry])[0]->isCertain);

        $escaped = "{{ constant('Acme\\\\AgentSkills::WEB') }} AgentSkills::WEB";
        $escapedHits = RenameMatcher::findHitsInContent($escaped, 'templates/a.twig', [$entry]);
        self::assertCount(2, $escapedHits);
        self::assertTrue($escapedHits[0]->isCertain);
        self::assertFalse($escapedHits[1]->isCertain, 'a short reference outside PHP');
    }

    public function testConfigKeyIsRenamedWithItsSubKeys(): void
    {
        $entry = $this->createEntry(RenameEntry::KIND_CONFIG_KEY, 'Settings.webSearch', 'Settings.research');
        self::assertSame(
            "Config::get('Settings.research'); Config::get('Settings.research.enabled'); 'Settings.webSearchX' 'App.Settings.webSearch'",
            $this->applyCertainHits("Config::get('Settings.webSearch'); Config::get('Settings.webSearch.enabled'); 'Settings.webSearchX' 'App.Settings.webSearch'", [$entry], 'src/A.php')
        );
    }

    public function testConsoleCommandPackageIdAndEnvVarBoundaries(): void
    {
        $commandEntry = $this->createEntry(RenameEntry::KIND_CONSOLE_COMMAND, 'app:agents:web', 'app:agents:research');
        self::assertSame(
            'bin/console app:agents:research; app:agents:web:all; app:agents:web-x; x:app:agents:web',
            $this->applyCertainHits('bin/console app:agents:web; app:agents:web:all; app:agents:web-x; x:app:agents:web', [$commandEntry], 'a.sh')
        );
        $packageIdEntry = $this->createEntry(RenameEntry::KIND_PACKAGE_ID, 'acme.agent.web', 'acme.web.research');
        self::assertSame("'acme.web.research', 'acme.agent.web.extra'", $this->applyCertainHits("'acme.agent.web', 'acme.agent.web.extra'", [$packageIdEntry], 'a.php'));

        $envEntry = $this->createEntry(RenameEntry::KIND_ENV_VAR, 'SETTINGS_URL', 'SETTINGS_ENDPOINT');
        self::assertSame(
            "SETTINGS_ENDPOINT=x\n%env(SETTINGS_ENDPOINT)% \$SETTINGS_ENDPOINT SETTINGS_URL_BACKUP MY_SETTINGS_URL",
            $this->applyCertainHits("SETTINGS_URL=x\n%env(SETTINGS_URL)% \$SETTINGS_URL SETTINGS_URL_BACKUP MY_SETTINGS_URL", [$envEntry], '.env')
        );
        $plainEnvEntry = $this->createEntry(RenameEntry::KIND_ENV_VAR, 'TOKEN', 'API_TOKEN');
        $hits = RenameMatcher::findHitsInContent("TOKEN=1 \${TOKEN} a TOKEN here", '.env', [$plainEnvEntry]);
        self::assertSame([false, true, false], array_map(static fn(RenameHit $hit): bool => $hit->isCertain, $hits));
    }

    public function testPathsNamedAfterAnOldDottedNameAreReported(): void
    {
        $skillEntry = $this->createEntry(RenameEntry::KIND_SKILL_NAME, 'Agent.Web', 'Web.Search');
        $configEntry = $this->createEntry(RenameEntry::KIND_CONFIG_KEY, 'AI.webSearch', 'AI.research');
        self::assertCount(1, RenameMatcher::findPathHits('skills/Agent/Web.md', [$skillEntry, $configEntry]));
        self::assertCount(1, RenameMatcher::findPathHits('docs/Agent.Web.md', [$skillEntry]));
        self::assertCount(1, RenameMatcher::findPathHits('config/app/AI/webSearch.php', [$configEntry]));
        self::assertSame([], RenameMatcher::findPathHits('skills/Agent/Web/Search.md', [$skillEntry]), 'a folder of sub-skills is no file of the skill');
        self::assertSame([], RenameMatcher::findPathHits('skills/Agent/WebSearch.md', [$skillEntry]));
        self::assertSame(RenameHit::TYPE_PATH, RenameMatcher::findPathHits('skills/Agent/Web.md', [$skillEntry])[0]->type);
    }

    protected function createEntry(string $kind, string $oldName, string $newName): RenameEntry
    {
        return new RenameEntry('acme/test', '2.0.0', $kind, $oldName, $newName);
    }

    /**
     * @param RenameEntry[] $renameEntries
     */
    protected function applyCertainHits(string $content, array $renameEntries, string $relativeFilePath): string
    {
        return RenameMatcher::replaceCertainHits($content, RenameMatcher::findHitsInContent($content, $relativeFilePath, $renameEntries));
    }
}
