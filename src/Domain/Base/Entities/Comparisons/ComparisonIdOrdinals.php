<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Comparisons;

/**
 * The ordinal placeholders of the ids a compared call MINTS (database auto-increment ids): two runs of the same call
 * mint different ids for the same new rows, so each declared id family numbers the ids above its high-water mark in
 * the order they first appear — the first new row of a family reads `<family#1>` in both runs, every later use of that
 * id the same placeholder. An id at or below the mark existed before the call (a fixture row) and stays RAW:
 * `UPDATE items … WHERE id = 42` and `… id = 43` must compare different.
 *
 * The high-water mark of a family is the highest id of its table read at the start of the call. A family without a
 * declared mark normalises nothing. One object per normalised run; families and marks are declared by the caller,
 * never guessed. Pure.
 */
class ComparisonIdOrdinals
{
    /** @var array<string, int> Per id family: the highest id that existed when the call started */
    public array $highWaterMarksByFamily = [];

    /** @var array<string, array<string, string>> Per id family: the minted id → its placeholder (a cache, never returned) */
    protected array $placeholdersByFamily = [];

    /**
     * @param array<string, int> $highWaterMarksByFamily Per id family: the highest id of its table at call start
     * @return static
     */
    public static function withHighWaterMarks(array $highWaterMarksByFamily): static
    {
        $comparisonIdOrdinals = new static();
        $comparisonIdOrdinals->highWaterMarksByFamily = $highWaterMarksByFamily;
        return $comparisonIdOrdinals;
    }

    /**
     * Whether $id of $idFamily was minted during the call (above the family's declared high-water mark).
     *
     * @param string $idFamily
     * @param int|string $id
     * @return bool
     */
    public function isMintedDuringCall(string $idFamily, int|string $id): bool
    {
        if (!isset($this->highWaterMarksByFamily[$idFamily])) {
            return false;
        }
        $idText = (string)$id;
        return ctype_digit($idText) && (int)$idText > $this->highWaterMarksByFamily[$idFamily];
    }

    /**
     * $id as two runs compare it: its placeholder when the call minted it, else the raw id.
     *
     * @param string $idFamily
     * @param int|string $id
     * @return int|string
     */
    public function normalizeId(string $idFamily, int|string $id): int|string
    {
        if (!$this->isMintedDuringCall($idFamily, $id)) {
            return $id;
        }
        $idKey = (string)$id;
        if (!isset($this->placeholdersByFamily[$idFamily][$idKey])) {
            $this->placeholdersByFamily[$idFamily][$idKey] = '<' . $idFamily . '#' . (count($this->placeholdersByFamily[$idFamily] ?? []) + 1) . '>';
        }
        return $this->placeholdersByFamily[$idFamily][$idKey];
    }
}
