<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DateTimeInterface;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidationModel;
use Stringable;

/**
 * One statement that writes, as the statement audit keeps it: the SQL and its bound parameters, raw, and the two
 * parts of its database's syntax the parser needs — the identifier quote and the conflict clause of an upsert, both
 * taken from the {@see SqlDialect} of the connection that issued it. {@see buildComparableShape()} parses it into what
 * two runs are compared by — the kind, the table, the written columns and values per row (INSERT / REPLACE), the
 * assignments (UPDATE) and the condition with its values (UPDATE / DELETE) — normalised only as
 * {@see DatabaseWriteStatementNormalization} declares (minted ids, now-moments of sites without a pinned clock,
 * declared derived-moment columns). A statement the parser does not understand is compared by its raw SQL and its raw
 * parameters.
 *
 * Known limits of the parser: its quote scanner treats `\` as an escape inside every quoted span, so under
 * PostgreSQL's `standard_conforming_strings` a string LITERAL ending in a backslash may bind later placeholders to the
 * wrong columns (bound parameters are not affected); PostgreSQL's jsonb operators `?`, `?|`, `?&` read as positional
 * placeholders.
 */
class DatabaseWriteStatement extends ValueObject
{
    public const string KIND_INSERT = 'INSERT';
    public const string KIND_REPLACE = 'REPLACE';
    public const string KIND_UPDATE = 'UPDATE';
    public const string KIND_DELETE = 'DELETE';
    public const string KIND_ROLLBACK_TO_SAVEPOINT = 'ROLLBACK TO SAVEPOINT';

    /** @var string The statement kind, one of the KIND_ constants */
    public string $kind = '';

    /** @var string The SQL as the connection executed it */
    public string $sql = '';

    /** @var array<int|string, mixed> The bound parameters by position or name, rendered to scalars (a moment as `Y-m-d H:i:s`, a list for an IN parameter) */
    public array $parameters = [];

    /** @var string The identifier quote of the statement's database ({@see SqlDialect::identifierQuoteCharacter()}) */
    public string $identifierQuoteCharacter = '`';

    /** @var string The conflict clause of an upsert on the statement's database ({@see SqlDialect::upsertConflictClause()}) */
    public string $upsertConflictClause = 'ON DUPLICATE KEY UPDATE';

    /**
     * Whether this is the framework's probabilistic housekeeping of expired cache-scope invalidations
     * ({@see \DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidation::deleteExpiredCacheScopeInvalidations()}):
     * it runs at random on cache writes, so two runs of the same call issue it a different number of times; it is
     * never the call's own effect. Matched without identifier quotes and letter case: the housekeeping is issued as
     * DQL, so its SQL carries the keywords in upper case and, on PostgreSQL, quoted identifiers.
     *
     * @return bool
     */
    public function isRandomCacheScopeInvalidationHousekeeping(): bool
    {
        if ($this->kind !== self::KIND_DELETE) {
            return false;
        }
        $housekeepingStart = 'DELETE FROM ' . DBCacheScopeInvalidationModel::getTableName()
            . ' WHERE (numberOfTimesToInvalidateCache is not null and numberOfTimesToInvalidateCache <= 0)';
        return str_starts_with(static::buildCaseAndQuoteNeutralSql($this->sql), static::buildCaseAndQuoteNeutralSql($housekeepingStart));
    }

    /**
     * $sql in lower case, without identifier quotes, whitespace collapsed — for a comparison that must not depend on
     * the database or on how the SQL was written.
     *
     * @param string $sql
     * @return string
     */
    protected static function buildCaseAndQuoteNeutralSql(string $sql): string
    {
        return strtolower(str_replace(['`', '"'], '', (string)preg_replace('/\s+/', ' ', trim($sql))));
    }

    /**
     * The statement kind of $sql when it writes (or rolls a write back), else null — the cheap test the audit runs on
     * every statement. The same on both databases.
     *
     * @param string $sql
     * @return string|null
     */
    public static function detectWriteKind(string $sql): ?string
    {
        if (!preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE|ROLLBACK\s+TO\s+SAVEPOINT)\b/i', $sql, $kindMatch)) {
            return null;
        }
        return strtoupper((string)preg_replace('/\s+/', ' ', $kindMatch[1]));
    }

    /**
     * @param string $kind
     * @param string $sql
     * @param array<int|string, mixed> $parameters The bound parameters by position or name
     * @param SqlDialect $sqlDialect The database of the connection that issued the statement
     * @return static
     */
    public static function fromIssuedStatement(string $kind, string $sql, array $parameters, SqlDialect $sqlDialect): static
    {
        $databaseWriteStatement = new static();
        $databaseWriteStatement->kind = $kind;
        $databaseWriteStatement->sql = $sql;
        $databaseWriteStatement->identifierQuoteCharacter = $sqlDialect->identifierQuoteCharacter();
        $databaseWriteStatement->upsertConflictClause = $sqlDialect->upsertConflictClause();
        foreach ($parameters as $parameterKey => $parameterValue) {
            $databaseWriteStatement->parameters[$parameterKey] = static::renderValue($parameterValue);
        }
        return $databaseWriteStatement;
    }

    /**
     * A bound value as a comparable scalar (or a list of them for an IN parameter).
     *
     * @param mixed $value
     * @return mixed
     */
    public static function renderValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_int($value), is_float($value), is_string($value) => $value,
            is_bool($value) => (int)$value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => array_map(static::renderValue(...), $value),
            $value instanceof Stringable => (string)$value,
            is_object($value) => (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            default => get_debug_type($value),
        };
    }

    /**
     * The statement as two runs compare it.
     *
     * @param DatabaseWriteStatementNormalization $databaseWriteStatementNormalization Shared by every statement of one run
     * @return DatabaseWriteStatementShape
     */
    public function buildComparableShape(DatabaseWriteStatementNormalization $databaseWriteStatementNormalization): DatabaseWriteStatementShape
    {
        // a JSON payload bound into an SQL expression is normalised before it is embedded in the expression's text
        $normalizedParameters = [];
        foreach ($this->parameters as $parameterKey => $parameterValue) {
            $normalizedParameters[$parameterKey] = $databaseWriteStatementNormalization->normalizeBoundParameter($parameterValue);
        }
        $parameterCursor = new DatabaseWriteStatementParameterCursor($normalizedParameters);
        $databaseWriteStatementShape = match ($this->kind) {
            self::KIND_INSERT, self::KIND_REPLACE => $this->parseInsert($parameterCursor),
            self::KIND_UPDATE => $this->parseUpdate($parameterCursor),
            self::KIND_DELETE => $this->parseDelete($parameterCursor),
            self::KIND_ROLLBACK_TO_SAVEPOINT => $this->buildKindOnlyShape(),
            default => null,
        };
        if ($databaseWriteStatementShape === null) {
            // without a parsed table no declared column applies: compared raw
            $databaseWriteStatementShape = new DatabaseWriteStatementShape();
            $databaseWriteStatementShape->layout = DatabaseWriteStatementShape::LAYOUT_UNPARSED;
            $databaseWriteStatementShape->kind = $this->kind;
            $databaseWriteStatementShape->unparsedSql = $this->sql;
            $databaseWriteStatementShape->unparsedParameters = $this->parameters;
            return $databaseWriteStatementShape;
        }
        $databaseWriteStatementShape->applyNormalization($databaseWriteStatementNormalization);
        return $databaseWriteStatementShape;
    }

    /**
     * @return DatabaseWriteStatementShape The shape of a statement that is compared by its kind only
     */
    protected function buildKindOnlyShape(): DatabaseWriteStatementShape
    {
        $databaseWriteStatementShape = new DatabaseWriteStatementShape();
        $databaseWriteStatementShape->layout = DatabaseWriteStatementShape::LAYOUT_KIND_ONLY;
        $databaseWriteStatementShape->kind = $this->kind;
        return $databaseWriteStatementShape;
    }

    /**
     * The optional identifier quote of the statement's database, for a regular expression.
     *
     * @return string
     */
    protected function buildOptionalQuotePattern(): string
    {
        return '(?:' . preg_quote($this->identifierQuoteCharacter, '/') . ')?';
    }

    /**
     * The stored key of the conflict clause: the clause's words in camel case (`ON DUPLICATE KEY UPDATE` →
     * `onDuplicateKeyUpdate`, `ON CONFLICT` → `onConflict`).
     *
     * @return string
     */
    public function buildConflictClauseKey(): string
    {
        $clauseWords = preg_split('/\s+/', strtolower(trim($this->upsertConflictClause))) ?: [];
        $conflictClauseKey = (string)array_shift($clauseWords);
        foreach ($clauseWords as $clauseWord) {
            $conflictClauseKey .= ucfirst($clauseWord);
        }
        return $conflictClauseKey;
    }

    /**
     * @param DatabaseWriteStatementParameterCursor $parameterCursor
     * @return DatabaseWriteStatementShape|null Null when the statement is no `INSERT … (columns) VALUES (…)`
     */
    protected function parseInsert(DatabaseWriteStatementParameterCursor $parameterCursor): ?DatabaseWriteStatementShape
    {
        $optionalQuote = $this->buildOptionalQuotePattern();
        // PostgreSQL's upsert names the target table `AS t` before the column list
        if (!preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+' . $optionalQuote . '([\w.]+)' . $optionalQuote . '(?:\s+AS\s+\w+)?\s*\(([^)]*)\)\s*VALUES\s*/is', $this->sql, $insertMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $databaseWriteStatementShape = new DatabaseWriteStatementShape();
        $databaseWriteStatementShape->layout = DatabaseWriteStatementShape::LAYOUT_ROWS;
        $databaseWriteStatementShape->kind = $this->kind;
        $databaseWriteStatementShape->table = $insertMatch[1][0];
        $databaseWriteStatementShape->columns = array_map(static fn (string $column): string => static::bareIdentifier($column), static::splitTopLevel($insertMatch[2][0], ','));
        $remainingSql = substr($this->sql, $insertMatch[0][1] + strlen($insertMatch[0][0]));
        $offset = 0;
        while (($databaseStatementParenthesisGroup = static::readParenthesisGroup($remainingSql, $offset)) !== null) {
            $offset = $databaseStatementParenthesisGroup->offsetAfterGroup;
            $rowItems = static::splitTopLevel($databaseStatementParenthesisGroup->content, ',');
            $rowValues = [];
            foreach ($databaseWriteStatementShape->columns as $columnIndex => $column) {
                $rowValues[$column] = $this->bindExpression((string)($rowItems[$columnIndex] ?? ''), $parameterCursor);
            }
            $databaseWrittenRow = DatabaseWrittenRow::withValues($rowValues);
            $databaseWriteStatementShape->rows->add($databaseWrittenRow);
            $offset = static::skipWhitespaceAndComma($remainingSql, $offset);
        }
        $trailingSql = trim(substr($remainingSql, $offset));
        $databaseWriteStatementShape->conflictClauseKey = $this->buildConflictClauseKey();
        $conflictClausePattern = (string)preg_replace('/\s+/', '\\s+', preg_quote(trim($this->upsertConflictClause), '/'));
        if (preg_match('/^' . $conflictClausePattern . '\s+(.*)$/is', $trailingSql, $conflictClauseMatch)) {
            $databaseWriteStatementShape->setConflictClauseValue($this->bindExpression($conflictClauseMatch[1], $parameterCursor));
        }
        return $databaseWriteStatementShape;
    }

    /**
     * @param DatabaseWriteStatementParameterCursor $parameterCursor
     * @return DatabaseWriteStatementShape|null Null when the statement is no `UPDATE … SET …` with plain assignments
     */
    protected function parseUpdate(DatabaseWriteStatementParameterCursor $parameterCursor): ?DatabaseWriteStatementShape
    {
        $optionalQuote = $this->buildOptionalQuotePattern();
        if (!preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*' . $optionalQuote . '([\w.]+)' . $optionalQuote . '(?:\s+(?:AS\s+)?(?!SET\b)\w+)?\s+SET\s+/is', $this->sql, $updateMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $afterSetSql = substr($this->sql, $updateMatch[0][1] + strlen($updateMatch[0][0]));
        $wherePosition = static::findTopLevelKeyword($afterSetSql, 'WHERE');
        $assignmentsSql = $wherePosition === null ? $afterSetSql : substr($afterSetSql, 0, $wherePosition);
        $databaseWriteStatementShape = new DatabaseWriteStatementShape();
        $databaseWriteStatementShape->layout = DatabaseWriteStatementShape::LAYOUT_ASSIGNMENTS;
        $databaseWriteStatementShape->kind = $this->kind;
        $databaseWriteStatementShape->table = $updateMatch[1][0];
        foreach (static::splitTopLevel($assignmentsSql, ',') as $assignmentSql) {
            $equalsPosition = strpos($assignmentSql, '=');
            if ($equalsPosition === false) {
                return null;
            }
            $databaseWriteStatementShape->assignValueToColumn(
                static::bareIdentifier(substr($assignmentSql, 0, $equalsPosition)),
                $this->bindExpression(substr($assignmentSql, $equalsPosition + 1), $parameterCursor)
            );
        }
        $this->applyCondition($databaseWriteStatementShape, $wherePosition === null ? '' : substr($afterSetSql, $wherePosition + 5), $parameterCursor);
        return $databaseWriteStatementShape;
    }

    /**
     * @param DatabaseWriteStatementParameterCursor $parameterCursor
     * @return DatabaseWriteStatementShape|null Null when the statement is no `DELETE … FROM <table>`
     */
    protected function parseDelete(DatabaseWriteStatementParameterCursor $parameterCursor): ?DatabaseWriteStatementShape
    {
        $optionalQuote = $this->buildOptionalQuotePattern();
        if (!preg_match('/^\s*DELETE\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*(?:\w+\s+)?FROM\s+' . $optionalQuote . '([\w.]+)' . $optionalQuote . '(?:\s+(?:AS\s+)?(?!WHERE\b)\w+)?/is', $this->sql, $deleteMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $afterTableSql = substr($this->sql, $deleteMatch[0][1] + strlen($deleteMatch[0][0]));
        $wherePosition = static::findTopLevelKeyword($afterTableSql, 'WHERE');
        $databaseWriteStatementShape = new DatabaseWriteStatementShape();
        $databaseWriteStatementShape->layout = DatabaseWriteStatementShape::LAYOUT_CONDITION;
        $databaseWriteStatementShape->kind = $this->kind;
        $databaseWriteStatementShape->table = $deleteMatch[1][0];
        $this->applyCondition($databaseWriteStatementShape, $wherePosition === null ? '' : substr($afterTableSql, $wherePosition + 5), $parameterCursor);
        return $databaseWriteStatementShape;
    }

    /**
     * Puts the condition and its bound values onto the shape: each placeholder with the column compared right before it.
     *
     * @param DatabaseWriteStatementShape $databaseWriteStatementShape
     * @param string $whereSql The condition after `WHERE` ('' without one)
     * @param DatabaseWriteStatementParameterCursor $parameterCursor
     * @return void
     */
    protected function applyCondition(DatabaseWriteStatementShape $databaseWriteStatementShape, string $whereSql, DatabaseWriteStatementParameterCursor $parameterCursor): void
    {
        $optionalQuote = $this->buildOptionalQuotePattern();
        foreach (static::findPlaceholders($whereSql)->getElements() as $databaseStatementPlaceholder) {
            $sqlBeforePlaceholder = substr($whereSql, 0, $databaseStatementPlaceholder->offset);
            $column = preg_match('/' . $optionalQuote . '(\w+)' . $optionalQuote . '\s*(?:=|<=>|!=|<>|>=|<=|>|<|\bIN\s*\(|\bLIKE)\s*$/i', $sqlBeforePlaceholder, $columnMatch) ? $columnMatch[1] : null;
            $databaseConditionValue = DatabaseConditionValue::forColumn($column, $parameterCursor->take($databaseStatementPlaceholder->name));
            $databaseWriteStatementShape->conditionValues->add($databaseConditionValue);
        }
        $databaseWriteStatementShape->condition = trim((string)preg_replace('/\s+/', ' ', $whereSql));
    }

    /**
     * One SQL value expression with its parameters bound: a bare placeholder is the bound value itself; an expression
     * keeps its SQL with each placeholder replaced by the JSON of its value; a literal stays as `SQL:<literal>`.
     *
     * @param string $expressionSql
     * @param DatabaseWriteStatementParameterCursor $parameterCursor
     * @return mixed
     */
    protected function bindExpression(string $expressionSql, DatabaseWriteStatementParameterCursor $parameterCursor): mixed
    {
        $expressionSql = trim($expressionSql);
        $databaseStatementPlaceholders = static::findPlaceholders($expressionSql);
        $firstPlaceholder = $databaseStatementPlaceholders->first();
        if ($databaseStatementPlaceholders->count() === 1 && $firstPlaceholder->length === strlen($expressionSql)) {
            return $parameterCursor->take($firstPlaceholder->name);
        }
        if ($databaseStatementPlaceholders->count() === 0) {
            return 'SQL:' . preg_replace('/\s+/', ' ', $expressionSql);
        }
        $boundSql = '';
        $offset = 0;
        foreach ($databaseStatementPlaceholders->getElements() as $databaseStatementPlaceholder) {
            $boundSql .= substr($expressionSql, $offset, $databaseStatementPlaceholder->offset - $offset)
                . json_encode($parameterCursor->take($databaseStatementPlaceholder->name), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $offset = $databaseStatementPlaceholder->offset + $databaseStatementPlaceholder->length;
        }
        return 'SQL:' . preg_replace('/\s+/', ' ', $boundSql . substr($expressionSql, $offset));
    }

    /**
     * `t0.`name``, `` `name` ``, `"name"`, ` name ` → `name`.
     *
     * @param string $identifier
     * @return string
     */
    protected static function bareIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $dotPosition = strrpos($identifier, '.');
        if ($dotPosition !== false) {
            $identifier = substr($identifier, $dotPosition + 1);
        }
        return trim($identifier, " `\"\t\n\r");
    }

    /**
     * The placeholders (`?` or `:name`) of $sql outside quoted strings and quoted identifiers, in order.
     *
     * @param string $sql
     * @return DatabaseStatementPlaceholders
     */
    protected static function findPlaceholders(string $sql): DatabaseStatementPlaceholders
    {
        $databaseStatementPlaceholders = new DatabaseStatementPlaceholders();
        $quoteCharacter = null;
        $sqlLength = strlen($sql);
        for ($offset = 0; $offset < $sqlLength; $offset++) {
            $character = $sql[$offset];
            if ($quoteCharacter !== null) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === $quoteCharacter) {
                    $quoteCharacter = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quoteCharacter = $character;
            } elseif ($character === '?') {
                $databaseStatementPlaceholder = DatabaseStatementPlaceholder::at($offset, 1, null);
                $databaseStatementPlaceholders->add($databaseStatementPlaceholder);
            } elseif ($character === ':' && ($sql[$offset + 1] ?? '') !== ':' && ($offset === 0 || $sql[$offset - 1] !== ':')
                && preg_match('/\G:([A-Za-z_]\w*)/', $sql, $nameMatch, 0, $offset)) {
                $databaseStatementPlaceholder = DatabaseStatementPlaceholder::at($offset, strlen($nameMatch[0]), $nameMatch[1]);
                $databaseStatementPlaceholders->add($databaseStatementPlaceholder);
                $offset += strlen($nameMatch[0]) - 1;
            }
        }
        return $databaseStatementPlaceholders;
    }

    /**
     * $sql split at $separator where it stands outside parentheses and quotes.
     *
     * @param string $sql
     * @param string $separator
     * @return string[]
     */
    protected static function splitTopLevel(string $sql, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quoteCharacter = null;
        $partStart = 0;
        $sqlLength = strlen($sql);
        for ($offset = 0; $offset < $sqlLength; $offset++) {
            $character = $sql[$offset];
            if ($quoteCharacter !== null) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === $quoteCharacter) {
                    $quoteCharacter = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quoteCharacter = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === $separator && $depth === 0) {
                $parts[] = trim(substr($sql, $partStart, $offset - $partStart));
                $partStart = $offset + 1;
            }
        }
        $parts[] = trim(substr($sql, $partStart));
        return $parts;
    }

    /**
     * The parenthesis group starting at $offset (after whitespace), or null when no group starts there.
     *
     * @param string $sql
     * @param int $offset
     * @return DatabaseStatementParenthesisGroup|null
     */
    protected static function readParenthesisGroup(string $sql, int $offset): ?DatabaseStatementParenthesisGroup
    {
        $sqlLength = strlen($sql);
        while ($offset < $sqlLength && ctype_space($sql[$offset])) {
            $offset++;
        }
        if (($sql[$offset] ?? '') !== '(') {
            return null;
        }
        $depth = 0;
        $quoteCharacter = null;
        for ($position = $offset; $position < $sqlLength; $position++) {
            $character = $sql[$position];
            if ($quoteCharacter !== null) {
                if ($character === '\\') {
                    $position++;
                } elseif ($character === $quoteCharacter) {
                    $quoteCharacter = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quoteCharacter = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return DatabaseStatementParenthesisGroup::withContent(substr($sql, $offset + 1, $position - $offset - 1), $position + 1);
                }
            }
        }
        return null;
    }

    /**
     * @param string $sql
     * @param int $offset
     * @return int The offset after whitespace and one comma
     */
    protected static function skipWhitespaceAndComma(string $sql, int $offset): int
    {
        $sqlLength = strlen($sql);
        while ($offset < $sqlLength && ctype_space($sql[$offset])) {
            $offset++;
        }
        if (($sql[$offset] ?? '') === ',') {
            $offset++;
        }
        return $offset;
    }

    /**
     * The offset of $keyword in $sql outside parentheses and quotes, as a whole word, or null.
     *
     * @param string $sql
     * @param string $keyword
     * @return int|null
     */
    protected static function findTopLevelKeyword(string $sql, string $keyword): ?int
    {
        $depth = 0;
        $quoteCharacter = null;
        $sqlLength = strlen($sql);
        $keywordLength = strlen($keyword);
        for ($offset = 0; $offset < $sqlLength; $offset++) {
            $character = $sql[$offset];
            if ($quoteCharacter !== null) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === $quoteCharacter) {
                    $quoteCharacter = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quoteCharacter = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($depth === 0 && strncasecmp(substr($sql, $offset, $keywordLength), $keyword, $keywordLength) === 0
                && ($offset === 0 || !preg_match('/\w/', $sql[$offset - 1]))
                && !preg_match('/\w/', $sql[$offset + $keywordLength] ?? ' ')) {
                return $offset;
            }
        }
        return null;
    }
}
