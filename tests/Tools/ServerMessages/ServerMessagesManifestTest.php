<?php

declare(strict_types=1);

namespace DDD\Tests\Tools\ServerMessages;

use DDD\Tools\ServerMessages\ServerMessagesManifest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/ServerMessages/ServerMessagesManifest.php';

/**
 * The server messages manifest of a package: which literals count as messages, hints kept across builds, the check,
 * the exclusions and the merge of the installed packages.
 */
class ServerMessagesManifestTest extends TestCase
{
    protected ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            exec('rm -rf ' . escapeshellarg($this->fixtureRoot));
            $this->fixtureRoot = null;
        }
    }

    public function testUserFacingExceptionsConstantsAndReasonsAreMessagesInternalOnesAndCodesAreNot(): void
    {
        $source = <<<'PHP'
<?php
class OrdersService
{
    public const string ERROR_CODE_LIMIT = 'The limit code text';
    public const string EMPTY_ORDER_MESSAGE = 'An order needs at least one line.';
    public const string PUSH_MESSAGE = 'Your order shipped today';
    public function placeOrder(): void
    {
        throw new BadRequestException('The basket is empty.');
    }
    public function findOrder(): void
    {
        throw new NotFoundException("Order not found.");
        throw new InternalErrorException('The database refused the write.');
        throw new ForbiddenException('ORDER_LOCKED');
    }
    public function findCancelRefusalReason(): string
    {
        return 'it has shipped already';
    }
    public function describeOrder(): string
    {
        return 'an ordinary return value';
    }
}
PHP;
        $messages = ServerMessagesManifest::findServerMessagesOfSource($source, 'OrdersService');
        $texts = array_column($messages, 0);
        sort($texts);
        self::assertSame(
            ['An order needs at least one line.', 'Order not found.', 'The basket is empty.', 'it has shipped already'],
            $texts
        );
        self::assertContains(['The basket is empty.', 'OrdersService::placeOrder'], $messages);
    }

    public function testBuildKeepsHintsDropsGoneKeysAndTheCheckFailsOnShortHintsAndOutdatedFiles(): void
    {
        $packageDirectory = $this->createPackage([
            'src/Domain/Orders/Services/OrdersService.php' => "<?php\nclass OrdersService { public function placeOrder(): void { throw new BadRequestException('The basket is empty.'); } }\n",
        ]);
        self::assertSame(ServerMessagesManifest::EXIT_SUCCESS, $this->runQuietly(['build', '--package-dir', $packageDirectory]));
        self::assertSame(ServerMessagesManifest::EXIT_FAILURE, $this->runQuietly(['build', '--package-dir', $packageDirectory, '--check']), 'an empty hint fails the check');

        $manifestFile = $packageDirectory . '/' . ServerMessagesManifest::MANIFEST_PATH;
        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        self::assertSame('acme/orders', $manifest['package']);
        $manifest['keys'][0]['hint'] = 'Error message when an order is placed with an empty basket, shown above the order form.';
        $manifest['keys'][] = ['key' => 'A message that is gone.', 'text' => 'A message that is gone.', 'hint' => 'x', 'origins' => []];
        file_put_contents($manifestFile, json_encode($manifest));
        self::assertSame(ServerMessagesManifest::EXIT_FAILURE, $this->runQuietly(['build', '--package-dir', $packageDirectory, '--check']), 'a file that differs from the build is outdated');

        self::assertSame(ServerMessagesManifest::EXIT_SUCCESS, $this->runQuietly(['build', '--package-dir', $packageDirectory]));
        $rebuilt = json_decode((string)file_get_contents($manifestFile), true);
        self::assertSame(['The basket is empty.'], array_column($rebuilt['keys'], 'key'));
        self::assertStringStartsWith('Error message when an order', $rebuilt['keys'][0]['hint']);
        self::assertSame(ServerMessagesManifest::EXIT_SUCCESS, $this->runQuietly(['build', '--package-dir', $packageDirectory, '--check']));
    }

    public function testToolsWorkersAndExcludedFilesAreNotScanned(): void
    {
        $packageDirectory = $this->createPackage([
            'src/Domain/Orders/Services/OrdersService.php' => "<?php\nclass OrdersService { public function a(): void { throw new BadRequestException('The basket is empty.'); } }\n",
            'src/Presentation/Mcp/Orders/OrdersMCPPackage.php' => "<?php\nclass OrdersMCPPackage { public function b(): void { throw new BadRequestException('Name the order id for the tool.'); } }\n",
            'src/Domain/Orders/MessageHandlers/OrderHandler.php' => "<?php\nclass OrderHandler { public function c(): void { throw new NotFoundException('The order of the message is gone.'); } }\n",
            'src/Domain/Orders/Services/OrdersImportService.php' => "<?php\nclass OrdersImportService { public function d(): void { throw new BadRequestException('The import file is broken.'); } }\n",
            'src/Domain/Orders/Services/OrdersDebugService.php' => "<?php\n// @server-messages-ignore-file\nclass OrdersDebugService { public function e(): void { throw new BadRequestException('Debug output is off.'); } }\n",
        ]);
        mkdir($packageDirectory . '/i18n');
        file_put_contents($packageDirectory . '/' . ServerMessagesManifest::MANIFEST_PATH, json_encode(['excludedFiles' => ['/OrdersImportService\.php$'], 'keys' => []]));
        $manifest = ServerMessagesManifest::buildManifest($packageDirectory, json_decode((string)file_get_contents($packageDirectory . '/' . ServerMessagesManifest::MANIFEST_PATH), true));
        self::assertSame(['The basket is empty.'], array_column($manifest['keys'], 'key'));
        self::assertSame(['/OrdersImportService\.php$'], $manifest['excludedFiles']);
    }

    public function testCollectMergesTheInstalledPackagesWithTheLongestHint(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/ddd-server-messages-' . bin2hex(random_bytes(4));
        $vendorDirectory = $this->fixtureRoot . '/vendor';
        foreach (['ddd-orders' => 'A short hint that still has forty characters.', 'ddd-invoices' => 'The longer hint of the second package wins over the shorter one.'] as $packageName => $hint) {
            mkdir($vendorDirectory . '/mgamadeus/' . $packageName . '/i18n', 0775, true);
            file_put_contents($vendorDirectory . '/mgamadeus/' . $packageName . '/' . ServerMessagesManifest::MANIFEST_PATH, json_encode([
                'package' => 'mgamadeus/' . $packageName,
                'keys' => [['key' => 'Not found.', 'text' => 'Not found.', 'hint' => $hint, 'origins' => [$packageName . 'Service::find']]],
            ]));
        }
        mkdir($vendorDirectory . '/mgamadeus/ddd-without-manifest', 0775, true);
        $collected = ServerMessagesManifest::collectInstalledPackages($vendorDirectory);
        self::assertSame(['mgamadeus/ddd-invoices', 'mgamadeus/ddd-orders'], $collected['packages']);
        self::assertCount(1, $collected['keys']);
        self::assertStringStartsWith('The longer hint', $collected['keys'][0]['hint']);
        self::assertSame(['mgamadeus/ddd-invoices', 'mgamadeus/ddd-orders'], $collected['keys'][0]['packages']);
        self::assertCount(2, $collected['keys'][0]['origins']);
    }

    /**
     * @param array<string, string> $files path relative to the package => content
     */
    protected function createPackage(array $files): string
    {
        $this->fixtureRoot ??= sys_get_temp_dir() . '/ddd-server-messages-' . bin2hex(random_bytes(4));
        $packageDirectory = $this->fixtureRoot . '/package';
        mkdir($packageDirectory, 0775, true);
        file_put_contents($packageDirectory . '/composer.json', json_encode(['name' => 'acme/orders']));
        foreach ($files as $relativePath => $content) {
            if (!is_dir(dirname($packageDirectory . '/' . $relativePath))) {
                mkdir(dirname($packageDirectory . '/' . $relativePath), 0775, true);
            }
            file_put_contents($packageDirectory . '/' . $relativePath, $content);
        }
        return $packageDirectory;
    }

    /**
     * @param string[] $arguments
     */
    protected function runQuietly(array $arguments): int
    {
        $command = 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/ddd-server-messages') . ' '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
        exec($command, $output, $exitCode);
        return $exitCode;
    }
}
