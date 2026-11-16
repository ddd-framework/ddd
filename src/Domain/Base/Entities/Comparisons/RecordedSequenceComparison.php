<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Comparisons;

/**
 * The comparison of two {@see RecordedSequence}s of one call (the statements that write or the messages dispatched,
 * recorded and replayed): order and multiplicity count, so the sequences are compared position by position after a
 * count check, each record as its canonical JSON. Pure.
 */
class RecordedSequenceComparison
{
    /** How many differences one comparison reports at most. */
    public const int MAX_REPORTED_DIFFERENCES = 30;

    /**
     * The differences between a recorded and a current sequence, one line each, empty when both are identical.
     *
     * @param string $label What one record is, for the lines (`statement`, `dispatch`)
     * @param RecordedSequence $recorded The recorded run's sequence
     * @param RecordedSequence $current The sequence of the run compared with it (the replay)
     * @return string[]
     */
    public static function buildDifferences(string $label, RecordedSequence $recorded, RecordedSequence $current): array
    {
        $differences = [];
        if ($recorded->count() !== $current->count()) {
            $differences[] = "{$label} count: " . $recorded->count() . ' recorded, ' . $current->count() . ' replayed';
        }
        $positionCount = max($recorded->count(), $current->count());
        for ($position = 0; $position < $positionCount && count($differences) < self::MAX_REPORTED_DIFFERENCES; $position++) {
            $recordedJson = $recorded->encodeRecordAt($position);
            $currentJson = $current->encodeRecordAt($position);
            if ($recordedJson === $currentJson) {
                continue;
            }
            $differences[] = match (true) {
                $recordedJson === null => "{$label} #{$position}: only replayed «" . mb_strcut((string)$currentJson, 0, 240) . '»',
                $currentJson === null => "{$label} #{$position}: only recorded «" . mb_strcut($recordedJson, 0, 240) . '»',
                default => "{$label} #{$position}: " . static::describeFirstDifference($recordedJson, $currentJson),
            };
        }
        return $differences;
    }

    /**
     * Where two encoded records start to differ, with a short excerpt of both.
     *
     * @param string $recordedJson
     * @param string $currentJson
     * @return string
     */
    protected static function describeFirstDifference(string $recordedJson, string $currentJson): string
    {
        $commonLength = min(strlen($recordedJson), strlen($currentJson));
        $offset = 0;
        while ($offset < $commonLength && $recordedJson[$offset] === $currentJson[$offset]) {
            $offset++;
        }
        $excerptStart = max(0, $offset - 60);
        return 'recorded «…' . mb_strcut($recordedJson, $excerptStart, 160) . '…» replayed «…' . mb_strcut($currentJson, $excerptStart, 160) . '…»';
    }
}
