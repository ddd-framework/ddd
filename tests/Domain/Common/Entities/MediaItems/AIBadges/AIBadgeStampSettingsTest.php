<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGeometry;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampSettings;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampTarget;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The badge settings from the `Common.AIBadgeStamp` config: settings that could not stamp an image are refused when
 * they are read, not when an image is published.
 */
class AIBadgeStampSettingsTest extends TestCase
{
    public function testTheShippedConfigIsValidAndNamesTheDefaultTarget(): void
    {
        $settings = AIBadgeStampSettings::fromConfigArray($this->readShippedConfig());

        self::assertSame(AIBadgeStampTarget::MOBILE_FEED, $settings->getTarget()->name, 'null names the default target');
        self::assertSame(AIBadgeStampTarget::META_STORY_OR_REEL, $settings->getTarget(AIBadgeStampTarget::META_STORY_OR_REEL)->name);
        self::assertSame(0.35, $settings->getTarget(AIBadgeStampTarget::META_STORY_OR_REEL)->bottomSafeZoneShare);
    }

    public function testBorderWidthZeroDrawsNoRing(): void
    {
        $settings = AIBadgeStampSettings::fromConfigArray(array_replace($this->readShippedConfig(), ['borderWidthInCssPixels' => 0]));

        self::assertSame(0, AIBadgeGeometry::forImage(1080, 1350, $settings, $settings->getTarget())->borderWidthInPixels);
    }

    public function testAnUnknownTargetIsRefused(): void
    {
        $settings = AIBadgeStampSettings::fromConfigArray($this->readShippedConfig());

        $this->expectException(InvalidArgumentException::class);
        $settings->getTarget('NO_SUCH_TARGET');
    }

    /** @dataProvider provideInvalidConfigValues */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideInvalidConfigValues')]
    public function testSettingsThatCouldNotStampAreRefused(array $replacedConfigValues): void
    {
        $this->expectException(InvalidArgumentException::class);
        AIBadgeStampSettings::fromConfigArray(array_replace($this->readShippedConfig(), $replacedConfigValues));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideInvalidConfigValues(): iterable
    {
        yield 'an unknown corner' => [['corner' => 'MIDDLE']];
        yield 'no default target' => [['defaultTarget' => null]];
        yield 'an unknown default target' => [['defaultTarget' => 'NO_SUCH_TARGET']];
        yield 'a maximum tint alpha below the tint alpha' => [['tintAlpha' => 0.5, 'maximumTintAlpha' => 0.4]];
        yield 'a colour channel above 255' => [['tintRgb' => [120, 300, 120]]];
        yield 'a colour without its alpha' => [['glyphRgba' => [255, 255, 255]]];
        yield 'an alpha above 1' => [['glyphShadowRgba' => [0, 0, 0, 1.5]]];
        yield 'no targets' => [['targets' => []]];
        yield 'a safe zone share of one half' => [['targets' => [AIBadgeStampTarget::MOBILE_FEED => ['displayWidthInCssPixels' => 390, 'safeZoneShares' => ['bottom' => 0.5]]]]];
        yield 'a display width of 0' => [['targets' => [AIBadgeStampTarget::MOBILE_FEED => ['displayWidthInCssPixels' => 0]]]];
    }

    /** @return array<string, mixed> */
    protected function readShippedConfig(): array
    {
        return require dirname(__DIR__, 6) . '/config/app/Common/AIBadgeStamp.php';
    }
}
