# Changelog

Notes on what applications must do after an upgrade. Every release is described in its commit message and tag.

## 3.48.2

- README: section "Files and file sources", and the two install hooks for agent skills (`ddd-link-skills` in
  `post-install-cmd` / `post-update-cmd`, `ddd-web link-skills` in `postinstall`) in the installation steps.
- Skill `ddd-files-specialist`: registration through `setFileSources()`, the tests a source needs, a security model
  section. Documentation only.

## 3.48.1

- New skill `ddd-files-specialist`: file sources (`FileSource` port, references `<source>/<id>`, `FileSourcesService`,
  the flow rule `FileFlowPolicyService` and its same-path replacement, `FileSourcesControllerTrait`), a source skeleton
  and checklist. Documentation only; no code change.

## 3.48.0

**Files of any store behind one port.** New, nothing existing changes; no table.

- **New port `DDD\Domain\Common\Services\Files\FileSource`** (tag `ddd.file_source`, autoconfigured): a store of
  files answers `getSourceName()` (`[a-z][a-z0-9-]{0,31}`), `findFilesFacts(string[] $ids): FileFactsSet` (only what
  the signed-in account may read; unknown and unreadable ids are left out, never told apart) and
  `readFileContent(string $id, int $maxBytes): FileContent` (same rights; larger content is refused, not cut).
- **A file is named by its reference `<source>/<id>`** (`media/901`, `drive/3172`): `FileReference`
  (`tryFromString()`, `fromString()`, `toString()`; the id is `[A-Za-z0-9_-]{1,128}`).
- **Value objects** in `DDD\Domain\Common\Entities\Files\Sources`: `FileFacts` (name, `FileFamily`, server-measured
  content type, size, sha256, width / height, `FileAudience`, `isContentUntrusted`, `containerLabel`, `openLink` set
  only through `setOpenLinkIfSafe()` — an application path, never a content URL), `FileFactsSet`, `FileContent` (bytes
  never serialized), `FileImagePreview`, `FileFlowTarget` (STORE with audience, store name, folder, own container;
  MODEL; OPERATOR), `FileFlowDecision` (`isAllowed`, `refusalReason`, `requiresOwnerConsent`, `stripsMetadata`,
  `isDurableCopyAllowed`, `expiresAfterDays`).
- **Registry `FileSourcesService`**: `findFileReference()` / `resolveFileReference()` (form and registered source),
  `findFileFacts()`, `requireFileFacts()`, `findFilesFacts(FileReferences)` (one call per source, asked order; `FileReferences::fromFileReferences()`), `readFileContent()`,
  `renderImagePreview()` / `renderImagePreviewOfContent()` (JPEG, PNG, GIF, WebP, HEIC by magic bytes through
  `RasterImageDecoder`; first frame, orientation applied, metadata removed, colour profile kept, never enlarged, PNG
  with alpha else JPEG; source caps 25 MB and 50 megapixels by default) and `removeImageMetadata()` (orientation
  applied first; bytes unchanged when nothing has to go).
- **Flow rule `FileFlowPolicyService::decideFileFlow(FileFacts, FileFlowTarget): FileFlowDecision`**: the one
  question every copy between stores, every read for a model and every show to an operator asks. The default: a file
  never moves to a wider audience than its own (`FileAudience`: PUBLIC < SHARED < INTERNAL < PERSONAL), a PERSONAL
  file is never copied into a store and never kept durably for a model; metadata is removed where the target audience
  is PUBLIC. An application states its own rule with a class at the same path
  (`App\Domain\Common\Services\Files\FileFlowPolicyService`).
- **Route trait `FileSourcesControllerTrait`** (`DDD\Presentation\Api\Admin\Common\Files`): `GET
  /{source}/{id}/facts` and `GET /{source}/{id}/content?variant=THUMBNAIL|PREVIEW|ORIGINAL`, answered as
  `UntrustedContentResponseDto` (attachment unless a raster image, `nosniff`, sandbox CSP; `no-store` for a PERSONAL
  file). The mounting controller overrides `throwForbiddenUnlessFileSourcesAccessAllowed()`; the flow rule (target
  OPERATOR) is asked per file. No presigned URL, no object-store address.
- Tests: reference forms, registry (rights of the source, caps, order), previews (orientation, metadata, alpha, HEIC,
  refused non-raster bodies), the default flow rule, the routes with an allowed and a forbidding controller.


## 3.47.2

- QueryOptions: the allowed-values check of a property with a `Choice` skips `eq` / `ne` against null (`null`,
  `'NULL'`, any case) — IS [NOT] NULL is no value of the property. Since 3.47.1 the allowed values are known in every
  process, so `stateReason eq null` on a nullable `Choice` property was refused with "Allowed values are: [...]".

## 3.47.1

- Reflection: the attribute-instance cache of a property keys on the lookup flags. Before, an exact-name read and an
  `IS_INSTANCEOF` read of the same attribute shared one entry, so whichever ran first in the process decided the
  other's answer (a miss was cached as null).
- QueryOptions: the filter definitions read `Choice` with `IS_INSTANCEOF`, so the allowed values of a property with the
  framework's `Choice` (a subclass of Symfony's) are known in every process. Before, they were present only when
  something had read the column definition first. **Effect:** the generated `filters` description lists "one of [...]"
  for such properties every time (surface snapshots change once), and a filter value outside the choices answers 400
  every time instead of depending on process history.

## 3.47.0

- New `DDD\Infrastructure\Exceptions\GoneException` (410: the target existed and will not come back, e.g. an expired
  ring) and `BadGatewayException` (502: an upstream service failed). An application holding its own classes for these
  statuses can move to them; nothing else to do.

## 3.46.3

- QueryOptions: converting MOMENT literals from the caller's zone (`normalizeMomentLiterals()`, set when an input time
  zone is active, e.g. MCP tools) leaves `eq` / `ne` against `'null'` in any case alone, as validation and the query
  builder already read it as IS [NOT] NULL. Before, `oneTimeAt eq 'NULL'` answered "is not a date-time". New
  `FiltersOptions::isNullComparison()`. Nothing to do for applications.

## 3.46.2

- `ddd-renames`: a path hit (a file named after an old dotted name) carries the path the file moves to in
  `replacement` (JSON) and after `->` (text) — the old name's slash form or dotted file name replaced by the new one,
  extension kept, following a chain across versions to its last name. Before, `replacement` was empty.
- `ddd-renames`: a file that already sits at the new place is no longer reported when the old path is a suffix of the
  new one (`Skills/Agent/Communication/Messages.md` for `Communication.Messages` -> `Agent.Communication.Messages`).
  Rule: the old slash form must match whole trailing segments, and when the path also ends with the new slash form,
  the longer match wins. Nothing to do in applications; re-run the dry run to get the move targets.

## 3.46.1

- `ddd-renames`: `--apply` and `--mark-applied` now also record the installed version of every package that could ship
  a `renames.json` but ships none yet — every DDD module (`extra.ddd-module` in `installed.json`) and every
  `mgamadeus/*` package, Core included. Before, a state file written while no package shipped a map stayed
  `"packages": {}`, and a map shipped later (backfilled to older versions) made every old rename pending again. The
  rule is unchanged: a rename is pending when its version is newer than the recorded one and not newer than the
  installed one; a recorded version is never lowered.
- `ddd-renames`: `--include` / `--exclude` are added to `include` / `exclude` of `.ddd-renames.json` by every mode
  that writes the state (`--apply`, `--mark-applied`; before: never, despite the help); a dry run and `--check` use them
  for that run only. New state key `vendorDir` (relative to the root): `--vendor-dir` below the root is recorded by
  the writing modes and used when the option is left out, so `--check` in CI needs no option.
- Applications that already ran `--mark-applied` or `--apply`: run `vendor/bin/ddd-renames --mark-applied` once more
  (with `--vendor-dir` when composer's vendor folder is not `<root>/vendor`) and commit `.ddd-renames.json`, so the
  baseline exists before the first map.
- Note on 3.46.0: `ParallelReadableObjectStorage` now extends `BasicObjectStorage` instead of implying the wide
  contract. A class that implemented only `ParallelReadableObjectStorage` plus the wide methods must add
  `ObjectStorage` to its `implements` list, otherwise `ObjectStorageService::getObjectStorage()` refuses it ("implements
  only BasicObjectStorage").

## 3.46.0

- Object storage drivers by configuration: `Common.ObjectStorage` takes `'drivers' => ['<driver name>' => Class::class]`
  next to `stores`. The class implements the new `ObjectStorageDriver` (the static factory
  `fromSettings(ObjectStorageSettings $objectStorageSettings, ?LoggerInterface $logger = null): static`); `s3` and
  `filesystem` are registered the same way (an application entry of the same name replaces them). A driver reads its
  own keys with `ObjectStorageSettings::getOption()` / `getBoolOption()` / `getIntOption()` (env
  `OBJECT_STORAGE_<NAME>_<OPTION>` in upper snake case, else the store's committed key). A driver name that is not
  registered, or a class that is no `ObjectStorageDriver`, now throws `InternalErrorException` naming the registered
  drivers (before: the store was silently not configured).
- A narrow contract: `BasicObjectStorage` (`putObject`, `getObjectContent`, `deleteObject`, `objectExists`), which
  `ObjectStorage` now extends; `ParallelReadableObjectStorage` extends `BasicObjectStorage`. New
  `ObjectStorageService::getBasicObjectStorage()` serves every driver; `getObjectStorage()` keeps its `?ObjectStorage`
  return type and throws for a store whose driver implements only the narrow contract. Also new:
  `getObjectStorageNotConfiguredReason()`, `getObjectStorageNames()`, `setBasicObjectStorage()`.
- s3 without keys: a store with driver `s3` but without both `OBJECT_STORAGE_<NAME>_ACCESS_KEY` and `_SECRET_KEY` is
  no longer configured (null from the service, the reason logged once per process as a warning) — before, async-aws
  walked its credential chain, instance metadata included, on every call. A store that uses the host's credentials on
  purpose declares `'credentials' => 'ambient'` (or `OBJECT_STORAGE_<NAME>_CREDENTIALS=ambient`).
- Applications: a store that ran on an IAM role, web identity or profile without keys was never a documented setup;
  such a store now answers null until it declares `credentials: ambient` — check the warning log or
  `getObjectStorageNotConfiguredReason()` for every s3 store without keys. An application that subclassed
  `ObjectStorageService` to add a driver can register the driver in `Common.ObjectStorage.drivers` and drop the
  subclass. Consumers that only put, get, delete and check objects may switch to `getBasicObjectStorage()` and type
  against `BasicObjectStorage`. Nothing else changes.

## 3.45.0

- Renames of packages as data: a package lists every rename of a skill name, constant, class, namespace, config key,
  console command, package id or environment variable in `renames.json` at its root (`{ version, kind, old, new,
  note? }`, JSON schema `tools/Renames/renames.schema.json`). New Composer bin `vendor/bin/ddd-renames` (plain PHP, no
  kernel): in an application it finds the renames of the installed packages that are newer than the version recorded
  in `.ddd-renames.json` and not newer than the installed one, lists every hit (dry run by default, `--format=json`),
  writes the certain hits with `--apply` (whole names only, a boundary rule per kind; ambiguous hits are reported,
  never written) and records the installed versions; `--check` exits 1 while renames are pending, `--mark-applied`
  records without writing, `--from-version`, `--include` / `--exclude` narrow a run. In a package,
  `ddd-renames validate` checks the map (schema, regular versions, old != new, no chain, swap or duplicate within a
  version). README › "Renames of packages (machine-readable)".
- Applications: optional. To adopt, run `vendor/bin/ddd-renames --mark-applied` once (or `--apply` to catch up on
  renames already shipped), commit `.ddd-renames.json`, run `vendor/bin/ddd-renames` after each `composer update` and
  add `vendor/bin/ddd-renames --check` to CI. Packages: ship a `renames.json` entry with every rename.

## 3.44.3

- Console: a command class of the application (or of another package) that EXTENDS a command of the framework or of a
  registered DDD module replaces it — the package's service is removed and the subclass is registered under all names
  of the package command (name and aliases) after its own, its own primary name first, each once; a subclass without
  `#[AsCommand]` takes over name, aliases and description (`FrameworkCommandsYieldPass`, by class hierarchy; the rule
  by shared name stays). Before, `#[AsCommand]` not being inherited split the two silently: a host subclass that still
  repeated a renamed command's old name registered only that, while package code started the new name.
- Package command = a class of Core's `src/` or below `DDD\Symfony\Commands\`, or a class file below a module's
  `getSourcePath()`. Commands of one package extending each other are not affected.
- Nothing to do in an application. A host subclass may drop its copied `#[AsCommand]` (repeat it only to add a name).

## 3.44.2

- Crons: a Cron whose command is no longer registered (a release removed it) can be switched off again —
  `app:crons:deactivate`, `app:crons:upsert --active=0`, and the admin endpoints deactivate / update with
  `isActive: false` (before, the CLI failed with "is not a valid cron command" and only a database update stopped
  it). The command check now judges a new Cron, an active Cron (switching on included) and a changed command
  (`Cron::isCommandRegistrationCheckRequired()`, read by `CronCommandConstraintValidator`); switching on such a Cron is
  refused with a message that names the way out (CLI: `REFUSED: …`, endpoint: 400 on `isActive`). Message of the
  constraint reworded.
- `app:crons:list` has a column `Command registered` and a warning naming the Crons whose command is not registered.
  The admin `CronOverview` already carries `commandExists` (status `MISSING_COMMAND`); the screen can offer Switch off
  for those rows. The tick keeps reporting such a Cron (one ERROR per hour, slot advanced) and never switches it off.
- Nothing to do in an application.

## 3.44.1

- Console: an application command that shares its name AND an alias with a framework command (the shape a host uses to keep an old name working) no longer breaks the kernel boot — `FrameworkCommandsYieldPass` collects the framework services to remove over all names and removes each once (before, the second shared name asked for the already removed definition: `ServiceNotFoundException`); test.

## 3.44.0

- `Cache` batches: `getMultiple(string[] $keys)` (key => value of the hits), `setMultiple(array $valuesByKey,
  int $ttlSeconds)` (one pipeline), `extendTtlOfKeys(string[] $keys, int $ttlSeconds)` (a new TTL for existing keys;
  one EXPIRE pipeline on phpredis, per key on Predis / cluster, read and write again elsewhere). Works on Redis and
  Valkey; an application's own `Cache` subclass inherits the generic versions.
- New `ParallelReadableObjectStorage::getObjectContents(string[] $keys)` (both Core drivers): several objects at once,
  null for a missing one; the s3 driver keeps every GET in flight together. A driver of an application that does not
  implement it keeps working; callers check `instanceof`.

## 3.43.0

- New `StreamingObjectStorage` (extends `ObjectStorage`; both Core drivers implement it): `readObjectChunks()` with an
  optional `ObjectStorageByteRange` (a ranged GET on the store), `putObjectFromStream()`, `uploadPartFromStream()`
  and `listMultipartUploads()` (`ObjectStorageMultipartUploads`). A driver of an application that implements only
  `ObjectStorage` keeps working; callers check `instanceof`.
- New `ObjectStorageStreamResponseDto`: streams an object through the backend (Range/206/416, ETag/304, HEAD,
  `Content-Length`, `X-Accel-Buffering: no`, the foreign-bytes headers), chunk by chunk with a flush per chunk.
- `RequestDto` leaves an `application/octet-stream` body unread (it was read as a string and decoded as JSON), so a
  controller can stream it with `$request->getContent(true)`.
- Hosts that stream through PHP: nginx `fastcgi_buffering off` or the `X-Accel-Buffering: no` header (sent),
  body size and memory limits above one upload part — `ddd-endpoint-specialist` › "Streamed downloads and uploads".
- Filesystem driver: an open multipart upload keeps its key in a file `key` of its directory (uploads started by an
  older version are listed with an empty key).

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
