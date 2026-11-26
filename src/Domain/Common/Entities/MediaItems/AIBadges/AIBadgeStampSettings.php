<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use InvalidArgumentException;

/**
 * The look and the targets of the "AI" badge, built from the `Common.AIBadgeStamp` config array
 * (config/app/Common/AIBadgeStamp.php, which documents every key). Lengths are CSS pixels of the target display.
 *
 * Pure: holds numbers only, is never serialized or persisted, and is therefore not a DDD value object.
 */
class AIBadgeStampSettings
{
    /** @var string Corner: bottom right (the default) */
    public const string CORNER_BOTTOM_RIGHT = 'BOTTOM_RIGHT';

    /** @var string Corner: bottom left */
    public const string CORNER_BOTTOM_LEFT = 'BOTTOM_LEFT';

    /** @var string Corner: top right */
    public const string CORNER_TOP_RIGHT = 'TOP_RIGHT';

    /** @var string Corner: top left */
    public const string CORNER_TOP_LEFT = 'TOP_LEFT';

    /** @var string[] Every corner */
    public const array ALL_CORNERS = [
        self::CORNER_BOTTOM_RIGHT,
        self::CORNER_BOTTOM_LEFT,
        self::CORNER_TOP_RIGHT,
        self::CORNER_TOP_LEFT,
    ];

    /** @var float The step the tint alpha is raised by until the glyph reaches the minimum contrast */
    public const float TINT_ALPHA_STEP = 0.05;

    /**
     * @param int[] $tintRgb
     * @param array<int, int|float> $borderRgba
     * @param array<int, int|float> $glyphRgba
     * @param array<int, int|float> $glyphShadowRgba
     */
    public function __construct(
        public readonly string $corner,
        public readonly float $badgeSizeInCssPixels,
        public readonly float $marginShareOfBadge,
        public readonly int $minimumBadgeInPixels,
        public readonly float $maximumBadgeShareOfShorterSide,
        public readonly array $tintRgb,
        public readonly float $tintAlpha,
        public readonly float $maximumTintAlpha,
        public readonly float $blurInCssPixels,
        public readonly float $saturation,
        public readonly float $borderWidthInCssPixels,
        public readonly array $borderRgba,
        public readonly array $glyphRgba,
        public readonly float $glyphCapHeightShareOfBadge,
        public readonly float $glyphShadowBlurInCssPixels,
        public readonly array $glyphShadowRgba,
        public readonly float $minimumContrast,
        public readonly int $fallbackJpegQuality,
        public readonly string $defaultTargetName,
        public readonly AIBadgeStampTargets $targets,
    ) {
    }

    /**
     * Builds the settings from a `Common.AIBadgeStamp` config array. Settings that could not stamp an image are
     * rejected here rather than failing a publication later.
     *
     * @param array<string, mixed> $stampConfig
     * @throws InvalidArgumentException When a value is missing or out of range, or the default target is not defined.
     */
    public static function fromConfigArray(array $stampConfig): static
    {
        $corner = $stampConfig['corner'] ?? null;
        if (!in_array($corner, self::ALL_CORNERS, true)) {
            throw new InvalidArgumentException('corner must be one of ' . implode(', ', self::ALL_CORNERS));
        }
        $targetsConfig = $stampConfig['targets'] ?? null;
        if (!is_array($targetsConfig) || $targetsConfig === []) {
            throw new InvalidArgumentException('targets must name at least one target');
        }
        $targets = new AIBadgeStampTargets();
        foreach ($targetsConfig as $targetName => $targetConfig) {
            if (!is_string($targetName) || !is_array($targetConfig)) {
                throw new InvalidArgumentException('Every target is a name with its settings');
            }
            $target = AIBadgeStampTarget::fromConfigArray($targetName, $targetConfig);
            $targets->add($target);
        }
        $defaultTargetName = $stampConfig['defaultTarget'] ?? null;
        if (!is_string($defaultTargetName) || $targets->getTargetByName($defaultTargetName) === null) {
            throw new InvalidArgumentException('defaultTarget must name one of the targets');
        }
        $tintAlpha = static::readNumberInRange($stampConfig, 'tintAlpha', 0, 1);
        $maximumTintAlpha = static::readNumberInRange($stampConfig, 'maximumTintAlpha', $tintAlpha, 1);
        return new static(
            $corner,
            static::readNumberInRange($stampConfig, 'badgeSizeInCssPixels', 1, 1000),
            static::readNumberInRange($stampConfig, 'marginShareOfBadge', 0, 10),
            (int)static::readNumberInRange($stampConfig, 'minimumBadgeInPixels', 1, 10000),
            static::readNumberInRange($stampConfig, 'maximumBadgeShareOfShorterSide', 0.01, 1),
            static::readColour($stampConfig, 'tintRgb', 3),
            $tintAlpha,
            $maximumTintAlpha,
            static::readNumberInRange($stampConfig, 'blurInCssPixels', 0, 100),
            static::readNumberInRange($stampConfig, 'saturation', 0, 10),
            static::readNumberInRange($stampConfig, 'borderWidthInCssPixels', 0, 100),
            static::readColour($stampConfig, 'borderRgba', 4),
            static::readColour($stampConfig, 'glyphRgba', 4),
            static::readNumberInRange($stampConfig, 'glyphCapHeightShareOfBadge', 0.05, 0.9),
            static::readNumberInRange($stampConfig, 'glyphShadowBlurInCssPixels', 0, 100),
            static::readColour($stampConfig, 'glyphShadowRgba', 4),
            static::readNumberInRange($stampConfig, 'minimumContrast', 1, 21),
            (int)static::readNumberInRange($stampConfig, 'fallbackJpegQuality', 1, 100),
            $defaultTargetName,
            $targets,
        );
    }

    /**
     * The target of that name, or the default target for null.
     *
     * @throws InvalidArgumentException When no target of that name is defined.
     */
    public function getTarget(?string $targetName = null): AIBadgeStampTarget
    {
        $targetName ??= $this->defaultTargetName;
        return $this->targets->getTargetByName($targetName) ?? throw new InvalidArgumentException(
            sprintf('No AI badge target %s; the targets are %s', $targetName, implode(', ', $this->targets->getTargetNames()))
        );
    }

    /**
     * @param array<string, mixed> $stampConfig
     * @throws InvalidArgumentException When the value is not a number from $minimum to $maximum.
     */
    protected static function readNumberInRange(array $stampConfig, string $configKey, float $minimum, float $maximum): float
    {
        $configValue = $stampConfig[$configKey] ?? null;
        if ((!is_int($configValue) && !is_float($configValue)) || $configValue < $minimum || $configValue > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be a number from %s to %s', $configKey, $minimum, $maximum));
        }
        return (float)$configValue;
    }

    /**
     * An RGB (3 values 0–255) or RGBA (3 values 0–255 and an alpha 0–1) colour.
     *
     * @param array<string, mixed> $stampConfig
     * @return array<int, int|float>
     * @throws InvalidArgumentException When the colour is malformed.
     */
    protected static function readColour(array $stampConfig, string $configKey, int $valueCount): array
    {
        $colour = $stampConfig[$configKey] ?? null;
        if (!is_array($colour) || count($colour) !== $valueCount) {
            throw new InvalidArgumentException(sprintf('%s must hold %d values', $configKey, $valueCount));
        }
        $colour = array_values($colour);
        for ($channel = 0; $channel < 3; $channel++) {
            if (!is_int($colour[$channel]) || $colour[$channel] < 0 || $colour[$channel] > 255) {
                throw new InvalidArgumentException(sprintf('%s: red, green and blue are integers from 0 to 255', $configKey));
            }
        }
        if ($valueCount === 4 && ((!is_int($colour[3]) && !is_float($colour[3])) || $colour[3] < 0 || $colour[3] > 1)) {
            throw new InvalidArgumentException(sprintf('%s: the alpha is a number from 0 to 1', $configKey));
        }
        return $colour;
    }
}
