<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One stack frame, innermost first. A client event's frame: the minified position as the client sent it and, once a
 * source map for the event's release is available, the original position (resolved = true). A backend frame
 * (`error.frames`): the file relative to the project root, the line, the function and, for the first application
 * frames, the source lines around the line (codeContext*); its resolved* fields stay empty.
 */
class IssueEventFrame extends ValueObject
{
    /** @var string|null The script URL or path (client), the file relative to the project root (backend) */
    #[Required]
    public ?string $file = null;

    /** @var int|null Line in the (minified) script or the file */
    #[Required]
    public ?int $line = null;

    /** @var int|null Column in the (minified) script */
    #[Required]
    public ?int $column = null;

    /** @var string|null Function name as the runtime reported it */
    #[Required]
    public ?string $function = null;

    /** @var bool Whether the frame is the application's own code */
    #[Required]
    public bool $inApp = false;

    /** @var string|null Original source file, from the source map */
    #[Required]
    public ?string $resolvedFile = null;

    /** @var int|null Original line, from the source map */
    #[Required]
    public ?int $resolvedLine = null;

    /** @var int|null Original column, from the source map */
    #[Required]
    public ?int $resolvedColumn = null;

    /** @var string|null Original function name, from the source map */
    #[Required]
    public ?string $resolvedFunction = null;

    /** @var string[] Source lines around the original line, when the source map carries the sources */
    #[Required]
    public array $contextLines = [];

    /** @var int|null Original line number of the first context line */
    #[Required]
    public ?int $contextStartLine = null;

    /** @var bool Whether the original position is known */
    #[Required]
    public bool $resolved = false;

    /** @var int|null Backend frames: line number of the first code context line; null when the frame carries none */
    #[Required]
    public ?int $codeContextStartLine = null;

    /** @var string[] Backend frames: the source lines around the line (read at log time, sanitised); empty when none */
    #[Required]
    public array $codeContextLines = [];

    public function uniqueKey(): string
    {
        // the same frame may legitimately repeat (recursion): order matters, duplicates stay
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
