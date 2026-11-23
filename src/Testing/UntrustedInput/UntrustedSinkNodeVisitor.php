<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the raw sink calls of one file (names resolved by a NameResolver that runs before it).
 */
class UntrustedSinkNodeVisitor extends NodeVisitorAbstract
{
    /** @var string[] Function name (lower case) => sink */
    public const array SINK_FUNCTIONS = [
        'exec' => UntrustedSinkScanner::SINK_SHELL,
        'shell_exec' => UntrustedSinkScanner::SINK_SHELL,
        'system' => UntrustedSinkScanner::SINK_SHELL,
        'passthru' => UntrustedSinkScanner::SINK_SHELL,
        'popen' => UntrustedSinkScanner::SINK_SHELL,
        'proc_open' => UntrustedSinkScanner::SINK_SHELL,
        'pcntl_exec' => UntrustedSinkScanner::SINK_SHELL,
        'curl_init' => UntrustedSinkScanner::SINK_HTTP,
        'curl_exec' => UntrustedSinkScanner::SINK_HTTP,
        'get_headers' => UntrustedSinkScanner::SINK_HTTP,
        'simplexml_load_string' => UntrustedSinkScanner::SINK_XML,
        'simplexml_load_file' => UntrustedSinkScanner::SINK_XML,
        'create_function' => UntrustedSinkScanner::SINK_EVAL,
    ];

    /** @var string[] Method name (lower case) => sink, for any object */
    public const array SINK_METHODS = [
        'readimageblob' => UntrustedSinkScanner::SINK_IMAGICK,
        'readimage' => UntrustedSinkScanner::SINK_IMAGICK,
        'readimagefile' => UntrustedSinkScanner::SINK_IMAGICK,
        'readimages' => UntrustedSinkScanner::SINK_IMAGICK,
        'pingimage' => UntrustedSinkScanner::SINK_IMAGICK,
        'pingimageblob' => UntrustedSinkScanner::SINK_IMAGICK,
        'pingimagefile' => UntrustedSinkScanner::SINK_IMAGICK,
        'loadxml' => UntrustedSinkScanner::SINK_XML,
        'loadhtml' => UntrustedSinkScanner::SINK_XML,
        'loadhtmlfile' => UntrustedSinkScanner::SINK_XML,
        'extractto' => UntrustedSinkScanner::SINK_ARCHIVE,
        'createtemplate' => UntrustedSinkScanner::SINK_TEMPLATE,
        'setcellvalue' => UntrustedSinkScanner::SINK_SPREADSHEET,
        'fromarray' => UntrustedSinkScanner::SINK_SPREADSHEET,
    ];

    /** @var string[] Class (lower case, fully qualified) => sink, for `new` */
    public const array SINK_CLASSES = [
        'simplexmlelement' => UntrustedSinkScanner::SINK_XML,
        'xmlreader' => UntrustedSinkScanner::SINK_XML,
        'phardata' => UntrustedSinkScanner::SINK_ARCHIVE,
        'phar' => UntrustedSinkScanner::SINK_ARCHIVE,
        'symfony\\component\\process\\process' => UntrustedSinkScanner::SINK_PROCESS,
        'guzzlehttp\\client' => UntrustedSinkScanner::SINK_HTTP,
        'dompdf\\dompdf' => UntrustedSinkScanner::SINK_DOMPDF,
    ];

    /** @var string[] `Class::method` (lower case) => sink, for static calls */
    public const array SINK_STATIC_CALLS = [
        'symfony\\component\\process\\process::fromshellcommandline' => UntrustedSinkScanner::SINK_SHELL,
        'symfony\\component\\httpclient\\httpclient::create' => UntrustedSinkScanner::SINK_HTTP,
        'xmlreader::xml' => UntrustedSinkScanner::SINK_XML,
        'xmlreader::open' => UntrustedSinkScanner::SINK_XML,
        'symfony\\component\\yaml\\yaml::parse' => UntrustedSinkScanner::SINK_YAML,
        'symfony\\component\\yaml\\yaml::parsefile' => UntrustedSinkScanner::SINK_YAML,
    ];

    /** @var string[] File functions that are a sink when an argument reads a property of a request DTO */
    public const array FILE_FUNCTIONS = [
        'file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'file_exists', 'is_file', 'getimagesize', 'unlink',
        'file_put_contents', 'opendir', 'scandir', 'glob', 'realpath', 'filesize', 'mime_content_type',
    ];

    /** @var UntrustedSinkFindings The calls found so far */
    public UntrustedSinkFindings $untrustedSinkFindings;

    public function __construct(protected string $relativeFilePath)
    {
        $this->untrustedSinkFindings = new UntrustedSinkFindings();
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Expr\ShellExec) {
            $this->addFinding($node, UntrustedSinkScanner::SINK_SHELL, 'backticks');
        } elseif ($node instanceof Expr\Eval_) {
            $this->addFinding($node, UntrustedSinkScanner::SINK_EVAL, 'eval');
        } elseif ($node instanceof FuncCall && $node->name instanceof Name) {
            $this->inspectFunctionCall($node, strtolower($node->name->toString()));
        } elseif (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $methodName = strtolower($node->name->toString());
            if (isset(static::SINK_METHODS[$methodName])) {
                $this->addFinding($node, static::SINK_METHODS[$methodName], '->' . $node->name->toString() . '()');
            }
        } elseif ($node instanceof New_ && $node->class instanceof Name) {
            $className = strtolower(ltrim($node->class->toString(), '\\'));
            if (isset(static::SINK_CLASSES[$className])) {
                $this->addFinding($node, static::SINK_CLASSES[$className], 'new ' . $node->class->toString());
            }
        } elseif ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $staticCallName = strtolower(ltrim($node->class->toString(), '\\')) . '::' . strtolower($node->name->toString());
            if (isset(static::SINK_STATIC_CALLS[$staticCallName])) {
                $this->addFinding($node, static::SINK_STATIC_CALLS[$staticCallName], $node->class->toString() . '::' . $node->name->toString() . '()');
            }
        }
        return null;
    }

    /**
     * @param FuncCall $funcCall
     * @param string $functionName lower case, namespace stripped by the caller when it is a fallback global call
     * @return void
     */
    protected function inspectFunctionCall(FuncCall $funcCall, string $functionName): void
    {
        $functionName = str_contains($functionName, '\\') ? substr($functionName, (int)strrpos($functionName, '\\') + 1) : $functionName;
        if (isset(static::SINK_FUNCTIONS[$functionName])) {
            $this->addFinding($funcCall, static::SINK_FUNCTIONS[$functionName], $functionName . '()');
            return;
        }
        if ($functionName === 'unserialize' && !$this->hasAllowedClassesOption($funcCall)) {
            $this->addFinding($funcCall, UntrustedSinkScanner::SINK_UNSERIALIZE, 'unserialize() without allowed_classes');
            return;
        }
        if (in_array($functionName, static::FILE_FUNCTIONS, true) && $this->readsRequestDtoProperty($funcCall)) {
            $this->addFinding($funcCall, UntrustedSinkScanner::SINK_FILE_PATH_FROM_REQUEST, $functionName . '() with a request DTO value');
        }
    }

    /**
     * @param FuncCall $funcCall
     * @return bool the second argument is an array literal with an `allowed_classes` key
     */
    protected function hasAllowedClassesOption(FuncCall $funcCall): bool
    {
        $optionsArgument = $funcCall->args[1] ?? null;
        if (!$optionsArgument instanceof Arg || !$optionsArgument->value instanceof Array_) {
            return false;
        }
        foreach ($optionsArgument->value->items as $arrayItem) {
            if ($arrayItem !== null && $arrayItem->key instanceof String_ && $arrayItem->key->value === 'allowed_classes') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param FuncCall $funcCall
     * @return bool an argument reads a property of a variable named `$requestDto` or `$…Dto`
     */
    protected function readsRequestDtoProperty(FuncCall $funcCall): bool
    {
        $nodeFinder = new NodeFinder();
        foreach ($funcCall->args as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            $propertyFetches = $nodeFinder->findInstanceOf($argument->value, PropertyFetch::class);
            foreach ($propertyFetches as $propertyFetch) {
                if ($propertyFetch->var instanceof Variable && is_string($propertyFetch->var->name)
                    && preg_match('/(^request|Dto$)/i', $propertyFetch->var->name) === 1
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param Node $node
     * @param string $sink
     * @param string $callDescription
     * @return void
     */
    protected function addFinding(Node $node, string $sink, string $callDescription): void
    {
        $untrustedSinkFinding = new UntrustedSinkFinding();
        $untrustedSinkFinding->filePath = $this->relativeFilePath;
        $untrustedSinkFinding->line = $node->getStartLine();
        $untrustedSinkFinding->sink = $sink;
        $untrustedSinkFinding->callDescription = $callDescription;
        $this->untrustedSinkFindings->add($untrustedSinkFinding);
    }
}
