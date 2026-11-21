<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;

/**
 * @see ValueNormalizerMiddleware — and on the way in: a NUL character in a text value is removed. PostgreSQL rejects
 * it in text (22021 "invalid byte sequence for encoding UTF8: 0x00", a 500 for a `\u0000` in a JSON body) and, as the
 * escape `\u0000` inside JSON text, in json/jsonb (22P05 "unsupported Unicode escape sequence": a JSON or value object
 * column whose data holds a NUL character, e.g. a model's output). MariaDB stored both; binary binds (LARGE_OBJECT,
 * BINARY) keep the byte. The escape is removed only from a value that is valid JSON and only where it is an escape
 * (not a `\\u0000` that writes a backslash), so a text that merely shows the six characters stays as it is.
 */
class ValueNormalizerStatement extends AbstractStatementMiddleware
{
    /** A `\u0000` escape: preceded by an even number of backslashes (none counts), so the backslash is not escaped */
    public const string JSON_NUL_ESCAPE_PATTERN = '/(?<!\\\\)((?:\\\\\\\\)*)\\\\u0000/';

    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        if (is_string($value) && $type !== ParameterType::LARGE_OBJECT && $type !== ParameterType::BINARY) {
            $value = static::removeNulCharacters($value);
        }
        return parent::bindValue($param, $value, $type);
    }

    /**
     * The text value without NUL characters: raw NUL bytes, and the JSON escape `\u0000` when the value is JSON text.
     *
     * @param string $value Bound text value
     * @return string The value PostgreSQL accepts in text, json and jsonb
     */
    public static function removeNulCharacters(string $value): string
    {
        if (str_contains($value, "\0")) {
            $value = str_replace("\0", '', $value);
        }
        if (str_contains($value, '\u0000') && json_validate($value)) {
            $value = preg_replace(static::JSON_NUL_ESCAPE_PATTERN, '$1', $value) ?? $value;
        }
        return $value;
    }

    public function execute($params = null): Result
    {
        return new ValueNormalizerResult(parent::execute($params));
    }
}
