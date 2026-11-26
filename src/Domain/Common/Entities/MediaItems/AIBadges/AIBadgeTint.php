<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

/**
 * The tint the badge gets and the WCAG contrast the glyph reaches on it.
 *
 * Pure: two numbers, never serialized or persisted, and therefore not a DDD value object.
 */
class AIBadgeTint
{
    public function __construct(
        public readonly float $tintAlpha,
        public readonly float $contrast,
    ) {
    }
}
