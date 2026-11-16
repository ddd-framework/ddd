<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\RecordedRuns;

/**
 * The wall-clock values a recorded run and its replay necessarily write differently, and how two runs compare them.
 * ONE rule set, used by the statement audit
 * ({@see \DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementNormalization}), the dispatch capture
 * ({@see \DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture}) and any JSON a caller compares
 * ({@see normalizeStructure()} for decoded JSON, {@see normalizeJsonText()} for JSON text — only the JSON a text is or
 * carries, never free text):
 *
 * - **now-moments of sites without a pinned clock**: a value under a time-named name (`created`, `updated`,
 *   `modified`, `…At`, `…Time`, `…Timestamp`) that lies inside {@see $clockWindow} reads {@see CLOCK_PLACEHOLDER}; a
 *   moment the pinned clock answered ({@see $pinnedMoments}) stays raw. A caller sets the window to the whole RUN (from
 *   its start to the end of the compared call) when its records carry stamps written earlier in the run;
 * - **derived moments** (now + a delay, a next firing): the names in {@see $derivedMomentKeys} read
 *   {@see DERIVED_CLOCK_PLACEHOLDER} whenever they hold a value — the window cannot see them;
 * - **durations** (how long something took): the names in {@see $durationKeys} read {@see DURATION_PLACEHOLDER};
 * - **a time of day written into text** by a site without a pinned clock (`… in progress (started 10:18) …`): only
 *   right after one of the declared {@see $clockTextPrefixes}, an `H:i` that one minute of {@see $clockWindow} reads
 *   (the process zone) becomes {@see CLOCK_PLACEHOLDER} — {@see normalizeClockTextPhrases()}. A minute cannot tell the
 *   pinned moment from the run's wall clock, so the pinned moment is NOT kept raw here. Never applied without a
 *   declared prefix: free text is compared raw.
 *
 * A moment written as Unix seconds with a microsecond fraction (`microtime(true)`, `…WallUs`) counts like whole
 * seconds; a name ending in `WallUs` is time-named.
 *
 * A name is a key at any depth of a structure (a message body, a decoded JSON value) or a written column. A string
 * that holds a JSON object or list (a JSON column, a JSON payload bound into an SQL expression) is decoded, normalised
 * and encoded again — only when a value in it changed, so an untouched payload is compared byte for byte. Pure.
 */
class RecordedRunWallClockNormalization
{
    public const string CLOCK_PLACEHOLDER = '<clock>';
    public const string DERIVED_CLOCK_PLACEHOLDER = '<derived-clock>';
    public const string DURATION_PLACEHOLDER = '<duration>';

    /** A name that says it holds a moment — the only names the clock window applies to. */
    public const string TIME_NAME_PATTERN = '/^(created|updated|modified|timestamp)$|^timeOf[A-Z]|(At|Time|Timestamp|Instant|_at|_time|WallUs)$/';

    /** The time of day {@see normalizeClockTextPhrases()} reads after a declared prefix. */
    public const string TIME_OF_DAY_TEXT_PATTERN = '\\d{2}:\\d{2}(?!:?\\d)';

    /** A moment written as text: `Y-m-d H:i:s`, or ISO 8601 with `T`, an optional fraction and zone. */
    public const string MOMENT_TEXT_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:?\d{2}|Z)?$/';

    /** @var RecordedRunClockWindow|null The wall-clock window; null = the moment rule is off */
    public ?RecordedRunClockWindow $clockWindow = null;

    /** @var string[] Moments (`Y-m-d H:i:s` / Unix seconds) the pinned clock answered — kept raw */
    public array $pinnedMoments = [];

    /** @var string[] Names whose value is a moment derived from the wall clock */
    public array $derivedMomentKeys = [];

    /** @var string[] Names whose value is a wall-clock duration */
    public array $durationKeys = [];

    /** @var string[] Literal text right before which a site without a pinned clock writes a time of day (`(started `) */
    public array $clockTextPrefixes = [];

    /**
     * @param RecordedRunClockWindow|null $clockWindow The wall-clock window; null = the moment rule is off
     * @param array<int, string|int> $pinnedMoments
     * @param string[] $derivedMomentKeys
     * @param string[] $durationKeys
     * @param string[] $clockTextPrefixes
     * @return static
     */
    public static function declare(?RecordedRunClockWindow $clockWindow = null, array $pinnedMoments = [], array $derivedMomentKeys = [], array $durationKeys = [], array $clockTextPrefixes = []): static
    {
        $recordedRunWallClockNormalization = new static();
        $recordedRunWallClockNormalization->clockWindow = $clockWindow;
        $recordedRunWallClockNormalization->pinnedMoments = array_map('strval', $pinnedMoments);
        $recordedRunWallClockNormalization->derivedMomentKeys = array_values(array_map('strval', $derivedMomentKeys));
        $recordedRunWallClockNormalization->durationKeys = array_values(array_map('strval', $durationKeys));
        $recordedRunWallClockNormalization->clockTextPrefixes = array_values(array_filter(array_map('strval', $clockTextPrefixes), 'strlen'));
        return $recordedRunWallClockNormalization;
    }

    /**
     * Whether $value under the time-named $name is a moment inside the window that the pinned clock did not answer.
     *
     * @param string|null $name A key or a column; null for a list element
     * @param mixed $value
     * @return bool
     */
    public function isUnpinnedWallClockMoment(?string $name, mixed $value): bool
    {
        if ($this->clockWindow === null || $name === null || !preg_match(self::TIME_NAME_PATTERN, $name)) {
            return false;
        }
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return false;
        }
        if (in_array((string)$value, $this->pinnedMoments, true)) {
            return false;
        }
        $timestamp = null;
        if (is_int($value) || is_float($value) || ctype_digit($value)) {
            $timestamp = (int)$value;
        } elseif (preg_match('/^\d+\.\d+$/', $value)) {
            $timestamp = (int)$value;
        } elseif (preg_match(self::MOMENT_TEXT_PATTERN, $value)) {
            $timestamp = strtotime($value) ?: null;
        }
        return $timestamp !== null && $this->clockWindow->contains($timestamp);
    }

    /**
     * One value under a name (a key or a column; null for a list element), as two runs compare it.
     *
     * @param string|null $name
     * @param mixed $value
     * @return mixed
     */
    public function normalizeValueUnderName(?string $name, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normalizeStructure($value);
        }
        if ($name !== null && $value !== null && in_array($name, $this->derivedMomentKeys, true)) {
            return self::DERIVED_CLOCK_PLACEHOLDER;
        }
        if ($name !== null && $value !== null && in_array($name, $this->durationKeys, true)) {
            return self::DURATION_PLACEHOLDER;
        }
        if ($this->isUnpinnedWallClockMoment($name, $value)) {
            return self::CLOCK_PLACEHOLDER;
        }
        if (is_string($value)) {
            return $this->normalizeClockTextPhrases($this->normalizeJsonText($value));
        }
        return $value;
    }

    /**
     * A text with every declared clock phrase's time of day inside the window read as {@see CLOCK_PLACEHOLDER}: the
     * declared prefix, then an `H:i` (or `Y-m-d H:i`) that some minute of the window formats to. Any other text stays
     * as it is.
     *
     * @param string $text
     * @return string
     */
    public function normalizeClockTextPhrases(string $text): string
    {
        if ($this->clockWindow === null || $this->clockTextPrefixes === []) {
            return $text;
        }
        $windowTimesOfDay = $this->findTimesOfDayOfWindow();
        foreach ($this->clockTextPrefixes as $clockTextPrefix) {
            $quotedPrefix = preg_quote($clockTextPrefix, '/');
            $text = (string)preg_replace_callback(
                '/(?<=' . $quotedPrefix . ')(?:\\d{4}-\\d{2}-\\d{2} )?' . self::TIME_OF_DAY_TEXT_PATTERN . '/',
                static fn (array $timeOfDayMatch): string => isset($windowTimesOfDay[$timeOfDayMatch[0]]) ? self::CLOCK_PLACEHOLDER : $timeOfDayMatch[0],
                $text
            );
        }
        return $text;
    }

    /**
     * @return bool[] Every `H:i` and `Y-m-d H:i` a minute of the window formats to (the process zone), as keys
     */
    protected function findTimesOfDayOfWindow(): array
    {
        $windowTimesOfDay = [];
        for ($minuteTimestamp = intdiv($this->clockWindow->fromTimestamp, 60) * 60; $minuteTimestamp <= $this->clockWindow->toTimestamp; $minuteTimestamp += 60) {
            $windowTimesOfDay[date('H:i', $minuteTimestamp)] = true;
            $windowTimesOfDay[date('Y-m-d H:i', $minuteTimestamp)] = true;
        }
        return $windowTimesOfDay;
    }

    /**
     * Decoded JSON with every value normalised under its key, at any depth. It takes and returns decoded JSON of ANY
     * shape — a JSON object (string keys) or list (integer keys), nested as deep as the record goes: a message body, a
     * JSON column's object, the decoded content of a call's result. It has no fixed keys because it is whatever the
     * compared record carries; the returned array has the same shape and keys, only values are replaced by
     * placeholders. For JSON as text use {@see normalizeJsonText()}.
     *
     * @param array<int|string, mixed> $structure Decoded JSON of any shape (`json_decode(…, true)` of an object or list)
     * @return array<int|string, mixed> The same shape and keys, the values normalised
     */
    public function normalizeStructure(array $structure): array
    {
        foreach ($structure as $key => $value) {
            $structure[$key] = $this->normalizeValueUnderName(is_string($key) ? $key : null, $value);
        }
        return $structure;
    }

    /**
     * A text holding a JSON object or list with its values normalised — encoded again only when one changed.
     *
     * @param string $text
     * @return string
     */
    public function normalizeJsonText(string $text): string
    {
        $trimmedText = ltrim($text);
        if ($trimmedText === '' || ($trimmedText[0] !== '{' && $trimmedText[0] !== '[')) {
            return $text;
        }
        $decodedJson = json_decode($text, true);
        if (!is_array($decodedJson)) {
            return $text;
        }
        $normalizedJson = $this->normalizeStructure($decodedJson);
        if ($normalizedJson === $decodedJson) {
            return $text;
        }
        return (string)json_encode($normalizedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
