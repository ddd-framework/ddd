<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

/**
 * Finds the old names of rename entries in a file's text, whole names only, with a boundary rule per kind:
 *
 * | Kind | Matches | Never matches |
 * |---|---|---|
 * | class | `A\B\C`, `\A\B\C`, `A\\B\\C` (escaped in JSON / double quotes); in a PHP file that imports it, the short name | `X\A\B\C`, `A\B\CD`, `A\B\C\D` |
 * | namespace | `A\B`, `A\B\Anything` (prefix) | `X\A\B`, `A\BC` |
 * | constant | `A\B\C::OLD`, `C::OLD` and `self/static/parent::OLD` where the file proves `C` is meant | `C::OLD_MORE`, `D::OLD` |
 * | skillName, packageId | `Agent.Web` delimited by quotes, whitespace, punctuation | `Agent.Web.Search`, `x.Agent.Web`, `Agent.Web-x` |
 * | configKey | `AI.web`, `AI.web.sub` (prefix) | `AI.webSearch`, `x.AI.web` |
 * | consoleCommand | `app:foo` | `app:foo:bar`, `x:app:foo` |
 * | envVar | `OLD_VAR` | `OLD_VAR_X`, `X_OLD_VAR` |
 *
 * A hit is certain when the match proves the meaning; it is ambiguous (reported, never written) when the old name is a
 * single plain word outside quotes, a short class or constant reference the file does not import, or an inherited
 * constant whose parent is not the renamed class. Overlapping hits keep the longest (a renamed class wins over its
 * renamed namespace).
 */
class RenameMatcher
{
    protected const string IDENTIFIER_CHARACTER = 'A-Za-z0-9_';

    /**
     * All non-overlapping hits of the entries (of one version) in a file's content.
     *
     * @param RenameEntry[] $renameEntries
     * @return RenameHit[] sorted by offset
     */
    public static function findHitsInContent(string $content, string $relativeFilePath, array $renameEntries): array
    {
        $isPhpFile = str_ends_with($relativeFilePath, '.php');
        $phpFileContext = null;
        $hits = [];
        foreach ($renameEntries as $renameEntry) {
            if (!str_contains($content, static::getPrefilterToken($renameEntry))) {
                continue;
            }
            if ($isPhpFile && $phpFileContext === null) {
                $phpFileContext = PhpFileContext::createFromSource($content);
            }
            array_push($hits, ...match ($renameEntry->kind) {
                RenameEntry::KIND_CLASS, RenameEntry::KIND_NAMESPACE => static::findPhpNameHits($content, $relativeFilePath, $renameEntry, $phpFileContext),
                RenameEntry::KIND_CONSTANT => static::findConstantHits($content, $relativeFilePath, $renameEntry, $phpFileContext),
                default => static::findDelimitedNameHits($content, $relativeFilePath, $renameEntry),
            });
        }
        $hits = static::removeOverlappingHits($hits);
        foreach ($hits as $hit) {
            $hit->locateInContent($content);
        }
        return $hits;
    }

    /**
     * The content with every certain text hit replaced.
     *
     * @param RenameHit[] $hits non-overlapping hits found in this content
     */
    public static function replaceCertainHits(string $content, array $hits): string
    {
        usort($hits, static fn(RenameHit $first, RenameHit $second): int => $second->offset <=> $first->offset);
        foreach ($hits as $hit) {
            if ($hit->isCertain && $hit->type === RenameHit::TYPE_TEXT) {
                $content = substr_replace($content, $hit->replacementText, $hit->offset, strlen($hit->matchedText));
            }
        }
        return $content;
    }

    /**
     * Files named after the old name of a dotted name (`skills/Agent/Web.md`, `Agent.Web.md`,
     * `config/app/AI/webSearch.php` for the config key `AI.webSearch`): reported, never moved. Folders are not reported.
     *
     * @param RenameEntry[] $renameEntries
     * @return RenameHit[]
     */
    public static function findPathHits(string $relativeFilePath, array $renameEntries): array
    {
        $hits = [];
        $pathWithoutExtension = (string)preg_replace('/\.[A-Za-z0-9]+$/', '', $relativeFilePath);
        foreach ($renameEntries as $renameEntry) {
            if (!in_array($renameEntry->kind, [RenameEntry::KIND_SKILL_NAME, RenameEntry::KIND_PACKAGE_ID, RenameEntry::KIND_CONFIG_KEY], true)) {
                continue;
            }
            if (!str_contains($renameEntry->oldName, '.')) {
                continue;
            }
            $slashForm = str_replace('.', '/', $renameEntry->oldName);
            $matchesSlashForm = $pathWithoutExtension === $slashForm || str_ends_with($pathWithoutExtension, '/' . $slashForm);
            $matchesDottedFileName = basename($pathWithoutExtension) === $renameEntry->oldName;
            if ($matchesSlashForm || $matchesDottedFileName) {
                $hits[] = new RenameHit(
                    $renameEntry,
                    $relativeFilePath,
                    0,
                    $relativeFilePath,
                    '',
                    false,
                    'the path is spelled after the old name; move or rename it by hand',
                    type: RenameHit::TYPE_PATH,
                );
            }
        }
        return $hits;
    }

    /** A literal every hit of the entry contains: a file without it is skipped before any regular expression */
    protected static function getPrefilterToken(RenameEntry $renameEntry): string
    {
        return match ($renameEntry->kind) {
            RenameEntry::KIND_CLASS, RenameEntry::KIND_NAMESPACE => PhpFileContext::getShortName($renameEntry->oldName),
            RenameEntry::KIND_CONSTANT => (string)$renameEntry->getOldConstantName(),
            default => $renameEntry->oldName,
        };
    }

    /**
     * The regular expression of a fully qualified PHP name, with its namespace separators single (`A\B`) or escaped
     * (`A\\B`), optionally led by the root separator; the name itself is the named group `name`.
     */
    protected static function buildPhpNamePattern(string $phpName, bool $escapedSeparators, string $endBoundary): string
    {
        $separator = $escapedSeparators ? '\\\\\\\\' : '\\\\';
        $segments = array_map(static fn(string $segment): string => preg_quote($segment, '/'), explode('\\', $phpName));
        return '/(?<![' . self::IDENTIFIER_CHARACTER . '\\\\])(?:' . $separator . ')?(?<name>' . implode($separator, $segments) . ')' . $endBoundary . '/';
    }

    /**
     * @return RenameHit[]
     */
    protected static function findPhpNameHits(string $content, string $relativeFilePath, RenameEntry $renameEntry, ?PhpFileContext $phpFileContext): array
    {
        $hits = [];
        $isClass = $renameEntry->kind === RenameEntry::KIND_CLASS;
        $isSingleSegment = !str_contains($renameEntry->oldName, '\\');
        foreach ([false, true] as $escapedSeparators) {
            $separator = $escapedSeparators ? '\\\\\\\\' : '\\\\';
            $endBoundary = '(?![' . self::IDENTIFIER_CHARACTER . '])' . ($isClass ? '(?!' . $separator . '[A-Za-z_])' : '');
            $pattern = static::buildPhpNamePattern($renameEntry->oldName, $escapedSeparators, $endBoundary);
            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches['name'] as [$matchedText, $offset]) {
                $replacement = $escapedSeparators ? str_replace('\\', '\\\\', $renameEntry->newName) : $renameEntry->newName;
                $isCertain = true;
                $ambiguityReason = null;
                if ($isSingleSegment) {
                    $leadingCharacter = $offset > 0 ? $content[$offset - 1] : '';
                    $followingText = substr($content, $offset + strlen($matchedText), 1);
                    $isCertain = $leadingCharacter === '\\' || $followingText === '\\';
                    $ambiguityReason = $isCertain ? null : 'a name without namespace separator may be an ordinary word here';
                }
                $hits[] = new RenameHit($renameEntry, $relativeFilePath, $offset, $matchedText, $replacement, $isCertain, $ambiguityReason);
            }
        }
        if ($isClass && $phpFileContext !== null) {
            array_push($hits, ...static::findClassShortNameHits($content, $relativeFilePath, $renameEntry, $phpFileContext));
        }
        return $hits;
    }

    /**
     * The short name of a renamed class in a PHP file that imports it, or that lives in its namespace.
     *
     * @return RenameHit[]
     */
    protected static function findClassShortNameHits(string $content, string $relativeFilePath, RenameEntry $renameEntry, PhpFileContext $phpFileContext): array
    {
        $oldShortName = PhpFileContext::getShortName($renameEntry->oldName);
        $newShortName = PhpFileContext::getShortName($renameEntry->newName);
        if ($oldShortName === $newShortName) {
            return [];
        }
        $oldNamespace = PhpFileContext::getNamespaceOfClassName($renameEntry->oldName);
        $newNamespace = PhpFileContext::getNamespaceOfClassName($renameEntry->newName);
        if ($phpFileContext->importsClassUnderShortName($renameEntry->oldName)) {
            $clashingClassName = $phpFileContext->importedClassNamesByAlias[$newShortName] ?? null;
            $isCertain = $clashingClassName === null || $clashingClassName === $renameEntry->newName;
            $ambiguityReason = $isCertain ? null : "the file imports another {$newShortName} ({$clashingClassName})";
        } elseif ($phpFileContext->namespace === $oldNamespace) {
            $isCertain = $newNamespace === $oldNamespace;
            $ambiguityReason = $isCertain ? null : "used without import in its old namespace; the new class {$renameEntry->newName} needs an import";
        } else {
            return [];
        }
        $pattern = '/(?<![' . self::IDENTIFIER_CHARACTER . '\\\\$])(?<!->)(?<!::)' . preg_quote($oldShortName, '/') . '(?![' . self::IDENTIFIER_CHARACTER . '\\\\])/';
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        $hits = [];
        foreach ($matches[0] as [$matchedText, $offset]) {
            $hits[] = new RenameHit($renameEntry, $relativeFilePath, $offset, $matchedText, $newShortName, $isCertain, $ambiguityReason);
        }
        return $hits;
    }

    /**
     * @return RenameHit[]
     */
    protected static function findConstantHits(string $content, string $relativeFilePath, RenameEntry $renameEntry, ?PhpFileContext $phpFileContext): array
    {
        $className = (string)$renameEntry->getConstantClassName();
        $oldConstantName = (string)$renameEntry->getOldConstantName();
        $newConstantName = (string)$renameEntry->getNewConstantName();
        $constantEnd = '::(?<constant>' . preg_quote($oldConstantName, '/') . ')(?![' . self::IDENTIFIER_CHARACTER . '])';
        $hits = [];
        foreach ([false, true] as $escapedSeparators) {
            preg_match_all(static::buildPhpNamePattern($className, $escapedSeparators, $constantEnd), $content, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches['constant'] as [$matchedText, $offset]) {
                $hits[] = new RenameHit($renameEntry, $relativeFilePath, $offset, $matchedText, $newConstantName, true);
            }
        }
        $shortName = PhpFileContext::getShortName($className);
        $referencePattern = '/(?<![' . self::IDENTIFIER_CHARACTER . '\\\\$])(?<reference>[A-Za-z_][' . self::IDENTIFIER_CHARACTER . ']*)'
            . $constantEnd . '(?!\s*\()/';
        preg_match_all($referencePattern, $content, $referenceMatches, PREG_OFFSET_CAPTURE);
        foreach ($referenceMatches['reference'] as $index => [$reference]) {
            [$matchedText, $offset] = $referenceMatches['constant'][$index];
            $isCertain = false;
            $ambiguityReason = null;
            if ($phpFileContext === null) {
                if ($reference !== $shortName) {
                    continue;
                }
                $ambiguityReason = "{$shortName}::{$oldConstantName} outside PHP code; the class cannot be proven";
            } elseif (in_array(strtolower($reference), ['self', 'static', 'parent'], true)) {
                if ($phpFileContext->extendedClassName === $className) {
                    $isCertain = true;
                } elseif ($phpFileContext->extendedClassName !== null && !in_array($oldConstantName, $phpFileContext->declaredConstantNames, true)) {
                    $ambiguityReason = "inherited constant; the class extends {$phpFileContext->extendedClassName}, which may extend {$className}";
                } else {
                    continue;
                }
            } else {
                $resolvedClassName = $phpFileContext->resolveClassName($reference);
                if ($resolvedClassName === $className) {
                    $isCertain = true;
                } elseif ($reference === $shortName && !isset($phpFileContext->importedClassNamesByAlias[$reference])) {
                    $ambiguityReason = "{$shortName} is not imported here; it resolves to {$resolvedClassName}";
                } else {
                    continue;
                }
            }
            $hits[] = new RenameHit($renameEntry, $relativeFilePath, $offset, $matchedText, $newConstantName, $isCertain, $ambiguityReason);
        }
        return $hits;
    }

    /**
     * Skill names, package ids, config keys, console commands and environment variables.
     *
     * @return RenameHit[]
     */
    protected static function findDelimitedNameHits(string $content, string $relativeFilePath, RenameEntry $renameEntry): array
    {
        $quotedName = preg_quote($renameEntry->oldName, '/');
        $pattern = match ($renameEntry->kind) {
            RenameEntry::KIND_CONSOLE_COMMAND => '/(?<![A-Za-z0-9_\-:.])' . $quotedName . '(?![A-Za-z0-9_\-:])/',
            RenameEntry::KIND_ENV_VAR => '/(?<![A-Za-z0-9_])' . $quotedName . '(?![A-Za-z0-9_])/',
            RenameEntry::KIND_CONFIG_KEY => '/(?<![A-Za-z0-9_\-.\/\\\\])' . $quotedName . '(?![A-Za-z0-9_\-\/\\\\])/',
            default => '/(?<![A-Za-z0-9_\-.\/\\\\])' . $quotedName . '(?![A-Za-z0-9_\-\/\\\\])(?!\.[A-Za-z0-9_])/',
        };
        $separators = match ($renameEntry->kind) {
            RenameEntry::KIND_ENV_VAR => '_',
            RenameEntry::KIND_CONSOLE_COMMAND => ':-',
            default => '.-_:',
        };
        $isCompoundName = strpbrk($renameEntry->oldName, $separators) !== false;
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        $hits = [];
        foreach ($matches[0] as [$matchedText, $offset]) {
            $isCertain = $isCompoundName || static::isEnclosedInQuotes($content, $offset, strlen($matchedText));
            if (!$isCertain && $renameEntry->kind === RenameEntry::KIND_ENV_VAR && $offset > 0 && in_array($content[$offset - 1], ['$', '{'], true)) {
                $isCertain = true;
            }
            $ambiguityReason = $isCertain ? null : 'a single word outside quotes may be an ordinary word here';
            $hits[] = new RenameHit($renameEntry, $relativeFilePath, $offset, $matchedText, $renameEntry->newName, $isCertain, $ambiguityReason);
        }
        return $hits;
    }

    protected static function isEnclosedInQuotes(string $content, int $offset, int $length): bool
    {
        if ($offset === 0 || $offset + $length >= strlen($content)) {
            return false;
        }
        $leadingCharacter = $content[$offset - 1];
        return in_array($leadingCharacter, ['"', "'", '`'], true) && $content[$offset + $length] === $leadingCharacter;
    }

    /**
     * @param RenameHit[] $hits
     * @return RenameHit[] sorted by offset; of overlapping hits the longest (then the first found) stays
     */
    protected static function removeOverlappingHits(array $hits): array
    {
        $positions = [];
        foreach ($hits as $position => $hit) {
            $positions[spl_object_id($hit)] = $position;
        }
        usort(
            $hits,
            static fn(RenameHit $first, RenameHit $second): int => strlen($second->matchedText) <=> strlen($first->matchedText)
                ?: $positions[spl_object_id($first)] <=> $positions[spl_object_id($second)]
        );
        $keptHits = [];
        foreach ($hits as $hit) {
            foreach ($keptHits as $keptHit) {
                if ($hit->offset < $keptHit->getEndOffset() && $keptHit->offset < $hit->getEndOffset()) {
                    continue 2;
                }
            }
            $keptHits[] = $hit;
        }
        usort($keptHits, static fn(RenameHit $first, RenameHit $second): int => $first->offset <=> $second->offset);
        return $keptHits;
    }
}
