<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

/**
 * The letters "AI" as a vector shape, so no font is needed: polygons in a box whose cap height is 1 (y grows
 * downwards), filled with the even-odd rule (the counter of the A is a hole). Both letters share one cap height; the
 * shape is only ever scaled uniformly, never stretched.
 *
 * A geometric stand-in: an official icon's letter outlines can replace these polygons.
 *
 * Pure: constants and arithmetic only.
 */
class AIBadgeGlyph
{
    /** @var float The width of the shape at cap height 1 */
    public const float WIDTH_AT_CAP_HEIGHT_ONE = 1.10;

    /**
     * @var float[][] The closed polygons of the shape at cap height 1, each a flat list x0, y0, x1, y1, …: the A's
     *     outline (legs and crossbar), the A's counter (a hole under the even-odd rule), the I
     */
    public const array POLYGONS = [
        [0.00, 1.00, 0.31, 0.00, 0.49, 0.00, 0.80, 1.00, 0.62, 1.00, 0.5518, 0.78, 0.2482, 0.78, 0.18, 1.00],
        [0.40, 0.29, 0.5022, 0.62, 0.2978, 0.62],
        [0.92, 0.00, 1.10, 0.00, 1.10, 1.00, 0.92, 1.00],
    ];

    /**
     * The polygons placed in a square of `$squareSide` with cap height `$capHeight`, centred on the cap height (optical
     * centre, not the baseline).
     */
    public static function buildPolygonsCentredInSquare(float $squareSide, float $capHeight): AIBadgeGlyphPolygons
    {
        $originLeft = ($squareSide - self::WIDTH_AT_CAP_HEIGHT_ONE * $capHeight) / 2;
        $originTop = ($squareSide - $capHeight) / 2;
        $glyphPolygons = new AIBadgeGlyphPolygons();
        foreach (self::POLYGONS as $unitCoordinates) {
            $glyphPolygon = new AIBadgeGlyphPolygon();
            for ($coordinateIndex = 0; $coordinateIndex < count($unitCoordinates); $coordinateIndex += 2) {
                $glyphPolygon->addPoint(
                    $originLeft + $unitCoordinates[$coordinateIndex] * $capHeight,
                    $originTop + $unitCoordinates[$coordinateIndex + 1] * $capHeight,
                );
            }
            $glyphPolygons->add($glyphPolygon);
        }
        return $glyphPolygons;
    }
}
