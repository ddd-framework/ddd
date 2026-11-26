# AI badge stamp (`AIBadgeStampService`)

Stamps a small round frosted-glass "AI" badge into a raster image (JPEG, PNG, WebP) for one target display.

## When to call it — and when never

- Call it where the APPLICATION decides an image needs the mark: right before it publishes an AI-generated or
  AI-edited image on a social platform, for that platform's target.
- Nothing in the framework or its modules calls it. No image generation path stamps; a generated image stays as it is.
- The jurisdiction rule (which countries need a badge) belongs to the application; the package carries no country list.
- Websites never get a burnt-in badge: they show a CSS overlay of fixed size (backdrop blur and saturate) instead.

```php
/** @var AIBadgeStampService $aIBadgeStampService */
$aIBadgeStampService = DDDService::instance()->getService(AIBadgeStampService::class);
$stampResult = $aIBadgeStampService->buildImageBytesWithAIBadge($imageBytes, AIImageKind::GENERATED, AIBadgeStampTarget::INSTAGRAM_FEED);
$publishedBytes = $stampResult->imageBytes;   // + format, geometry, tintAlpha, contrast, jpegQuality, warnings
```

- `$aiImageKind`: `AIImageKind::GENERATED` or `EDITED`; `NOT_AI` throws `InvalidArgumentException`.
- `$targetName`: a target of the config; null = `defaultTarget`. `$settings`: null = the config.
- Throws `InvalidArgumentException` for SVG and every other non-raster format, an animated image or an unknown
  target; `LengthException` when the badge does not fit. It never returns the unstamped image as stamped.

## Size, place, look

- The size comes from the TARGET display, not from the file: 24 CSS px on a 390 px phone feed; image pixels = CSS px ×
  displayed image width / `displayWidthInCssPixels`; at least 20 px, at most 15 % of the shorter side. The margin is
  half the badge.
- Bottom right by default (`corner`); a target's `safeZoneShares` keep edges clear (Meta story/reel 14/6/35/6 %).
- An EXIF orientation is honoured: corner and safe zones are decided on the image as it displays, the badge is turned
  onto the stored pixels and displays upright.
- Look: Gaussian blur and CSS `saturate()` of the background, grey tint raised in steps of 0.05 up to 0.85 until the
  glyph reaches WCAG contrast 3.0, no ring (`borderWidthInCssPixels` > 0 adds one), the letters as vector polygons
  (`AIBadgeGlyph`, no font).

## Config

`Common.AIBadgeStamp` — the framework default `config/app/Common/AIBadgeStamp.php` documents every key. An application
replaces it whole with its own `config/app/Common/AIBadgeStamp.php` (copy every key it keeps). The settings are read
once per process (`AIBadgeStampService::forgetShippedSettings()` in tests). Invalid settings throw when they are read.

## Metadata

- A JPEG is written at its own estimated quality and its own chroma subsampling (`fallbackJpegQuality` only when the
  quality cannot be estimated); CMYK stays CMYK.
- EXIF, IPTC and XMP of a JPEG or PNG input are put back (`ImageMetadata`); without XMP, the IPTC `DigitalSourceType`
  of the kind is written (`AIImageIptcTag`: `trainedAlgorithmicMedia`, `compositeWithTrainedAlgorithmicMedia`).
- A C2PA manifest is dropped (the pixels changed, its signature would not hold): `isC2paManifestDropped` and a warning.
- WebP: metadata only as far as ImageMagick keeps it, no digital source type; a warning says so.
