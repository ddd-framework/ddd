<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

/**
 * What one stamp produced: the image bytes and what was applied, for the caller's log.
 *
 * Holds raw binary, is never serialized or persisted, and is therefore not a DDD value object.
 */
class AIBadgeStampResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly string $imageBytes,
        public readonly string $format,
        public readonly string $targetName,
        public readonly AIBadgeGeometry $geometry,
        public readonly float $tintAlpha,
        public readonly float $contrast,
        public readonly ?int $jpegQuality,
        public readonly bool $isC2paManifestDropped,
        public readonly bool $isCarryingDigitalSourceType,
        public readonly array $warnings,
    ) {
    }
}
