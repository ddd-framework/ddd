---
name: ddd-untrusted-input-specialist
description: "Keeps foreign input (uploads, mail, URLs, API bodies, model output, drive files, client-declared types) from ever being executed or interpreted as code. Covers the 8-point rule and the DDD Infrastructure Untrusted helpers: RasterImageDecoder (pinned Imagick decode, magic-byte allow-list), GuardedHttpFetchService (SSRF-safe fetch, pinned connect, re-checked redirects), SafeXml, ContainedPath (path traversal), IsolatedProcess (argument array, stripped env, timeout), ArchiveInspector (zip bombs), UntrustedContentResponseDto with nosniff and CSP sandbox, ExcelDocument text cells (formula injection), Datafilter sanitizeHtml; the sink scan UntrustedSinkScanTestCase with config/untrusted-sinks-allowlist.php, behaviour tests, the ImageMagick allow-list policy.xml and app:untrusted-input:check-runtime. Use when handling an upload or image, fetching a user URL, serving an attachment, parsing XML, SVG, PDF, Office or ZIP, running a converter, writing an Excel export, the sink scan fails, or Ghostscript is found."
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Untrusted Input Specialist

No byte or string from outside is ever executed or interpreted as code by our servers, and nothing surprises us.

**Foreign** = everything our own code did not produce: uploads, inbound mail, URLs and what they answer, third-party API
bodies, model output, files in a drive or workspace, and anything a client sends ABOUT them (name, type, size,
dimensions).

## The rule (8 points)

1. Foreign bytes never reach an interpreter: no PostScript / PDF / SVG / MVG / MSL decode by ImageMagick, no Office
   conversion outside a sandbox, no `unserialize` without `allowed_classes`, no YAML object tags, no `include`, no
   template from a string, no formula, no `eval`.
2. The type comes from the magic bytes against an allow-list, the decoder is pinned to it, resource limits are set
   first. A client-declared type, extension, size or dimension is never stored or served.
3. Foreign bytes are served as attachment with a server-chosen type, `X-Content-Type-Options: nosniff` and
   `Content-Security-Policy: ... sandbox`; inline only for JPEG, PNG, GIF, WebP.
4. A URL from outside is fetched only through `GuardedHttpFetchService`. A path from outside is contained
   (`ContainedPath`) before any file function sees it.
5. XML and HTML from outside are parsed only through `SafeXml` (no network, no DTD, no entities, no `PARSEHUGE`, size cap).
6. Processes are `IsolatedProcess`: argument array, server-generated paths, stripped environment, timeout.
7. Strings written into spreadsheets are text (`ExcelDocument::writeCellValue()`). HTML is sanitised once, at output
   (`Datafilter::sanitizeHtml()`), and never edited afterwards.
8. A raw call to a sink outside the helpers needs an entry with its reason in the package's
   `config/untrusted-sinks-allowlist.php`; the sink scan test enforces it.

## Helpers (`DDD\Infrastructure\Untrusted\…`, static unless noted)

| Need | Use | Never |
|---|---|---|
| decode an image | `RasterImageDecoder::readBlob($bytes)` / `readFirstFrameOfFile($path)`; `probe($bytes)` → `RasterImageInfo` (width, height, mimeType from the bytes); `detectFormat()` / `isAllowed()`; encode with `setOutputFormat($imagick, 'jpg')` (upper-case coder name) | `new Imagick()` + `readImageBlob()` (the magic bytes pick the coder: PostScript runs Ghostscript) |
| fetch a URL from outside | `GuardedHttpFetchService::fetch($url, $maxBytes, $timeout, $maxRedirects, $allowedContentTypes)` (container service) → `GuardedHttpFetchResult` (`isSuccessful()`, `body`, `refusalReason`) | Guzzle / `file_get_contents($url)` / `get_headers()` on a user URL |
| parse XML / HTML | `SafeXml::loadXml()` / `loadSimpleXml()` / `loadHtml()` / `createReader()` | `DOMDocument::loadXML()` with `LIBXML_NOENT` / `DTDLOAD` / `PARSEHUGE`, `simplexml_load_string()` |
| a path from a request | `ContainedPath::resolveExistingFile($root, $relative, ['svg'])` / `resolveNewFile()` | `file_get_contents($root . $requestDto->path)` |
| run a converter / scanner | `IsolatedProcess::create($binary, $arguments, $workingDirectory, $timeout)` + `createWorkingDirectory()` / `removeWorkingDirectory()` | `exec()`, `shell_exec()`, `Process::fromShellCommandline()`, foreign names on a command line |
| look into a ZIP (OOXML, ODF) | `ArchiveInspector::inspectZip($path)` → `ArchiveInspection` (entry names, sizes, `hasUnsafeEntryNames`); `readEntry()` with a cap | `ZipArchive::extractTo()`, `PharData` |
| serve foreign bytes | `new UntrustedContentResponseDto($bytes, $serverType, $fileName, $inlineWanted)` or `UntrustedContentHeaders::applyToResponse()` | the stored client type as Content-Type, a raw file name in `Content-Disposition` |
| a spreadsheet export | `ExcelDocument::fromArray()` / `addSheetFromData()`; own sheets: `ExcelDocument::writeCellValue()`, `createXlsxWriter()` | `$sheet->setCellValue()` / `fromArray()` with user strings |
| HTML printed raw | `Datafilter::sanitizeHtml($html)` | `sanitizeInput()` for output (it decodes entities back into tags) |

Errors: refused content throws `UntrustedContentException` (a 400); a refused image format throws
`UnsupportedImageFormatException` (an `ImagickException`, so existing "could not decode" handling keeps working).

## What Core already does for you

- `PhotoUtils::adjustImageToRequirements()` / `sliceImageVertically()` decode through the decoder (non-raster → null);
  `getImageBase64FromUrl()` fetches through the guarded fetcher.
- `MediaItemContentTrait`: `isMediaItemContentInfoGenerated()` is false while bytes exist that the server has not
  measured; `populateMediaItemContentInfo()` measures from the bytes; `loadFromUploadedFile()` decodes pinned;
  `loadFromSource()` fetches guarded and refuses stream wrappers (`phar://`, `php://`, `data:`).
- `PDFDocument`: pages by poppler `pdftoppm`, text by `pdftotext`, OCR by `tesseract`, each an `IsolatedProcess`; never
  Imagick or Ghostscript. `fromHTML()`: Dompdf without PHP, JavaScript and remote resources (`data:` images work).
- `FileResponseDto` (and every subclass) sends `nosniff` and the sandbox CSP (PDF without CSP); `ImageResponseDto`
  serves only image types, anything else as `application/octet-stream` attachment; PDF / Excel downloads encode the
  file name.
- `#[RequestCache]` varies the key by the signed-in account by default (a cached answer never skips the controller's
  own access check for another account); `considerCurrentAuthAccountForCacheKey: false` only for routes whose answer
  is the same for every account and whose access is decided by the firewall.
- The Redis cache marshaller unserialises only classes under `DDD\`, `App\`, Symfony HttpFoundation, Doctrine
  collections, Brick and a few PHP built-ins; anything else is a cache miss (`Marshaller::ALLOWED_CLASS_NAMESPACE_PREFIXES`).

## Enforcement

- **Sink scan** — every package and app adds one test (needs `nikic/php-parser`, a Core dependency):
  ```php
  class UntrustedSinkScanTest extends \DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase
  {
      protected function getPackageRootDirectory(): string { return dirname(__DIR__); }
  }
  ```
  It parses `src/` and fails on each raw sink call not covered by `config/untrusted-sinks-allowlist.php`
  (`['file' => 'src/…', 'sink' => 'process', 'lines' => [10, 20], 'reason' => '…']`, `lines` optional), and on
  entries that no longer match a call. Sink names and what they catch: [references/sinks.md](references/sinks.md).
- **Behaviour tests** — `use \DDD\Testing\UntrustedInput\UntrustedInputBehaviourTestTrait;` in a test class of the
  app (on the base that boots its framework objects): PostScript / EPS / PDF / SVG / MVG / MSL / HTML refused by the
  decoder, a GIF polyglot decoded only as GIF, private and redirected-to-private URLs refused, `=` strings stay text in
  XLSX, the security headers present. Bodies for own tests: `UntrustedInputFixtures::INTERPRETER_BODIES`.
- **Runtime check** — `php bin/console app:untrusted-input:check-runtime` (`--format=json`) in the image build and
  deploy: ImageMagick must refuse PS/EPS/PDF/SVG/MVG/MSL/TEXT, `gs` must not be on the PATH, PNG/JPEG must work; exit 1
  otherwise. Health checks call `UntrustedInputRuntimeCheckService::checkRuntime()->isSafe()`.

## The ImageMagick policy (second fence)

The decoder is the first fence; the image's `policy.xml` must deny every coder except the raster ones, every
delegate and `@file` lists, and set resource ceilings — so vendor code or a forgotten call cannot reach Ghostscript
either. Template and install notes: [references/imagemagick-policy.md](references/imagemagick-policy.md). Remove
Ghostscript from images that do not need it (LibreOffice and ClamAV do not).

## Traps

- **Lower-case output formats** (`setImageFormat('jpeg')`) are refused by a case-sensitive allow-list policy: use
  `RasterImageDecoder::setOutputFormat()` (upper-case coder name).
- **Setting the format AFTER the read** does nothing: `setFormat()` must precede `readImageBlob()` — use the decoder.
- **A client's `fileFormat`, `width`, `fileSize`** are never trusted: measure (`RasterImageDecoder::probe()`).
- **Exact-match deny-lists of types** (`text/html` vs `text/html; charset=utf-8`): normalise
  (`UntrustedContentHeaders::normalizeContentType()`) and use allow-lists.
- **`html_entity_decode()` after a purifier** turns `&lt;script&gt;` into a tag: sanitise for output with
  `sanitizeHtml()` and never edit sanitised HTML by string replacement.
- **Redirects**: following them in the HTTP client skips the guard for the new host — the fetcher re-guards each hop.
- **Route requirement `.+`** on a parameter that reaches a file function = path traversal: contain it.
- **Model output** is foreign: a URL, markdown image or file path an agent produced goes through the same helpers.
- **Office files**: macros live in OLE storages (`Macros`, `_VBA_PROJECT_CUR`) and in OOXML parts typed
  `application/vnd.ms-office.vbaProject` — decide by `[Content_Types].xml`, not by an entry name.

## Cross-Reference

- Files in object storage, presigned URLs: `ddd-service-specialist` › Object Storage · response DTOs: `ddd-endpoint-specialist`
- MediaItem entities: `ddd-entity-specialist` · secrets in the environment of child processes: `ddd-secrets-and-env-hardening-specialist`
