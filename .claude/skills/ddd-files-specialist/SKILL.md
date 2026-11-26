---
name: ddd-files-specialist
description: "Builds and wires file sources in mgamadeus/ddd: files of any store (media library, attachments, a drive) behind one port, named by a reference <source>/<id>. Covers FileReference (names a FILE, never a record), FileReferences, the FileSource port (tag ddd.file_source, repository rights, audience per file, cap before the read, measured type/size/sha256), FileSourcesService (facts, content, previews, removeImageMetadata), FileAudience, FileFamily, the flow rule FileFlowPolicyService (FileFlowTarget, FileFlowDecision, the audience x target matrix, a host rule skeleton with consent and expiry), where each check sits (25 MB / 50 MP / edge 16-2048), FileSourcesControllerTrait, a source skeleton and checklist. Use when adding a file source, exposing stored files to the agent or the drive, a reference does not resolve, a file is not found for a caller, deciding who may copy a file where, replacing the flow rule, serving a file by reference, or rendering a picture of a stored file."
metadata:
  author: mgamadeus
  version: 1.1.0
  framework: mgamadeus/ddd >= 3.48.0
---

# DDD Files Specialist

Files of every store of an application behind ONE port. A store (a media library, the attachments of a ticket, a
drive) implements `FileSource`; every reader — the drive's import, the agent's drive tools, the admin file route —
goes through `FileSourcesService`, and every copy or show asks the flow rule `FileFlowPolicyService` first.

Code: `src/Domain/Common/Entities/Files/Sources/` (value objects), `src/Domain/Common/Services/Files/` (port, registry,
flow rule), `src/Presentation/Api/Admin/Common/Files/` (route trait and DTOs).

**What a project changes:** it adds sources (one class per prefix) and may replace the flow rule at its own path.
`FileSourcesService` stays unchanged in every project; the tools, widgets and routes that take references understand
a new prefix without any change of their own.

```
 SOURCES (one class each)        CORE                                   TARGETS (sinks)
 media/<id>   host          ─┐   FileSourcesService                ┌─►  STORE    a copy (drive import, media import)
 drive/<id>   ddd-clouddrive ├─► resolves the reference, facts,  ──┤
 <prefix>/<id> any package  ─┘   bytes, previews, under the        ├─►  MODEL    bytes or a picture for the agent
                                 caller's rights                   └─►  OPERATOR the admin file route (a browser)
                                 FileFlowPolicyService: may file X go to target Y?
                                 allow · allow with owner consent · refuse  (+ strip metadata? durable? expiry?)
```

## Critical rules

1. **A reference names a FILE, never a record.** `<source>/<id>`: `media/901` is the file 901 of the media library,
   not the product it shows. Source name `[a-z][a-z0-9-]{0,31}` (`FileReference::SOURCE_NAME_PATTERN`), id
   `[A-Za-z0-9_-]{1,128}` (`ID_PATTERN`): no slash, no dot, so a reference is never a path. It is an address in
   arguments and routes, never a stored polymorphic column, and it grants nothing. A record with several files has no
   reference of its own: not `ticket/123`, but `support/4711` with the attachment's id; a composite id
   (`support/t123-a2`) fits the pattern when a source needs one.
2. **A source adds no right and lifts none.** It reads through its entity's service, so the repository's read rights
   apply. A file the caller may not read does not exist for it: left out of the facts, "not found" on read — never
   told apart from an unknown id.
3. **Facts come from the bytes, never from the client.** Content type, size and sha256 are measured (magic bytes,
   `strlen`, `hash`) or taken from the source's own measured record; a client-declared type, name or size is never
   stored or served (`ddd-untrusted-input-specialist`).
4. **Cap before the read.** `readFileContent($id, $maxBytes)` refuses a file over the cap with `BadRequestException`
   before it loads the bytes when the size is known, and again after the read; it never cuts.
5. **No URL of a store.** A source hands out at most `FileFacts::$openLink`, set only through `setOpenLinkIfSafe()` (an
   application path such as `/admin/media/901`; scheme, host, `//` and control characters are dropped). Bytes reach a
   browser only through the backend route.
6. **Every sink asks the flow rule** before it writes or shows: after the destination's write right and the source's
   read right, before any byte is read. A copy lives under the rights of its destination; the flow rule is what keeps a file
   from reaching more people than where it lies.
7. **A list of references is `FileReferences`** (ObjectSet, keyed by `<source>/<id>`, insertion order), never
   `FileReference[]` on a public signature (AGENTS.md › Arrays Are Not a Substitute for ValueObjects / ObjectSets).

## The value objects (`DDD\Domain\Common\Entities\Files\Sources`)

| Class | What it holds |
|---|---|
| `FileReference` | `sourceName`, `id`; `tryFromString()` (null for anything else), `fromString()` (400), `toString()`, `isSameAs()` |
| `FileReferences` | the set; `fromFileReferences(FileReference[])`, `add()`, `getElements()` |
| `FileFacts` | `fileReference`, `name`, `family` (`FileFamily`), `contentType`, `sizeBytes`, `sha256`, `width`, `height`, `audience` (`FileAudience`), `isContentUntrusted` (default true), `containerLabel` (where it lies, for people), `openLink`; `isImage()` |
| `FileFactsSet` | the facts a caller may read; `findByFileReference()` |
| `FileContent` | `fileFacts` plus bytes (`fromFactsAndContent()`, `getContent()`, `getSizeBytes()`, `getContentStream()`); the bytes are never serialized |
| `FileImagePreview` | a rendered picture: `fileFacts`, `contentType` (`image/jpeg` or `image/png`), `width`, `height`, `getContent()` |
| `FileAudience` | `PUBLIC` < `SHARED` < `INTERNAL` < `PERSONAL` (`getRestrictionRank()`, `isKnown()`): who may see a file |
| `FileFamily` | `IMAGE`, `PDF`, `DOCUMENT`, `SPREADSHEET`, `PRESENTATION`, `TEXT`, `RIDE_FILE` (GPX only; FIT and TCX are `OTHER`), `VIDEO`, `OTHER`; `fromContentType($contentType, $name)` |
| `FileFlowTarget` | where a file goes: `forStore($storeName, $audience, $containerLabel, $isOwnContainer, $folderPath)`, `forModel()`, `forOperator()` |
| `FileFlowDecision` | `isAllowed`, `refusalReason`, `requiresOwnerConsent`, `stripsMetadata`, `isDurableCopyAllowed`, `expiresAfterDays`; `allow()`, `refuse($reason)` |

**Audience** is a property of each file, decided by the source: a public media library answers PUBLIC, a ticket's
attachments PERSONAL (a customer's own data), a team folder INTERNAL, a space shared with outside people SHARED.

## Implementing a source

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services\Files;

use App\Domain\Shop\Entities\Media\MediaItem;
use App\Domain\Shop\Services\MediaItemsService;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/** The product pictures of the shop as the file source `media` (`media/<mediaItemId>`) */
class MediaFileSourceService extends Service implements FileSource
{
    public const string SOURCE_NAME = 'media';

    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function findFilesFacts(array $ids): FileFactsSet
    {
        $fileFactsSet = new FileFactsSet();
        foreach ($ids as $id) {
            $mediaItem = $this->findReadableMediaItem((string)$id);   // read rights of the repository apply
            if ($mediaItem !== null) {
                $fileFactsSet->add($this->buildFileFacts($mediaItem));
            }
        }
        return $fileFactsSet;
    }

    public function readFileContent(string $id, int $maxBytes): FileContent
    {
        $mediaItem = $this->findReadableMediaItem($id)
            ?? throw new NotFoundException(sprintf('%s/%s was not found.', self::SOURCE_NAME, $id));
        if ((int)$mediaItem->fileSize > $maxBytes) {                 // cap BEFORE the read
            throw new BadRequestException(sprintf('%s/%s is larger than %d bytes.', self::SOURCE_NAME, $id, $maxBytes));
        }
        $bytes = $mediaItem->loadContent();
        $fileFacts = $this->buildFileFacts($mediaItem);
        $fileFacts->sizeBytes = strlen($bytes);                    // measured, not declared
        $fileFacts->sha256 = hash('sha256', $bytes);
        return FileContent::fromFactsAndContent($fileFacts, $bytes);
    }

    protected function findReadableMediaItem(string $id): ?MediaItem
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;                                           // ids of another form do not exist here
        }
        /** @var MediaItemsService $mediaItemsService */
        $mediaItemsService = DDDService::instance()->getService(MediaItemsService::class);
        return $mediaItemsService->find((int)$id);
    }

    protected function buildFileFacts(MediaItem $mediaItem): FileFacts
    {
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference(self::SOURCE_NAME, (string)$mediaItem->id);
        $fileFacts->name = (string)$mediaItem->fileName;
        $fileFacts->contentType = $mediaItem->measuredContentType;  // measured at upload
        $fileFacts->family = FileFamily::fromContentType($fileFacts->contentType, $fileFacts->name);
        $fileFacts->sizeBytes = $mediaItem->fileSize;
        $fileFacts->width = $mediaItem->width;
        $fileFacts->height = $mediaItem->height;
        $fileFacts->audience = FileAudience::PUBLIC;               // product pictures are shown in the shop
        $fileFacts->containerLabel = 'Media library';
        $fileFacts->setOpenLinkIfSafe('/admin/media/' . $mediaItem->id);
        return $fileFacts;
    }
}
```

The interface carries `#[AutoconfigureTag('ddd.file_source')]`: an autowired service that implements it is registered
without any `services.yaml` entry, and `FileSourcesService::setFileSources()` receives every tagged service
(`#[AutowireIterator]`). The service lives in a `…\Services\` namespace and ends in `Service`
(`ddd-service-specialist`). A test that builds the registry by hand calls `setFileSources([$fixtureFileSource])`.

### Checklist for a new source

- [ ] Source name unique in the application, lower case, short; ids match a strict pattern before any lookup.
- [ ] Every lookup goes through the entity's service or repository (rights apply); an unreadable file is left out /
      "not found", the same as an unknown id.
- [ ] `findFilesFacts()` answers in one pass for many ids (no N+1 for a list of 50), caps the count it accepts.
- [ ] Audience decided per file; PERSONAL for a person's own data (attachments, uploads of a customer).
- [ ] Content type, size, sha256 measured; `isContentUntrusted` stays true for anything uploaded or received.
- [ ] Cap checked before the read and after it; a trashed, unreleased or blocked file is refused.
- [ ] `openLink` only through `setOpenLinkIfSafe()`; no content URL, no presigned URL, no object key.
- [ ] Tests (a fixture source and a registry built with `setFileSources()`; Core's own suite uses `FixtureFileSource` in `tests/Domain/Common/Files/Fixtures/`):
      a file of another account is left out of the facts and "not found" on read, exactly like an unknown id;
      malformed ids (`../1`, `0`, `abc`) are not looked up; the audience per file; the cap refuses (no cut);
      type, size and sha256 come from the bytes; `openLink` is null for a URL; the order of `findFilesFacts()`.

## Security model

- **A reference is a name, not a grant.** Anyone (a client, a model, a log line) may type one; it only works for a
  caller whose rights reach the entity, at the moment of the read. Never store a reference as proof of access.
- **Unreadable = nonexistent.** No message, status or timing tells a caller that a file exists but is not theirs.
- **Personal files never go durable.** Core's rule refuses every copy of a PERSONAL file into a store and answers
  `isDurableCopyAllowed = false` for the model, so a picture of it stays in short-lived caches only.
- **Metadata goes only when a file becomes public.** EXIF / XMP / IPTC (place, time, device) is removed for a PUBLIC
  target; inside the team it stays, since it helps the people who work with the file. Previews never carry it.
- **Bytes are foreign.** Decoding only through `RasterImageDecoder`, serving only as `UntrustedContentResponseDto`
  (`ddd-untrusted-input-specialist`).
- **One rule decides.** Every sink asks `FileFlowPolicyService`; no sink keeps its own audience logic beside it.

## Reading files: `FileSourcesService`

`DDDService::instance()->getService(FileSourcesService::class)`:

| Method | Answer |
|---|---|
| `getSourceNames()`, `findFileSourceByName()`, `hasFileSource()` | the registered sources |
| `findFileReference(string $text): ?FileReference` | the reference a text names when its form fits AND its source is registered (`assets/logo.png` is no reference) |
| `resolveFileReference(string $text): FileReference` | the same, 400 otherwise (names the known sources) |
| `findFileFacts()` / `requireFileFacts()` | one file's facts; null / 404 when the caller may not read it |
| `findFilesFacts(FileReferences): FileFactsSet` | many, one call per source, in the asked order, unreadable ones left out |
| `readFileContent(FileReference, int $maxBytes): FileContent` | the bytes with their facts; over the cap = 400 |
| `renderImagePreview(FileReference, int $maxEdgePixels, ?$maxSourceBytes, ?$maxSourcePixels): FileImagePreview` | a picture for viewing: decoded pinned by `RasterImageDecoder` (JPEG, PNG, GIF, WebP, HEIC by magic bytes), first frame, upright, metadata removed, colour profile kept, never enlarged, PNG with alpha else JPEG; source caps 25 MB and 50 megapixels by default |
| `renderImagePreviewOfContent(FileContent, …)` | the same for bytes already read |
| `removeImageMetadata(string $bytes): string` | EXIF / XMP / IPTC removed (orientation applied first, colour profile kept); bytes unchanged when nothing has to go; a HEIC picture is always rewritten as JPEG (JPEG stays JPEG, the other formats keep theirs) — the sink takes content type and name extension from the result; for a sink whose decision says `stripsMetadata` |

A reference typed by anyone — a model included — resolves only here, under the running account's rights.

## The flow rule: `FileFlowPolicyService`

`decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision` — asked by every sink: a
copy into a store (`forStore`), bytes or a picture for the model (`forModel`), a show to an operator on the file route
(`forOperator`).

Core's default knows no application. Principle: **a copy never reaches more people than could see the file where it
lies**; a person's own file is copied nowhere. The matrix (file audience ↓, target →; a STORE target by its audience):

| File \ target | STORE PUBLIC | STORE SHARED | STORE INTERNAL | STORE PERSONAL | MODEL | OPERATOR |
|---|---|---|---|---|---|---|
| PUBLIC | allow, metadata stripped | allow | allow | allow | allow | allow |
| SHARED | refuse (wider) | allow | allow | allow | allow | allow |
| INTERNAL | refuse (wider) | refuse (wider) | allow | allow | allow | allow |
| PERSONAL | refuse | refuse | refuse | refuse | allow, not durable | allow, not durable |
| unknown audience | refuse | refuse | refuse | refuse | allow | allow |

"wider" = `FileAudience::getRestrictionRank()` of the target is lower than the file's (PUBLIC 0 < SHARED 1 < INTERNAL 2
< PERSONAL 3). `stripsMetadata` comes from `isMetadataStrippedForAudience()` (PUBLIC only). "not durable" =
`isDurableCopyAllowed = false`: a picture of the file for the model stays in short-lived caches, never in an object
store; a browser response is `private, no-store`. Default decisions never ask for consent and set no expiry.

**An application replaces the rule** with a class at the same path under its root namespace —
`App\Domain\Common\Services\Files\FileFlowPolicyService extends \DDD\Domain\Common\Services\Files\FileFlowPolicyService`
— which every sink then asks. Typical own rules: only some callers may move files at all; a consent instead of a
refusal for an internal file going to a shared space (`requiresOwnerConsent = true`; the sink stops until the owner
said yes); copies of PERSONAL data allowed into one working folder with an expiry (`expiresAfterDays`); a store that
must never receive a family (`refuse()`). Keep `refusalReason` a sentence the caller can show; it reaches people and
models.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Common\Services\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService as CoreFileFlowPolicyService;

/** The application's flow rule: replaces Core's for every sink (same path under App\). */
class FileFlowPolicyService extends CoreFileFlowPolicyService
{
    public const string CUSTOMER_FILES_FOLDER = 'customer-files';
    public const int CUSTOMER_FILE_COPY_DAYS = 30;

    public function decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision
    {
        if (!$this->isCallerAllowedToMoveFiles()) {                      // e.g. internal staff only, never partners
            return FileFlowDecision::refuse(sprintf('%s: files are not available to you here.', $fileFacts->fileReference->toString()));
        }
        // a customer's file may be copied into the caller's own drive, below customer-files/, for 30 days
        if ($fileFlowTarget->isStore() && $fileFacts->audience === FileAudience::PERSONAL) {
            if (!$fileFlowTarget->isOwnContainer || !$fileFlowTarget->isInFolder(self::CUSTOMER_FILES_FOLDER)) {
                return FileFlowDecision::refuse(sprintf(
                    '%s was not copied: a customer\'s file goes only into %s/ of your own drive.',
                    $fileFacts->fileReference->toString(),
                    self::CUSTOMER_FILES_FOLDER
                ));
            }
            $fileFlowDecision = FileFlowDecision::allow();
            $fileFlowDecision->expiresAfterDays = self::CUSTOMER_FILE_COPY_DAYS;   // the drive sets the copy's expiry
            return $fileFlowDecision;
        }
        // an internal file into a space shared with outside people: ask the owner instead of refusing
        if ($fileFlowTarget->isStore() && $fileFacts->audience === FileAudience::INTERNAL
            && $fileFlowTarget->audience === FileAudience::SHARED
        ) {
            $fileFlowDecision = FileFlowDecision::allow();
            $fileFlowDecision->requiresOwnerConsent = true;
            return $fileFlowDecision;
        }
        return parent::decideFileFlow($fileFacts, $fileFlowTarget);   // Core's matrix for everything else
    }

    protected function isCallerAllowedToMoveFiles(): bool
    {
        return true;   // the application's own check of the running account (roles, capabilities, areas)
    }
}
```

## Where each check sits

The order the sinks run, for every file of a call (the drive's import and the agent's save as the example):

| # | Check | Where | Answer when it fails |
|---|---|---|---|
| 1 | the reference names a registered source | `FileSourcesService::findFileReference()` / `resolveFileReference()` | not a reference (a path) / 400 |
| 2 | the caller may write the destination | the sink first: the drive's write right on the space (`DriveImportsService`), a read-only mount (`saveDriveFile`) | 403 for the whole call |
| 3 | (agent, consent mount) the refusals known without bytes | the store's precheck (`AgentDriveSavePrecheck`, ddd-agents) runs steps 4–5 for every file | refused lines; those files are never put to the owner |
| 4 | the caller may read the file (facts) | the source's `findFilesFacts()`, through its repository's read rights | left out / 404, same as an unknown id |
| 5 | the file may go to this target | `FileFlowPolicyService::decideFileFlow()` — BEFORE any byte is read | `refusalReason` (a refused line, or 403) |
| 6 | owner consent | the mount `WRITE_WITH_CONSENT` asks the owner (agent path, after step 3, for the remaining files); a decision's `requiresOwnerConsent` is met only by such a consent | the sink stops and asks; nothing written |
| 7 | size and pixel limits | `readFileContent($ref, $maxBytes)` before the read; previews: source ≤ 25 MB (`PREVIEW_SOURCE_MAX_BYTES`), ≤ 50 megapixels (`PREVIEW_SOURCE_MAX_PIXELS`), edge 16–2048 px (`PREVIEW_MIN_EDGE_PIXELS` / `PREVIEW_MAX_EDGE_PIXELS`); sinks may lower them | 400, nothing cut |
| 8 | the bytes are what they claim | type, size, sha256 measured from the bytes; decode only through `RasterImageDecoder` | refused content (400) |
| 9 | metadata | `decision->stripsMetadata` → `removeImageMetadata()` (may change the format: HEIC → JPEG; the sink takes type and extension from the result); previews always without | — |
| 10 | durable copy, expiry | `decision->isDurableCopyAllowed` (caches only), `decision->expiresAfterDays` (the sink sets the copy's expiry) | — |
| 11 | the destination's own rules | the sink: malware scan, quotas, conflicts | the sink's error |

A sink that skips step 5 bypasses the one rule — every new sink calls `decideFileFlow()` with the target it writes to
or shows on.

## Serving files by reference: `FileSourcesControllerTrait`

An application mounts the route once, under its own admin firewall:

```php
#[Route('/common/files')]
#[Tag(group: 'Common', name: 'Files', description: 'Files of every file source by reference')]
class AdminFilesController extends AdminController
{
    use FileSourcesControllerTrait;

    protected function throwForbiddenUnlessFileSourcesAccessAllowed(): void
    {
        // an account predicate beyond access_control, else leave empty
    }
}
```

- `GET /{source}/{id}/facts` → `FileSourceFileFactsGetResponseDto` (name, family, measured type, size, audience,
  container).
- `GET /{source}/{id}/content?variant=ORIGINAL|PREVIEW|THUMBNAIL` → `UntrustedContentResponseDto`: ORIGINAL the stored
  bytes (inline only for raster pictures, else attachment, `nosniff`, sandbox CSP, at most 50 MB:
  `getFileContentMaxBytes()`); PREVIEW (2048 px) and THUMBNAIL (320 px) rendered by the server, pictures only.
  `Cache-Control: private, max-age=300`, `private, no-store` for PERSONAL.
- Both ask the flow rule with `forOperator()`; a file the caller may not read is 404.
- Bytes reach the browser only through this route and the session: no presigned URL, no object key. Mount it only
  for callers trusted with every source's own rights (an admin firewall); client or partner audiences get their own
  routes and projections.

## Who builds on it

- `mgamadeus/ddd-clouddrive` ≥ 0.5.0: the source `drive` (`drive/<nodeId>`) and `DriveImportsService::importFiles()`
  (copies files of any source into a drive folder under the flow rule).
- `mgamadeus/ddd-agents` ≥ 0.7.0: the drive tools take references, `viewImages` shows pictures of any source to the
  model (`AgentFilePicturesService`), `AI.fileSources.findTools` names each source's find tool.

## Troubleshooting

| Symptom | Check |
|---|---|
| `"x" names no file source. Known sources: …` | the source service is not registered: implements `FileSource`, autowired, in a `Services` namespace; `getSourceName()` returns `x` |
| a file is "not found" although it exists | the caller lacks the read right on the entity (intended), or the id fails the source's own pattern |
| `… is too large to be read here` | the caller's cap (`readFileContent` / the route's `getFileContentMaxBytes()`); the source's cap check runs first |
| a copy is refused | the flow rule: audiences of file and target, PERSONAL, or the application's own rule |
| `… is no picture that can be rendered` | PREVIEW/THUMBNAIL of a non-raster file (SVG, PDF): only ORIGINAL exists for it |

## Cross-reference

Services and container fetches: `ddd-service-specialist` · foreign bytes, decoders, response headers:
`ddd-untrusted-input-specialist` · route DTOs and controllers: `ddd-endpoint-specialist`.
