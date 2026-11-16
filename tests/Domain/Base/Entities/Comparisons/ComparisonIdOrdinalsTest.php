<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Comparisons;

use DDD\Domain\Base\Entities\Comparisons\ComparisonIdOrdinals;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison;
use PHPUnit\Framework\TestCase;

/**
 * Ids minted during a call read as ordinals per family in the order they first appear; a pre-existing id and an id of a
 * family without a mark stay raw. Two ordered lists differ by count, by position and by content.
 */
class ComparisonIdOrdinalsTest extends TestCase
{
    public function testMintedIdsReadAsOrdinalsInFirstAppearanceOrder(): void
    {
        $comparisonIdOrdinals = ComparisonIdOrdinals::withHighWaterMarks(['order' => 100]);
        self::assertSame('<order#1>', $comparisonIdOrdinals->normalizeId('order', 105));
        self::assertSame('<order#2>', $comparisonIdOrdinals->normalizeId('order', '101'));
        self::assertSame('<order#1>', $comparisonIdOrdinals->normalizeId('order', '105'), 'a later use of the same id reads the same placeholder');
        self::assertSame(100, $comparisonIdOrdinals->normalizeId('order', 100), 'at the mark: pre-existing, raw');
        self::assertSame(105, $comparisonIdOrdinals->normalizeId('invoice', 105), 'a family without a mark stays raw');
        self::assertSame('abc', $comparisonIdOrdinals->normalizeId('order', 'abc'), 'no number, no ordinal');
    }

    public function testTwoListsDifferByCountPositionAndContent(): void
    {
        self::assertSame([], RecordedSequenceComparison::buildDifferences('statement', RecordedSequence::fromStoredDocuments([['a' => 1]]), RecordedSequence::fromStoredDocuments([['a' => 1]])));
        $differences = RecordedSequenceComparison::buildDifferences('statement', RecordedSequence::fromStoredDocuments([['a' => 1], ['b' => 2]]), RecordedSequence::fromStoredDocuments([['a' => 9]]));
        self::assertSame('statement count: 2 recorded, 1 replayed', $differences[0]);
        self::assertStringStartsWith('statement #0: recorded «…', $differences[1]);
        self::assertSame('statement #1: only recorded «{"b":2}»', $differences[2]);
        self::assertSame('statement #0: recorded «…{"a":1}…» replayed «…{"a":9}…»', $differences[1], 'the difference text, byte for byte');
        self::assertSame(
            ['statement count: 0 recorded, 1 replayed', 'statement #0: only replayed «{"c":3}»'],
            RecordedSequenceComparison::buildDifferences('statement', RecordedSequence::fromStoredDocuments([]), RecordedSequence::fromStoredDocuments([['c' => 3]]))
        );
    }
}
