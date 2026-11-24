# Changelog

Notes on what applications must do after an upgrade. Every release is described in its commit message and tag.

## 3.42.0

- New Composer bin `ddd-server-messages`: a PHP package lists the server messages its endpoints answer with in
  `i18n/server-messages.json` (`build`, CI `build --check`: outdated file or a hint under 40 characters fails), an
  application merges those of every installed package (`collect --vendor-dir vendor`). README › "Server messages of
  packages (text export)".
- Applications with a web text export: add the collected keys to the export (like the npm packages' `i18n-keys.json`)
  and never prune a key a package lists — a message that moved into a package is no longer found by a scan of the
  application's own PHP, but its endpoint still sends it.

## 3.41.2

- Config: a config file and a folder of the same name side by side (`Common/Translations.php` and
  `Common/Translations/AppTexts.php`, in one root or across application and module roots) are both found. A key the
  file defines wins; a key it does not define is looked up in the folder, in the same root and in every lower root.
  Before, the file hid the folder: `Config::get('Common.Translations.AppTexts')` answered null while the application
  had its own `Common/Translations.php`.
- Unchanged: an application file still replaces the module file at the same path whole (a key the application file
  leaves out is not taken from the module file).
- Reading the node itself (`Common.Translations`) answers the file plus the folder entries read so far. For a key
  both hold, the higher root wins, and within one root the file wins. Before, a node read after one of its folder files
  could answer only that folder entry. Folders are still never listed.
- Applications: a workaround that reads a folder's file directly (e.g. `require` of
  `config/app/Common/Translations/AppTexts.php`) can go back to `Config::get()`.
- Removed the protected helpers `searchForFileInRootDirectories()` / `searchForFileInRootDirectory()` of `Config`
  (replaced by `resolveInConfigDirectory()`).

## 3.41.1

- Console: a module command with `aliases` in its `AsCommand` attribute is listed once (name plus aliases). The module
  pass gave it the plain `console.command` tag before the autoconfiguration added the attribute's tag, and the
  console's command pass took the joined `alias|name|alias` string of the second tag as one more alias (shown in `list`). The new
  `ConsoleCommandTagsOrderPass` (registered by `DDDKernel`) puts the named tag first and drops repeated names. Nothing
  to do in an application; a kernel that does not extend `DDDKernel` registers the pass itself
  (`PassConfig::TYPE_BEFORE_OPTIMIZATION`, priority -5).
- Docs: the package cuts and names rulebook (domains, one feature word per package, `ddd-agent-*` organs, agent skill
  names, package ids, right constants, package or host) in `ddd-module-orchestrator`, AGENTS.md and the README.

## 3.41.0

- Owned namespaces: `DDDModule::getOwnedNamespaces()` (default: none) and the check
  `DDD\Testing\Modules\ModuleNamespacesTestCase` (with `ModuleNamespaceChecker`, `ModuleNamespacePackage`,
  `ModuleNamespaceViolation(s)`): no class name and no `config/app/` file shipped by two installed packages, and no class
  of a package outside the namespaces it declares. Hosts and modules add one test class:
  `class ModuleNamespacesTest extends \DDD\Testing\Modules\ModuleNamespacesTestCase { protected function
  getProjectDirectory(): string { return dirname(__DIR__); } }`. It reads source files only (no kernel).
- Config: reading a config file no longer drops the files already read below the same path from the process cache
  (`searchInConfig()` reset every path element; reading one `AI.Skills.*` file dropped every other `AI.*` entry, which
  was then read from disk again).

## 3.40.2

- Same-path overrides: an application class at the same path under `App\` replaces a framework or module class only
  when it extends it (or the framework class does not exist). An unrelated application class that happens to carry the
  name of a class a newer release added no longer silently takes its place (it used to fail far away with a
  TypeError); it is ignored and a line names it in the error log: rename it or make it extend the framework class.

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
