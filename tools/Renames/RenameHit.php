<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

/**
 * One place in an application file where an old name of a rename entry was found. A certain hit is written by
 * `--apply`; an ambiguous hit (the boundary rule matched, but the context does not prove the name means the renamed
 * thing) and a path hit (a file or folder named after the old name) are only reported.
 */
class RenameHit
{
    /** The old name occurs in the file's text and is replaced */
    public const string TYPE_TEXT = 'text';

    /** A file or folder path is spelled after the old name; never moved by the tool */
    public const string TYPE_PATH = 'path';

    public function __construct(
        public RenameEntry $renameEntry,
        public string $relativeFilePath,
        public int $offset,
        public string $matchedText,
        public string $replacementText,
        public bool $isCertain,
        public ?string $ambiguityReason = null,
        public int $lineNumber = 0,
        public int $columnNumber = 0,
        public string $lineText = '',
        public string $type = self::TYPE_TEXT,
    ) {
    }

    public function getEndOffset(): int
    {
        return $this->offset + strlen($this->matchedText);
    }

    /** Sets line, column and the trimmed line text from the file content the hit was found in */
    public function locateInContent(string $content): void
    {
        $before = substr($content, 0, $this->offset);
        $this->lineNumber = substr_count($before, "\n") + 1;
        $lineStart = strrpos($before, "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $this->columnNumber = $this->offset - $lineStart + 1;
        $lineEnd = strpos($content, "\n", $this->offset);
        $line = substr($content, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart);
        $this->lineText = mb_strimwidth(trim($line), 0, 200, '…');
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonObject(): array
    {
        return [
            'file' => $this->relativeFilePath,
            'line' => $this->lineNumber,
            'column' => $this->columnNumber,
            'type' => $this->type,
            'certain' => $this->isCertain,
            'reason' => $this->ambiguityReason,
            'matched' => $this->matchedText,
            'replacement' => $this->replacementText,
            'lineText' => $this->lineText,
            'rename' => $this->renameEntry->toJsonObject(),
        ];
    }
}
