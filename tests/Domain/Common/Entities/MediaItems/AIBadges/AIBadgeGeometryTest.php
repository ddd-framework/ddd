<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGeometry;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampSettings;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampTarget;
use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use LengthException;
use PHPUnit\Framework\TestCase;

/**
 * Size and place of the badge: CSS pixels of the target display scaled by the displayed image width, the bounds, the
 * corner, the safe zones, and the stored square of an image with an EXIF orientation.
 */
class AIBadgeGeometryTest extends TestCase
{
    public function testTheWorkedExamplesOfTheShippedConfig(): void
    {
        $settings = $this->buildSettings();

        $portraitFeed = AIBadgeGeometry::forImage(1080, 1350, $settings, $settings->getTarget(AIBadgeStampTarget::MOBILE_FEED));
        self::assertSame([66, 33, 981, 1251], [$portraitFeed->badgeDiameterInPixels, $portraitFeed->marginInPixels, $portraitFeed->left, $portraitFeed->top]);

        $squareFeed = AIBadgeGeometry::forImage(1200, 1200, $settings, $settings->getTarget(AIBadgeStampTarget::MOBILE_FEED));
        self::assertSame([74, 37, 1089, 1089], [$squareFeed->badgeDiameterInPixels, $squareFeed->marginInPixels, $squareFeed->left, $squareFeed->top]);

        $story = AIBadgeGeometry::forImage(1080, 1920, $settings, $settings->getTarget(AIBadgeStampTarget::META_STORY_OR_REEL));
        self::assertSame([66, 949, 1182], [$story->badgeDiameterInPixels, $story->left, $story->top], 'the safe zone pushes the badge in');
    }

    public function testTheUpperBoundWinsOnATinyImage(): void
    {
        $settings = $this->buildSettings();

        $geometry = AIBadgeGeometry::forImage(100, 100, $settings, $settings->getTarget());

        self::assertSame(15, $geometry->badgeDiameterInPixels, 'the minimum of 20 px is capped at 15 % of the shorter side');
    }

    public function testAnImageTooSmallForTheBadgeIsRefused(): void
    {
        $settings = $this->buildSettings();

        $this->expectException(LengthException::class);
        AIBadgeGeometry::forImage(5, 5, $settings, $settings->getTarget());
    }

    public function testEachCorner(): void
    {
        $placesByCorner = [
            AIBadgeStampSettings::CORNER_TOP_LEFT => [37, 37],
            AIBadgeStampSettings::CORNER_TOP_RIGHT => [1089, 37],
            AIBadgeStampSettings::CORNER_BOTTOM_LEFT => [37, 1089],
            AIBadgeStampSettings::CORNER_BOTTOM_RIGHT => [1089, 1089],
        ];
        foreach ($placesByCorner as $corner => $expectedPlace) {
            $settings = $this->buildSettings(['corner' => $corner]);
            $geometry = AIBadgeGeometry::forImage(1200, 1200, $settings, $settings->getTarget());
            self::assertSame($expectedPlace, [$geometry->left, $geometry->top], $corner);
        }
    }

    public function testASafeZoneOnTheTopLeftPushesTheBadgeIn(): void
    {
        $settings = $this->buildSettings(['corner' => AIBadgeStampSettings::CORNER_TOP_LEFT]);

        $geometry = AIBadgeGeometry::forImage(1080, 1920, $settings, $settings->getTarget(AIBadgeStampTarget::META_STORY_OR_REEL));

        self::assertSame([65, 269], [$geometry->left, $geometry->top], '6 % of the width, 14 % of the height');
    }

    public function testATurnedImageIsMeasuredAsItDisplaysAndPlacedOnItsStoredPixels(): void
    {
        $settings = $this->buildSettings();

        // stored landscape 1350×1080, displayed as portrait 1080×1350 after a turn to the right
        $geometry = AIBadgeGeometry::forImage(1350, 1080, $settings, $settings->getTarget(), ExifOrientation::TURNED_RIGHT);

        self::assertSame(66, $geometry->badgeDiameterInPixels, 'scaled by the displayed width 1080');
        self::assertSame([981, 1251], [$geometry->displayLeft, $geometry->displayTop]);
        self::assertSame([1251, 33], [$geometry->left, $geometry->top], 'the displayed bottom right is the stored top right');
    }

    public function testEveryOrientationMapsTheDisplayedSquareBackOntoTheStoredPixels(): void
    {
        $storedWidth = 300;
        $storedHeight = 200;
        $diameter = 10;
        foreach (range(ExifOrientation::UPRIGHT, ExifOrientation::TURNED_LEFT) as $exifOrientation) {
            $isSideways = ExifOrientation::isSideways($exifOrientation);
            $displayWidth = $isSideways ? $storedHeight : $storedWidth;
            $displayHeight = $isSideways ? $storedWidth : $storedHeight;
            // a square at the displayed bottom right
            $displayLeft = $displayWidth - 5 - $diameter;
            $displayTop = $displayHeight - 5 - $diameter;
            $storedLeft = AIBadgeGeometry::computeStoredLeft($displayLeft, $displayTop, $diameter, $storedWidth, $storedHeight, $exifOrientation);
            $storedTop = AIBadgeGeometry::computeStoredTop($displayLeft, $displayTop, $diameter, $storedWidth, $storedHeight, $exifOrientation);
            // the stored square's centre, displayed with the orientation, is the displayed square's centre
            [$centreX, $centreY] = $this->displayStoredPoint($storedLeft + $diameter / 2, $storedTop + $diameter / 2, $storedWidth, $storedHeight, $exifOrientation);
            self::assertEqualsWithDelta([$displayLeft + $diameter / 2, $displayTop + $diameter / 2], [$centreX, $centreY], 0.001, 'orientation ' . $exifOrientation);
        }
    }

    /**
     * Where a viewer displays a point of the stored pixels (continuous coordinates).
     *
     * @return float[]
     */
    protected function displayStoredPoint(float $storedX, float $storedY, int $storedWidth, int $storedHeight, int $exifOrientation): array
    {
        return match ($exifOrientation) {
            ExifOrientation::MIRRORED => [$storedWidth - $storedX, $storedY],
            ExifOrientation::UPSIDE_DOWN => [$storedWidth - $storedX, $storedHeight - $storedY],
            ExifOrientation::FLIPPED => [$storedX, $storedHeight - $storedY],
            ExifOrientation::TRANSPOSED => [$storedY, $storedX],
            ExifOrientation::TURNED_RIGHT => [$storedHeight - $storedY, $storedX],
            ExifOrientation::TRANSVERSED => [$storedHeight - $storedY, $storedWidth - $storedX],
            ExifOrientation::TURNED_LEFT => [$storedY, $storedWidth - $storedX],
            default => [$storedX, $storedY],
        };
    }

    /**
     * The shipped settings with some keys replaced.
     *
     * @param array<string, mixed> $replacedConfigValues
     */
    protected function buildSettings(array $replacedConfigValues = []): AIBadgeStampSettings
    {
        $stampConfig = require dirname(__DIR__, 6) . '/config/app/Common/AIBadgeStamp.php';
        return AIBadgeStampSettings::fromConfigArray(array_replace($stampConfig, $replacedConfigValues));
    }
}
