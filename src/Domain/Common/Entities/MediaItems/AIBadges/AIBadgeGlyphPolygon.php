<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One closed polygon of the badge's glyph: its points in order, x and y at the same index.
 */
class AIBadgeGlyphPolygon extends ValueObject
{
    /** @var float[] The x coordinates of the points */
    public array $xCoordinates = [];

    /** @var float[] The y coordinates of the points (y grows downwards) */
    public array $yCoordinates = [];

    public function addPoint(float $xCoordinate, float $yCoordinate): static
    {
        $this->xCoordinates[] = $xCoordinate;
        $this->yCoordinates[] = $yCoordinate;
        return $this;
    }

    public function countPoints(): int
    {
        return count($this->xCoordinates);
    }

    /** Two polygons with the same points are both kept: the key is the object */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
