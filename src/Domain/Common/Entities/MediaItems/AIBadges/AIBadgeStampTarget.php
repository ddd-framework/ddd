<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Base\Entities\ValueObject;
use InvalidArgumentException;

/**
 * The display a stamped image is made for: the width it is shown at in CSS pixels and the share of the image each edge
 * keeps clear of the badge (platform buttons and captions over a story, a crop of the feed). Built by
 * {@see AIBadgeStampSettings::fromConfigArray()} from the `targets` of `Common.AIBadgeStamp`.
 *
 * Pure: holds numbers only.
 */
class AIBadgeStampTarget extends ValueObject
{
    /** @var string A feed on a phone, the image at full width */
    public const string MOBILE_FEED = 'MOBILE_FEED';

    /** @var string A feed on a desktop browser */
    public const string DESKTOP_FEED = 'DESKTOP_FEED';

    /** @var string A full-screen story or reel on a phone */
    public const string MOBILE_STORY_OR_REEL = 'MOBILE_STORY_OR_REEL';

    /** @var string A Facebook page post in the feed */
    public const string FACEBOOK_FEED = 'FACEBOOK_FEED';

    /** @var string An Instagram feed post */
    public const string INSTAGRAM_FEED = 'INSTAGRAM_FEED';

    /** @var string A LinkedIn post in the feed */
    public const string LINKEDIN_FEED = 'LINKEDIN_FEED';

    /** @var string An X post in the timeline */
    public const string X_FEED = 'X_FEED';

    /** @var string A Google Business Profile post, event or offer */
    public const string GOOGLE_BUSINESS_PROFILE_POST = 'GOOGLE_BUSINESS_PROFILE_POST';

    /** @var string A Facebook or Instagram story or reel */
    public const string META_STORY_OR_REEL = 'META_STORY_OR_REEL';

    /** @var string[] The edges a safe zone names */
    public const array SAFE_ZONE_EDGES = ['top', 'right', 'bottom', 'left'];

    public function __construct(
        public readonly string $name,
        public readonly float $displayWidthInCssPixels,
        public readonly float $topSafeZoneShare = 0.0,
        public readonly float $rightSafeZoneShare = 0.0,
        public readonly float $bottomSafeZoneShare = 0.0,
        public readonly float $leftSafeZoneShare = 0.0,
    ) {
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }

    /**
     * A target from its config entry: `displayWidthInCssPixels` (above 0) and optional `safeZoneShares` per edge, each
     * from 0 to below 0.5.
     *
     * @param array<string, mixed> $targetConfig
     * @throws InvalidArgumentException When the width or a share is out of range.
     */
    public static function fromConfigArray(string $name, array $targetConfig): static
    {
        $displayWidth = $targetConfig['displayWidthInCssPixels'] ?? null;
        if ((!is_int($displayWidth) && !is_float($displayWidth)) || $displayWidth <= 0) {
            throw new InvalidArgumentException(sprintf('The target %s needs a displayWidthInCssPixels above 0', $name));
        }
        $safeZoneShares = $targetConfig['safeZoneShares'] ?? [];
        if (!is_array($safeZoneShares)) {
            throw new InvalidArgumentException(sprintf('The safeZoneShares of the target %s must be an array', $name));
        }
        $sharesByEdge = [];
        foreach (self::SAFE_ZONE_EDGES as $edge) {
            $share = $safeZoneShares[$edge] ?? 0;
            if ((!is_int($share) && !is_float($share)) || $share < 0 || $share >= 0.5) {
                throw new InvalidArgumentException(
                    sprintf('The %s safe zone share of the target %s must be from 0 to below 0.5', $edge, $name)
                );
            }
            $sharesByEdge[$edge] = (float)$share;
        }
        return new static(
            $name,
            (float)$displayWidth,
            $sharesByEdge['top'],
            $sharesByEdge['right'],
            $sharesByEdge['bottom'],
            $sharesByEdge['left'],
        );
    }
}
