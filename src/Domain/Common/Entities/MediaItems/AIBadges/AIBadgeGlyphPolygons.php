<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The closed polygons of the badge's glyph, in drawing order; filled together with the even-odd rule.
 *
 * @property AIBadgeGlyphPolygon[] $elements
 * @method AIBadgeGlyphPolygon|null first()
 * @method AIBadgeGlyphPolygon[] getElements()
 */
class AIBadgeGlyphPolygons extends ObjectSet
{
}
