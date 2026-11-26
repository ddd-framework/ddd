---
name: ddd-files-specialist
description: "Builds and wires file sources in mgamadeus/ddd: files of any store (media library, attachments, a drive) behind one port, named by a reference <source>/<id>. Covers FileReference (names a FILE, never a record), FileReferences, implementing the FileSource port (tag ddd.file_source, rights through the repository, unreadable ids left out, audience per file, cap before the read, type/size/sha256 measured, setOpenLinkIfSafe), FileSourcesService (resolve, facts, content, previews, removeImageMetadata), FileAudience, FileFamily, the flow rule FileFlowPolicyService with FileFlowTarget and FileFlowDecision and its same-path replacement, FileSourcesControllerTrait (facts and content routes), a source skeleton and checklist. Use when adding a file source, exposing stored files to the agent or the drive, a reference does not resolve, a file is not found for a caller, deciding who may copy a file where, replacing the flow rule, serving a file by reference, or rendering a picture of a stored file."
metadata:
  author: mgamadeus
  version: 1.0.0
  framework: mgamadeus/ddd >= 3.48.0
---

# DDD Files Specialist

Files of every store of an application behind ONE port. A store (a media library, the attachments of a ticket, a
drive) implements `FileSource`; every reader — the drive's import, the agent's drive tools, the admin file route —
goes through `FileSourcesService`, and every copy or show asks the flow rule `FileFlowPolicyService` first.

Code: `src/Domain/Common/Entities/Files/Sources/` (value objects), `src/Domain/Common/Services/Files/` (port, registry,
flow rule), `src/Presentation/Api/Admin/Common/Files/` (route trait and DTOs).

## Critical rules

1. **A reference names a FILE, never a record.** `<source>/<id>`: `media/901` is the file 901 of the media library,
   not the product it shows. Source name `[a-z][a-z0-9-]{0,31}` (`FileReference::SOURCE_NAME_PATTERN`), id
   `[A-Za-z0-9_-]{1,128}` (`ID_PATTERN`): no slash, no dot, so a reference is never a path. It is an address in
   arguments and routes, never a stored polymorphic column, and it grants nothing.
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
6. **Every sink asks the flow rule** before it writes or shows: after the source's read right, before the
   destination's write right. A copy lives under the rights of its destination; the flow rule is what keeps a file
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
| `FileFamily` | `IMAGE`, `PDF`, `DOCUMENT`, `SPREADSHEET`, `PRESENTATION`, `TEXT`, `RIDE_FILE` (GPX, FIT, TCX), `VIDEO`, `OTHER`; `fromContentType($contentType, $name)` |
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
without any `services.yaml` entry. The service lives in a `…\Services\` namespace and ends in `Service`
(`ddd-service-specialist`).

### Checklist for a new source

- [ ] Source name unique in the application, lower case, short; ids match a strict pattern before any lookup.
- [ ] Every lookup goes through the entity's service or repository (rights apply); an unreadable file is left out /
      "not found", the same as an unknown id.
- [ ] `findFilesFacts()` answers in one pass for many ids (no N+1 for a list of 50), caps the count it accepts.
- [ ] Audience decided per file; PERSONAL for a person's own data (attachments, uploads of a customer).
- [ ] Content type, size, sha256 measured; `isContentUntrusted` stays true for anything uploaded or received.
- [ ] Cap checked before the read and after it; a trashed, unreleased or blocked file is refused.
- [ ] `openLink` only through `setOpenLinkIfSafe()`; no content URL, no presigned URL, no object key.
- [ ] Tests: rights (other account → left out), audience, cap, unknown and malformed ids, measured facts.

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
| `removeImageMetadata(string $bytes): string` | EXIF / XMP / IPTC removed (orientation applied first, colour profile kept); bytes unchanged when nothing has to go; for a sink whose decision says `stripsMetadata` |

A reference typed by anyone — a model included — resolves only here, under the running account's rights.

## The flow rule: `FileFlowPolicyService`

`decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision` — asked by every sink: a
copy into a store (`forStore`), bytes or a picture for the model (`forModel`), a show to an operator on the file route
(`forOperator`).

Core's default knows no application:

| Target | Decision |
|---|---|
| MODEL, OPERATOR | allowed; `isDurableCopyAllowed` false for a PERSONAL file (a picture for the model stops at the cache) |
| STORE, audience of file or target unknown | refused |
| STORE, PERSONAL file | refused: a person's own file stays where it is (it can still be read in place) |
| STORE, target audience wider than the file's | refused: "more people see … than see the file where it lies" |
| STORE, otherwise | allowed; `stripsMetadata` when the target audience is PUBLIC (`isMetadataStrippedForAudience()`) |

**An application replaces the rule** with a class at the same path under its root namespace —
`App\Domain\Common\Services\Files\FileFlowPolicyService extends \DDD\Domain\Common\Services\Files\FileFlowPolicyService`
— which every sink then asks. Typical own rules: a consent instead of a refusal for an internal file going to a
shared space (`requiresOwnerConsent = true`; the sink stops until the owner said yes), an expiry for copies of
PERSONAL data in a working folder (`expiresAfterDays`), a store that must never receive a family (`refuse()`). Keep
`refusalReason` a sentence the caller can show; it reaches people and models.

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
