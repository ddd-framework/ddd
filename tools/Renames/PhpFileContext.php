<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

/**
 * What a PHP file says about the names it uses: its namespace, its imports, the class it declares and extends, the
 * constants it declares. Read with regular expressions (no parser, no autoloading): enough to tell whether `Short` or
 * `Short::CONSTANT` in the file means a renamed class.
 */
class PhpFileContext
{
    /**
     * @param array<string, string> $importedClassNamesByAlias alias (or short name) => fully qualified name
     * @param string[] $declaredConstantNames
     */
    public function __construct(
        public string $namespace = '',
        public array $importedClassNamesByAlias = [],
        public ?string $extendedClassName = null,
        public array $declaredConstantNames = [],
    ) {
    }

    public static function createFromSource(string $source): static
    {
        $phpFileContext = new static();
        if (preg_match('/^\s*namespace\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/m', $source, $namespaceMatch)) {
            $phpFileContext->namespace = $namespaceMatch[1];
        }
        preg_match_all('/^\s*use\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $source, $useMatches, PREG_SET_ORDER);
        foreach ($useMatches as $useMatch) {
            $alias = ($useMatch[2] ?? '') !== '' ? $useMatch[2] : static::getShortName($useMatch[1]);
            $phpFileContext->importedClassNamesByAlias[$alias] = $useMatch[1];
        }
        if (preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $source, $extendsMatch)) {
            $phpFileContext->extendedClassName = $phpFileContext->resolveClassName($extendsMatch[1]);
        }
        preg_match_all('/\bconst\s+(?:[A-Za-z_?|\\\\][A-Za-z0-9_?|\\\\]*\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $source, $constantMatches);
        $phpFileContext->declaredConstantNames = $constantMatches[1];
        return $phpFileContext;
    }

    /** The fully qualified name a class reference in this file means (PHP's resolution rules for class names) */
    public function resolveClassName(string $classReference): string
    {
        if (str_starts_with($classReference, '\\')) {
            return substr($classReference, 1);
        }
        $firstSegment = explode('\\', $classReference, 2)[0];
        if (isset($this->importedClassNamesByAlias[$firstSegment])) {
            return $this->importedClassNamesByAlias[$firstSegment] . substr($classReference, strlen($firstSegment));
        }
        return $this->namespace === '' ? $classReference : $this->namespace . '\\' . $classReference;
    }

    /** Whether the file imports a class under its own short name (`use A\B\C;`, not `use A\B\C as D;`) */
    public function importsClassUnderShortName(string $className): bool
    {
        return ($this->importedClassNamesByAlias[static::getShortName($className)] ?? null) === $className;
    }

    public static function getShortName(string $className): string
    {
        $position = strrpos($className, '\\');
        return $position === false ? $className : substr($className, $position + 1);
    }

    public static function getNamespaceOfClassName(string $className): string
    {
        $position = strrpos($className, '\\');
        return $position === false ? '' : substr($className, 0, $position);
    }
}
