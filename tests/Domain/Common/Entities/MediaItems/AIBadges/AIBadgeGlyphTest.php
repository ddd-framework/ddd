<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGlyph;
use PHPUnit\Framework\TestCase;

/**
 * The letters "AI" as polygons: three of them (the A, its counter, the I), centred in the square, 1.10 × the cap height
 * wide and one cap height tall.
 */
class AIBadgeGlyphTest extends TestCase
{
    public function testThreePolygonsCentredInTheSquare(): void
    {
        $glyphPolygons = AIBadgeGlyph::buildPolygonsCentredInSquare(100.0, 40.0);

        self::assertCount(3, $glyphPolygons->getElements());
        $xCoordinates = [];
        $yCoordinates = [];
        foreach ($glyphPolygons->getElements() as $glyphPolygon) {
            self::assertCount($glyphPolygon->countPoints(), $glyphPolygon->yCoordinates);
            $xCoordinates = array_merge($xCoordinates, $glyphPolygon->xCoordinates);
            $yCoordinates = array_merge($yCoordinates, $glyphPolygon->yCoordinates);
        }
        self::assertEqualsWithDelta(44.0, max($xCoordinates) - min($xCoordinates), 0.0001, 'width 1.10 × cap height');
        self::assertEqualsWithDelta(40.0, max($yCoordinates) - min($yCoordinates), 0.0001, 'height = cap height');
        self::assertEqualsWithDelta(50.0, (max($xCoordinates) + min($xCoordinates)) / 2, 0.0001, 'centred horizontally');
        self::assertEqualsWithDelta(50.0, (max($yCoordinates) + min($yCoordinates)) / 2, 0.0001, 'centred vertically');
    }
}
