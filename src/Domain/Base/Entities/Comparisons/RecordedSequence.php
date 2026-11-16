<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Comparisons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * An ordered list of records of one run as two runs compare them (the statements that write, the dispatched messages,
 * the requests of one call): each record is a stored document, compared as its canonical JSON. Built by the object
 * sets that are compared (`toRecordedSequence()`) or from the decoded entries of a stored recording
 * ({@see fromStoredDocuments()}). Read by {@see RecordedSequenceComparison}.
 */
class RecordedSequence extends ValueObject
{
    /** @var array<int, mixed> The records' decoded stored documents, in order (a payload of no fixed shape) */
    protected array $storedDocuments = [];

    /**
     * @param array<int, mixed> $documents The decoded entries of a stored recording (or of a `toArray()`), in order
     * @return static
     */
    public static function fromStoredDocuments(array $documents): static
    {
        $recordedSequence = new static();
        $recordedSequence->storedDocuments = array_values($documents);
        return $recordedSequence;
    }

    /**
     * @return int How many records the sequence holds
     */
    public function count(): int
    {
        return count($this->storedDocuments);
    }

    /**
     * The record at a position as canonical JSON: unescaped, so a changed text reads as text.
     *
     * @param int $position
     * @return string|null Null when the sequence has no record at the position
     */
    public function encodeRecordAt(int $position): ?string
    {
        if (!array_key_exists($position, $this->storedDocuments)) {
            return null;
        }
        return (string)json_encode($this->storedDocuments[$position], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
