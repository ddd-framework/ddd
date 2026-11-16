<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

/**
 * The bound parameters of one statement, handed out in placeholder order while the statement is parsed: a positional
 * placeholder (`?`) takes the next positional value, a named one (`:name`) the value of that name. A placeholder
 * without a value reads `<unbound>`, so a parse never fails on a short parameter list.
 */
class DatabaseWriteStatementParameterCursor
{
    public const string UNBOUND_PLACEHOLDER = '<unbound>';

    /** @var array<int|string, mixed> The bound values by position or name (scalars, or a list for an IN parameter) */
    public array $parameters = [];

    /** @var array<int, int|string> The keys of the positional parameters, in binding order */
    public array $positionalKeys = [];

    /** The index in {@see $positionalKeys} of the value the next positional placeholder takes. */
    public int $nextPositionalIndex = 0;

    /**
     * @param array<int|string, mixed> $parameters The bound values by position or name
     */
    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
        $positionalKeys = array_values(array_filter(array_keys($parameters), 'is_int'));
        sort($positionalKeys);
        $this->positionalKeys = $positionalKeys;
    }

    /**
     * The value of the next placeholder: the named one when $placeholderName is set, else the next positional one.
     *
     * @param string|null $placeholderName
     * @return mixed
     */
    public function take(?string $placeholderName): mixed
    {
        if ($placeholderName !== null) {
            if (array_key_exists($placeholderName, $this->parameters)) {
                return $this->parameters[$placeholderName];
            }
            return array_key_exists(':' . $placeholderName, $this->parameters) ? $this->parameters[':' . $placeholderName] : self::UNBOUND_PLACEHOLDER;
        }
        $positionalKey = $this->positionalKeys[$this->nextPositionalIndex] ?? null;
        $this->nextPositionalIndex++;
        return $positionalKey !== null ? $this->parameters[$positionalKey] : self::UNBOUND_PLACEHOLDER;
    }
}
