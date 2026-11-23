<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Finds raw calls to the sinks of foreign input in PHP sources: interpreters (Imagick decode, XML/HTML parsers, YAML
 * with flags, Twig templates from strings, Dompdf, eval, unserialize without `allowed_classes`), processes (shell
 * functions, backticks, `Process::fromShellCommandline`, `new Process`), outbound HTTP clients (curl, Guzzle, Symfony
 * HttpClient, get_headers), archive extraction, spreadsheet cell writes, and file functions fed from a request DTO.
 *
 * A finding is not a bug: it is a call that must go through the helpers of `DDD\Infrastructure\Untrusted` or be
 * listed, with its reason, in the package's allow-list ({@see UntrustedSinkScanTestCase}).
 */
class UntrustedSinkScanner
{
    public const string SINK_IMAGICK = 'imagick-decode';
    public const string SINK_XML = 'xml-parse';
    public const string SINK_YAML = 'yaml-parse';
    public const string SINK_TEMPLATE = 'template-from-string';
    public const string SINK_DOMPDF = 'dompdf';
    public const string SINK_EVAL = 'eval';
    public const string SINK_UNSERIALIZE = 'unserialize';
    public const string SINK_SHELL = 'shell';
    public const string SINK_PROCESS = 'process';
    public const string SINK_HTTP = 'http-client';
    public const string SINK_ARCHIVE = 'archive-extract';
    public const string SINK_SPREADSHEET = 'spreadsheet-cell';
    public const string SINK_FILE_PATH_FROM_REQUEST = 'file-path-from-request';

    /**
     * @param string $packageRootDirectory
     * @param string[] $sourceDirectories relative to the root
     * @return UntrustedSinkFindings
     */
    public function scan(string $packageRootDirectory, array $sourceDirectories = ['src']): UntrustedSinkFindings
    {
        $untrustedSinkFindings = new UntrustedSinkFindings();
        $packageRootDirectory = rtrim((string)realpath($packageRootDirectory), '/');
        foreach ($sourceDirectories as $sourceDirectory) {
            $absoluteSourceDirectory = $packageRootDirectory . '/' . trim($sourceDirectory, '/');
            if (!is_dir($absoluteSourceDirectory)) {
                continue;
            }
            foreach ($this->findPhpFiles($absoluteSourceDirectory) as $phpFilePath) {
                $relativeFilePath = ltrim(substr($phpFilePath, strlen($packageRootDirectory)), '/');
                foreach ($this->scanFile($phpFilePath, $relativeFilePath)->getElements() as $untrustedSinkFinding) {
                    $untrustedSinkFindings->add($untrustedSinkFinding);
                }
            }
        }
        return $untrustedSinkFindings;
    }

    /**
     * @param string $phpFilePath
     * @param string $relativeFilePath
     * @return UntrustedSinkFindings
     */
    public function scanFile(string $phpFilePath, string $relativeFilePath): UntrustedSinkFindings
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $statements = $parser->parse((string)file_get_contents($phpFilePath));
        } catch (Error $parseError) {
            throw new RuntimeException("{$relativeFilePath} cannot be parsed: " . $parseError->getMessage());
        }
        if ($statements === null) {
            return new UntrustedSinkFindings();
        }
        $untrustedSinkNodeVisitor = new UntrustedSinkNodeVisitor($relativeFilePath);
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new NameResolver());
        $nodeTraverser->addVisitor($untrustedSinkNodeVisitor);
        $nodeTraverser->traverse($statements);
        return $untrustedSinkNodeVisitor->untrustedSinkFindings;
    }

    /**
     * @param string $directory
     * @return string[] absolute paths, sorted
     */
    protected function findPhpFiles(string $directory): array
    {
        $phpFilePaths = [];
        $recursiveIteratorIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($recursiveIteratorIterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                $phpFilePaths[] = $fileInfo->getPathname();
            }
        }
        sort($phpFilePaths);
        return $phpFilePaths;
    }
}
