<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The address of one stored file, whatever entity holds it: `<sourceName>/<id>` (`media/901`, `support/3301`,
 * `drive/812`). The source name is the name of a registered {@see \DDD\Domain\Common\Services\Files\FileSource}; the
 * id is whatever that source addresses its files by. A reference is an address in arguments and routes, never a
 * stored polymorphic column, and it grants nothing: every read resolves it again under the caller's rights
 * ({@see \DDD\Domain\Common\Services\Files\FileSourcesService}).
 */
class FileReference extends ValueObject
{
    /** @var string What a source name looks like: lower case, starts with a letter */
    public const string SOURCE_NAME_PATTERN = '[a-z][a-z0-9-]{0,31}';

    /** @var string What an id looks like: no slash, no dot, so a reference is never a path */
    public const string ID_PATTERN = '[A-Za-z0-9_-]{1,128}';

    /** @var string The name of the source that holds the file, e.g. `media` */
    public string $sourceName;

    /** @var string The id of the file inside its source */
    public string $id;

    public function __construct(string $sourceName = '', string $id = '')
    {
        $this->sourceName = $sourceName;
        $this->id = $id;
        parent::__construct();
    }

    /**
     * The reference a text names, or null when the text is no reference (a path, an empty text, a URL).
     */
    public static function tryFromString(string $text): ?FileReference
    {
        $pattern = '#^(' . static::SOURCE_NAME_PATTERN . ')/(' . static::ID_PATTERN . ')$#';
        if (preg_match($pattern, trim($text), $matches) !== 1) {
            return null;
        }
        return new static($matches[1], $matches[2]);
    }

    /**
     * The reference a text names.
     *
     * @throws BadRequestException When the text is anything but `<source>/<id>`
     */
    public static function fromString(string $text): FileReference
    {
        $fileReference = static::tryFromString($text);
        if ($fileReference === null) {
            throw new BadRequestException(sprintf(
                '"%s" is no file reference. A reference is <source>/<id>, e.g. media/901.',
                mb_substr($text, 0, 80)
            ));
        }
        return $fileReference;
    }

    /** The text form `<sourceName>/<id>` */
    public function toString(): string
    {
        return $this->sourceName . '/' . $this->id;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function isSameAs(FileReference $other): bool
    {
        return $this->sourceName === $other->sourceName && $this->id === $other->id;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->toString());
    }
}
