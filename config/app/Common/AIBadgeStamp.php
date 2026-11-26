<?php

declare(strict_types=1);

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampSettings;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampTarget;

/**
 * Framework DEFAULT of the "AI" badge an application stamps on a raster image (JPEG, PNG, WebP) before it publishes the
 * image somewhere, read whole as `Common.AIBadgeStamp` by {@see AIBadgeStampSettings::fromConfigArray()}. A consuming
 * application overrides this whole file at `config/app/Common/AIBadgeStamp.php`.
 *
 * Every length is given in CSS pixels of the TARGET display (the width the image is shown at in the target's standard
 * view, e.g. a feed on a phone) and converted to image pixels with `imageWidth / displayWidthInCssPixels`, so the badge
 * looks the same size on screen whatever the width of the file.
 *
 * - corner: one of the AIBadgeStampSettings::CORNER_* values.
 * - badgeSizeInCssPixels: the diameter of the round badge on the target display.
 * - marginShareOfBadge: the gap between the badge and the two image edges of its corner, as a share of the badge
 *   diameter (0.5: the gap is the radius, the centre sits one diameter in from both edges).
 * - minimumBadgeInPixels / maximumBadgeShareOfShorterSide: the bounds of the badge diameter in image pixels.
 * - tintRgb, tintAlpha, maximumTintAlpha: the glass tint; the alpha is raised in steps of 0.05 up to the maximum until
 *   the glyph reaches minimumContrast on the badge (a very light corner gets a darker glass).
 * - blurInCssPixels (Gaussian sigma), saturation: the frosted background of the badge.
 * - borderWidthInCssPixels, borderRgba: the inner ring; a width of 0 (the default) draws no ring.
 * - glyphRgba, glyphCapHeightShareOfBadge, glyphShadowBlurInCssPixels, glyphShadowRgba: the letters "AI", drawn as a
 *   vector shape (no font), their cap height a share of the badge diameter.
 * - fallbackJpegQuality: a JPEG is written at its own quality (estimated from its quantization tables) and its own
 *   chroma subsampling; this quality is used only when the input's quality cannot be estimated.
 * - defaultTarget: the target used when the caller names none.
 * - targets: the display a stamped image is made for; per target its display width in CSS pixels and the share of the
 *   image each edge keeps clear of the badge (platform buttons, captions, crops).
 */
return [
    'corner' => AIBadgeStampSettings::CORNER_BOTTOM_RIGHT,
    'badgeSizeInCssPixels' => 24,
    'marginShareOfBadge' => 0.5,
    'minimumBadgeInPixels' => 20,
    'maximumBadgeShareOfShorterSide' => 0.15,
    'tintRgb' => [120, 120, 120],
    'tintAlpha' => 0.35,
    'maximumTintAlpha' => 0.85,
    'blurInCssPixels' => 10,
    'saturation' => 1.6,
    'borderWidthInCssPixels' => 0,
    'borderRgba' => [255, 255, 255, 0.25],
    'glyphRgba' => [255, 255, 255, 0.95],
    'glyphCapHeightShareOfBadge' => 0.32,
    'glyphShadowBlurInCssPixels' => 2,
    'glyphShadowRgba' => [0, 0, 0, 0.35],
    'minimumContrast' => 3.0,
    'fallbackJpegQuality' => 92,
    'defaultTarget' => AIBadgeStampTarget::MOBILE_FEED,
    'targets' => [
        // generic displays
        AIBadgeStampTarget::MOBILE_FEED => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::DESKTOP_FEED => ['displayWidthInCssPixels' => 600],
        AIBadgeStampTarget::MOBILE_STORY_OR_REEL => [
            'displayWidthInCssPixels' => 390,
            'safeZoneShares' => ['top' => 0.14, 'right' => 0.06, 'bottom' => 0.35, 'left' => 0.06],
        ],
        // per platform and format, each measured on a phone, the smallest common display
        AIBadgeStampTarget::FACEBOOK_FEED => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::INSTAGRAM_FEED => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::LINKEDIN_FEED => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::X_FEED => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::GOOGLE_BUSINESS_PROFILE_POST => ['displayWidthInCssPixels' => 390],
        AIBadgeStampTarget::META_STORY_OR_REEL => [
            'displayWidthInCssPixels' => 390,
            'safeZoneShares' => ['top' => 0.14, 'right' => 0.06, 'bottom' => 0.35, 'left' => 0.06],
        ],
    ],
];
