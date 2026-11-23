# Changelog

Notes on what applications must do after an upgrade. Every release is described in its commit message and tag.

## 3.40.1

- `app:untrusted-input:check-runtime` no longer needs GD: the raster check decodes an embedded PNG and writes JPEG
  through Imagick only (the behaviour test trait's PNG too).
- Output formats are set by their upper-case coder name: `RasterImageDecoder::setOutputFormat($imagick, 'jpg')` /
  `normalizeFormatName()`; `PhotoUtils` and `PDFDocument` use it. A case-sensitive allow-list `policy.xml` (only
  `JPEG`, `PNG`, …) no longer refuses Core's writes. Own code: replace lower-case `setImageFormat('jpeg')` literals,
  or list both cases in the policy's module and coder patterns.

## 3.40.0 — untrusted input

No foreign byte or string is executed or interpreted as code any more by Core.

New:
- `DDD\Infrastructure\Untrusted`: `RasterImageDecoder`, `SafeXml`, `ContainedPath`, `IsolatedProcess`,
  `ArchiveInspector`, `UntrustedContentHeaders`; services `GuardedHttpFetchService`, `UntrustedInputRuntimeCheckService`;
  `UntrustedContentResponseDto`; `Datafilter::sanitizeHtml()`; `ExcelDocument::writeCellValue()` / `createXlsxWriter()`.
- `DDD\Testing\UntrustedInput`: `UntrustedSinkScanTestCase` (sink scan with `config/untrusted-sinks-allowlist.php`),
  `UntrustedInputBehaviourTestTrait`, `UntrustedInputFixtures`.
- Command `app:untrusted-input:check-runtime`; skill `ddd-untrusted-input-specialist`.

Changed behaviour:
- `PhotoUtils::adjustImageToRequirements()` / `sliceImageVertically()` decode only JPEG, PNG, GIF, WebP, HEIC (else
  null); `getImageBase64FromUrl()` fetches through the guarded fetcher (no local paths, no private addresses).
- `MediaItemContentTrait`: width, height, size and format are measured from the bytes whenever bytes are present
  (client-declared values no longer count); content that is no image now fails `populateMediaItemContentInfo()` with
  "Invalid image format" also when the client declared a format. `loadFromUploadedFile()` refuses non-raster files
  (`UnsupportedImageFormatException`, an `ImagickException`); `loadFromSource()` fetches URLs guarded and refuses
  stream wrappers.
- `PDFDocument::getDocumentAsImage()` renders the first page with poppler `pdftoppm` (was Imagick/Ghostscript, all
  pages); `getTextContent()` uses `pdftotext` and `tesseract` as isolated processes. Install `poppler-utils` (and
  `tesseract-ocr` for OCR). `fromHTML()` runs Dompdf without PHP, JavaScript and remote resources: embed images as
  `data:` URIs.
- `ExcelDocument`: non-numeric strings are text cells (`=…` is no formula), formulas are never pre-calculated.
- `FileResponseDto` and subclasses send `X-Content-Type-Options: nosniff` and, except for PDF and unless the caller
  sets one, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`. `ImageResponseDto`
  serves only image types (others as `application/octet-stream` attachment). PDF/Excel download names are RFC 6266
  encoded.
- `#[RequestCache]`: `considerCurrentAuthAccountForCacheKey` defaults to true.
- Redis cache values naming a class outside `DDD\`, `App\`, Symfony HttpFoundation, Doctrine collections, Brick and a
  few PHP built-ins are cache misses (`Marshaller::ALLOWED_CLASS_NAMESPACE_PREFIXES`; extend in a subclass).

Applications must:
1. Add the sink scan test and their allow-list; replace own Imagick decodes, URL fetches, XML parses, shell calls and
   spreadsheet writes by the helpers.
2. Ship the ImageMagick allow-list policy, remove Ghostscript, run `app:untrusted-input:check-runtime` in the build.
3. Serve stored uploads with `UntrustedContentResponseDto` (server-detected type, never the stored client type).
