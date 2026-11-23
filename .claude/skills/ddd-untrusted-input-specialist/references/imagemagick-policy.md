# ImageMagick allow-list policy (ImageMagick 7)

Replace the image's `policy.xml` (`/usr/local/etc/ImageMagick-7/policy.xml` for a source build,
`/etc/ImageMagick-7/policy.xml` for distribution packages; `magick -list policy` shows the path in use). ImageMagick 7
applies the policies in file order and the last match wins: deny everything, then allow the raster coders.

```xml
<policymap>
  <policy domain="resource" name="memory" value="256MiB"/>
  <policy domain="resource" name="map" value="512MiB"/>
  <policy domain="resource" name="disk" value="1GiB"/>
  <policy domain="resource" name="width" value="16KP"/>
  <policy domain="resource" name="height" value="16KP"/>
  <policy domain="resource" name="area" value="128MP"/>
  <policy domain="resource" name="time" value="60"/>
  <policy domain="resource" name="list-length" value="32"/>
  <policy domain="system" name="max-memory-request" value="256MiB"/>

  <policy domain="delegate" rights="none" pattern="*"/>
  <policy domain="filter" rights="none" pattern="*"/>
  <policy domain="path" rights="none" pattern="@*"/>

  <policy domain="module" rights="none" pattern="*"/>
  <policy domain="module" rights="read | write" pattern="{JPEG,PNG,GIF,WEBP,HEIC}"/>

  <policy domain="coder" rights="none" pattern="*"/>
  <policy domain="coder" rights="read | write" pattern="{JPEG,JPG,PNG,GIF,WEBP,HEIC,HEIF}"/>
</policymap>
```

- **Case**: some ImageMagick builds match `module` and `coder` patterns case-sensitively, so a policy listing only
  `JPEG` refuses `setImageFormat('jpeg')`. Code sets output formats by their upper-case coder name —
  `RasterImageDecoder::setOutputFormat($imagick, 'jpg')` sets `JPEG` (aliases resolved, non-raster names refused);
  never a lower-case literal in `setImageFormat()`. A policy that must also serve vendor code writing lower case lists
  both cases (`{JPEG,jpeg,PNG,png,…}`).
- Add a coder only for a format the application writes itself (e.g. `PNG32`); every added coder is reachable by
  foreign bytes through any unpinned read.
- Pseudo-formats (`plasma:`, `xc:`, `label:`, `text:`) are refused by this policy; code that draws with them needs its
  own coder entry and must never get foreign input.
- Remove Ghostscript (`gs`) from the image when nothing needs it; keep the `libgs` library if another package links it.
- Build step after installing the file: `php bin/console app:untrusted-input:check-runtime` (fails the build when a
  coder is open or `gs` is on the PATH).
- `RasterImageDecoder::applyResourceLimits()` stays at or below these ceilings (Imagick can only lower them).
