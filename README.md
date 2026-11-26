# mgamadeus/ddd

A Domain-Driven Design entity framework built on top of Symfony 7.3 and Doctrine ORM for PHP 8.3+.

## Overview

`mgamadeus/ddd` provides a complete DDD stack for building PHP applications:

- **Entities & Value Objects** with identity, validation, serialization, parent-child relationships, and single table inheritance
- **Lazy Loading** via `#[LazyLoad]` attributes -- relationships load on-demand from DB, Virtual, or Class Method repositories
- **Repository Pattern** with DB (Doctrine), Virtual, and Class Method repository types, plus auto-generated ORM model classes
- **Service Layer** with `EntitiesService` for entity management and `Service` for cross-cutting concerns
- **OData-Style QueryOptions** -- `$filter`, `$select`, `$expand`, `$orderBy`, `$top`, `$skip` with 11 filter operators (eq, ne, gt, ge, lt, le, in, ni, bw, ft, fb)
- **Rights Protection** -- query-level access control via `applyReadRightsQuery()`, `applyUpdateRightsQuery()`, `applyDeleteRightsQuery()`
- **Multi-Language Support** via `#[Translatable]` with JSON storage, fulltext search indexes, and language/country/writing-style context
- **Change History** -- automatic `created`/`updated` timestamps via `ChangeHistoryTrait`
- **REST Presentation Layer** -- `HttpController`, typed DTOs (Request/Response), OpenAPI 3.0 documentation, request caching, specialized response types (Excel, PDF, ZIP, Image, HTML, Redirect)
- **Admin Endpoint Traits** -- the backend of standard admin screens, mounted by an application with one controller each: `WorkersControllerTrait` (Messenger workers), `IssuesControllerTrait` (error groups of the issues registry), `DatabaseModelsControllerTrait` (schema diff and its applies), `CronsControllerTrait` (Crons and their executions)
- **Async Processing** -- Symfony Messenger integration with `AppMessage`/`AppMessageHandler` base classes, auth context propagation, and workspace routing
- **Module System** -- Composer packages self-register as DDD modules with automatic service discovery and entity inclusion
- **CLI Commands** -- Console command infrastructure with admin auth context, batch processing, progress tracking, and cron job management
- **Infrastructure Utilities** -- Config management, caching (APC/Redis/PhpFiles), encryption, JWT, text processing, input filtering, internationalized domain names

## Requirements

- PHP >= 8.3
- Symfony 7.3
- Doctrine ORM ^2.13
- Extensions: `ctype`, `dom`, `gd`, `iconv`, `libxml`, `openssl`, `zlib`, `imagick`

## Installation

```bash
composer require mgamadeus/ddd
```

Register the bundle in your Symfony application:

```php
// config/bundles.php
return [
    // ...
    DDD\DDDBundle::class => ['all' => true],
];
```

On **PostgreSQL**, a new database needs the framework's extensions, the `ddd_ci_ai` collation and its `ddd_*` SQL
functions before any table: create the role and the database, then run
`php bin/console app:db:postgres:install-extensions-collation-functions` (idempotent — safe on every deploy), then
create the tables through the schema diff. Details: the `ddd-database-schema-diff-specialist` skill, section "Setting
up a new PostgreSQL database".

**Agent skills of the installed packages** (Core, the modules, the npm packages) are linked into the app's
`.claude/skills` by two install hooks — wire both, nothing else is needed per package:

```json
// composer.json (the app's Composer root), beside the existing scripts
"scripts": {
    "post-install-cmd": ["@php vendor/bin/ddd-link-skills"],
    "post-update-cmd": ["@php vendor/bin/ddd-link-skills"]
}
```

```json
// package.json of the web frontend (when it installs @mgamadeus/* packages)
"scripts": { "postinstall": "ddd-web link-skills" }
```

Details and the `--check` for CI: [Agent Skills (vendor skill links)](#agent-skills-vendor-skill-links).

## Quick Start

### 1. Entity

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Entities\Products;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Validation\Constraints\Length;
use App\Domain\Common\Repo\DB\Products\DBProduct;

/**
 * @method static ProductsService getService()
 * @method static DBProduct getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBProduct::class)]
class Product extends Entity
{
    use ChangeHistoryTrait;

    public ?int $id = null;

    #[Length(max: 255)]
    public string $name;

    public ?string $description = null;

    public ?int $categoryId = null;

    #[LazyLoad]
    public ?Category $category;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
```

### 2. EntitySet (Collection)

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Entities\Products;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use App\Domain\Common\Repo\DB\Products\DBProducts;
use App\Domain\Common\Services\ProductsService;

/**
 * @property Product[] $elements
 * @method Product first()
 * @method static ProductsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBProducts::class)]
class Products extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = ProductsService::class;
}
```

### 3. Repositories

```php
// DB Repo -- Single Entity
class DBProduct extends DBEntity
{
    public const BASE_ENTITY_CLASS = Product::class;
    public const BASE_ORM_MODEL = DBProductModel::class;  // Auto-generated, never edit
}

// DB Repo -- Entity Set
class DBProducts extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBProduct::class;
    public const BASE_ENTITY_SET_CLASS = Products::class;
}
```

### 4. Service

```php
<?php
declare(strict_types=1);
namespace App\Domain\Common\Services;

use App\Domain\Common\Entities\Products\Product;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * @method Product find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method Products findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 */
class ProductsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = Product::class;
}
```

### 5. Use It

```php
// CRUD
$product = Product::byId(42);
echo $product->category->name;  // Lazy-loaded automatically
$product->name = 'Updated';
$product->update();              // Validates and persists
$product->delete();

// Service access
$service = Products::getService();
$allProducts = $service->findAll();

// Programmatic QueryOptions
$originalQO = clone Products::getDefaultQueryOptions();
$filters = FiltersOptions::fromString("status eq 'ACTIVE'");
Products::getDefaultQueryOptions()->setFilters($filters)->setTop(100);
$activeProducts = $service->findAll();
Products::setDefaultQueryOptions($originalQO);  // Always restore
```

## Key Features

### Lazy Loading

Relationships load automatically on property access -- no manual finder methods needed:

```php
#[LazyLoad]                                                    // DB (default)
public ?Category $category;

#[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'computeTotal')]
public ?Money $total;                                          // Custom method

#[LazyLoad(repoType: LazyLoadRepo::VIRTUAL, loadMethod: 'lazyloadRankings')]
public Rankings $rankings;                                     // Virtual/computed

#[LazyLoad(addAsParent: true)]
public ?ParentEntity $parent;                                  // Parent-child

#[LazyLoad(loadThrough: IntermediaryEntities::class)]
public ?RelatedEntities $related;                              // N-N via junction
```

### QueryOptions (OData-Style Filtering)

Applied at the database level with 11 filter operators:

```
GET /api/products?$filter=status eq 'ACTIVE' and price gt '10'&$select=id,name&$expand=category(select=id,name)&$orderBy=name asc&$top=20
```

| Operator | Meaning | Example |
|----------|---------|---------|
| `eq` / `ne` | Equals / Not equals | `status eq 'ACTIVE'` |
| `gt` / `ge` / `lt` / `le` | Comparison | `price gt '100'` |
| `in` / `ni` | In / Not in list | `status in ['ACTIVE','PENDING']` |
| `bw` | Between | `date bw ['2026-01-01','2026-12-31']` |
| `ft` | Fulltext (natural language) | `name ft 'search terms'` |
| `fb` | Fulltext (boolean, prefix matching) | `name fb 'alm*'` |

Expand supports nested clauses: `$expand=zones(filters=isActive eq 'true';orderBy=name asc;top=50;expand=tables)`

### Rights Protection

Query-level access control via overridable methods in DB repositories:

```php
class DBProduct extends DBEntity
{
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) return false;
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $alias = static::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.id is null");
            return true;
        }
        if ($authAccount?->roles?->isAdmin()) return true;
        // Non-admin restrictions...
        return parent::applyReadRightsQuery($queryBuilder);
    }
}
```

### Multi-Language (Translatable)

```php
class Product extends Entity
{
    use TranslatableTrait;

    #[Translatable]
    public string $name;

    #[Translatable(fullTextIndex: true)]  // Enables ft/fb search operators
    public ?string $description = null;
}
```

JSON storage with language/country/writing-style context. Fulltext indexes auto-generate virtual search columns.

### REST Controllers & DTOs

```php
#[Route('/api/products')]
#[Tag(group: 'Catalog', name: 'Products')]
class ProductsController extends HttpController
{
    #[Get('/list')]
    #[Summary('Products List')]
    public function list(
        ProductsGetRequestDto &$requestDto,
        ProductsService $productsService
    ): ProductsGetResponseDto {
        Products::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $productsService->throwErrors = true;

        $responseDto = new ProductsGetResponseDto();
        $responseDto->products = $productsService->findAll();
        $responseDto->products->expand();
        return $responseDto;
    }
}
```

Response types beyond JSON: `ExcelResponseDto`, `PDFResponseDto`, `ImageResponseDto`, `ZipResponseDto`, `FileResponseDto`, `HtmlResponseDto`, `RedirectResponseDto`.

### Async Processing (Symfony Messenger)

```php
// Message
class ProcessItemMessage extends AppMessage
{
    public static string $messageHandler = ProcessItemHandler::class;
    public ?int $itemId = null;

    public function __construct(?int $itemId = null) {
        parent::__construct();
        $this->itemId = $itemId;
    }
}

// Handler
#[AsMessageHandler(fromTransport: 'process_item')]
class ProcessItemHandler extends AppMessageHandler
{
    public function __invoke(ProcessItemMessage $message): void {
        $this->setAuthAccountFromMessage($message);
        if ($message->processOnWorkspaceIfNecessary()) return;
        // Process...
    }
}

// Dispatch from service
public function processItem(int $itemId, bool $async = false): void {
    if ($async) { (new ProcessItemMessage($itemId))->dispatch(); return; }
    // Synchronous work...
}
```

### Module System

Composer packages self-register as DDD modules:

```json
{ "extra": { "ddd-module": "Vendor\\MyModule\\MyDDDModule" } }
```

```php
class MyDDDModule extends DDDModule
{
    public static function getSourcePath(): string { return __DIR__ . '/../src'; }
    public static function getConfigPath(): ?string { return __DIR__ . '/../config/app'; }
}
```

Modules are discovered automatically. Services auto-registered. Entities included in model generation. Config directories integrated with app > module > framework priority.

**Naming a module.** A module owns one feature word and shows it in every outward layer. Fill in this table in the
module's README:

| Layer | Form | Example (`ddd-agent-sandbox`) |
|---|---|---|
| Composer, npm, repository | `mgamadeus/ddd-<name>`, `@mgamadeus/ddd-<name>`, `ddd-framework/ddd-<name>` | `ddd-agent-sandbox` |
| Module class | `DDD\Modules\<Name>\<Name>Module` | `DDD\Modules\AgentSandbox\AgentSandboxModule` |
| PHP | own domain `DDD\Domain\<Word>`; shared domain `DDD\Domain\<Domain>\<Layer>\<Feature>`, declared in `getOwnedNamespaces()` | `DDD\Domain\AI\Services\Sandboxes` |
| Config | `config/app/<Word>/` or `config/app/<Domain>/<Word>/`, key = path with dots | `AI.Sandbox.*` |
| Console, environment, image | `app:<package word>:`, `<PACKAGE_WORD>_*`, `ddd-<name>-<role>:<version>` | `app:agent-sandbox:`, `AGENT_SANDBOX_*`, `ddd-agent-sandbox-runner` |
| Agent skill, package id | `<Topic>.<Feature>`, `<provider>.<topic>.<feature>` bound by class | `Agent.Sandbox.Shell`, `agent.sandbox.shell` |

A module that is an organ of the agent (useless without one, but not depending on `ddd-agents`) is named
`ddd-agent-<word>`. Releases are regular versions, never pre-releases.

### CLI Commands

```php
#[AsCommand(name: 'app:recalculate', description: 'Recalculates data')]
class RecalculateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        $service = MyEntities::getService();
        $service->recalculate(new SymfonyStyle($input, $output));
        return Command::SUCCESS;
    }
}
```

Framework-provided commands: `app:generate-doctrine-models-for-entities`, `app:process-cli-message`, `app:crons:execute`, `app:crons:list`, `app:crons:upsert`, `app:crons:activate`, `app:crons:deactivate`, `app:crons:executions`.

### Agent Skills (vendor skill links)

The framework and its modules ship agent skills (`.claude/skills/<name>/SKILL.md`). The Composer bin
`ddd-link-skills` links them into the app's `.claude/skills` from the Composer hooks — plain PHP, it boots no kernel
and loads no application code:

```bash
vendor/bin/ddd-link-skills [--check] [--dry-run] [--quiet] [--root <dir>]
```

#### Vendor skill links — behaviour (shared by `ddd-link-skills` and `ddd-web link-skills`)

DDD packages ship agent skills at `<package>/.claude/skills/<name>/SKILL.md`. `ddd-link-skills` (Composer package
`mgamadeus/ddd`) and `ddd-web link-skills` (npm package `@mgamadeus/ddd-web`) generate and maintain the links to them.
Both behave identically, print the same lines and read and write the same manifest, so a Composer hook and an npm
postinstall never fight; either one alone links everything.

1. **Root**: the nearest ancestor of the working directory (itself included) that contains `.git` (folder or file),
   else the nearest that contains `.claude` (never the home folder), else the working directory. `--root <dir>` or the
   env variable `DDD_SKILLS_ROOT` override it. The install may run in a subfolder (Composer in `app/`, npm in `web/`).
2. **Discovery**: every `vendor/mgamadeus/*` and `node_modules/@mgamadeus/*` whose `vendor/` / `node_modules/` folder
   lies at most three levels below the root (`vendor/`, `app/vendor/`, `apps/admin/node_modules/`); other `vendor/`,
   `node_modules/` and dot folders are not searched, symlinked folders are not followed. A skill is a folder with a
   `SKILL.md`. Order: skill path, byte order. The same name from two packages is a clash: the first is kept and the
   clash reported. The same package installed twice (two Composer roots) is not a clash.
3. **Primary folder** `<root>/.claude/skills`, created when missing. When `.claude` or `.claude/skills` is a symlink
   (for example to `.codex`), the links go into its real location, once.
4. **Mirrors** `<root>/.agents/skills` and `<root>/.codex/skills` (Codex): the layout is detected, never changed. A
   missing mirror is not created; a mirror (or its parent) that resolves to the primary folder gets nothing; a mirror
   that is a real folder gets one link per skill, `../../.claude/skills/<name>`, pointing to the primary entry.
5. **Links**: relative targets from the link's real folder to the skill folder, for example
   `../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist`. Idempotent; a link into a DDD vendor area
   with another target is repointed. A real file or folder, or a symlink that points elsewhere, is never touched: it
   is reported as a clash with the app's own skill.
6. **Cleanup**: broken symlinks in the primary folder and the mirrors whose target lies in a `vendor/mgamadeus/` or
   `node_modules/@mgamadeus/` path are deleted (skill no longer shipped, package removed), and so are mirror links to
   primary entries that were removed. Only symlinks; app skills and real folders stay.
7. **Manifest** `<primary>/.ddd-skills.json` (format below), rewritten only when its content changes. An entry whose
   link still resolves but whose package was not found by this run (another install tree) is kept.
8. **Git**: a managed block in `<primary>/.gitignore` and in each real mirror folder's `.gitignore` lists the generated
   link names (and, in the primary, the manifest); everything outside the block stays. Nothing is removed from git:
   generated links that git still tracks are reported with the exact `git rm --cached -- …` command.
9. **`--check`**: changes nothing and exits 1, with a list, on missing links, stale targets, broken DDD links, an
   outdated manifest or gitignore block, clashes, generated paths still tracked, and edited vendor skills (same package
   version as in the manifest, different SKILL.md hash; a normal run warns and keeps the recorded hash, so the check
   keeps failing until the package is reinstalled). `--dry-run` prints what would change; `--quiet` prints only
   warnings and problems.
10. **Output and exit code**: one line per change, prefixed `[ddd-skills]`. A normal run always exits 0 (clashes and
    tracked links are warnings), except on an unexpected error writing the folder (exit 1).

Manifest:

```json
{
    "format": 1,
    "skills": {
        "ddd-entity-specialist": {
            "package": "mgamadeus/ddd",
            "version": "3.36.0",
            "source": "app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist",
            "target": "../../app/vendor/mgamadeus/ddd/.claude/skills/ddd-entity-specialist",
            "sha256": "<sha256 of SKILL.md>",
            "mirrors": [".agents/skills"]
        }
    }
}
```

`version` comes from `vendor/composer/installed.json` (else the package's `composer.json`) or the package's
`package.json`; `source` is relative to the root. Both tools write it byte-identically (4-space JSON, names sorted,
trailing newline).

Gitignore block:

```gitignore
# ddd-skills:start
# Generated by ddd-link-skills / ddd-web link-skills; rewritten on every install.
/.ddd-skills.json
/ddd-entity-specialist
# ddd-skills:end
```

#### App-side setup

For an app whose Composer root is `app/` and whose frontend is `web/`:

1. `app/composer.json`, alongside the existing scripts:
   ```json
   "scripts": {
       "post-install-cmd": ["@php vendor/bin/ddd-link-skills", "..."],
       "post-update-cmd": ["@php vendor/bin/ddd-link-skills", "..."]
   }
   ```
2. `web/package.json`: `"postinstall": "ddd-web link-skills"` (an app that installs with `ignore-scripts` relies on
   the Composer hook, which links the npm skills too).
3. Run the install once, then untrack the formerly hand-made links the tool reports
   (`git rm --cached -- .claude/skills/ddd-entity-specialist …`) and commit together with the new `.gitignore` blocks.
4. CI or session start: `php app/vendor/bin/ddd-link-skills --check` (or `pnpm --dir web exec ddd-web link-skills
   --check`).

### Server messages of packages (text export)

A web frontend translates a server message (the `error` of a 4xx answer) by its English text, and an application's
text export finds those texts in its own PHP. A message thrown by an installed package is invisible to that scan, and
an export that prunes keys it did not find deletes a text an endpoint still sends. Each PHP package therefore lists
its client-facing messages in `i18n/server-messages.json`, built by the Composer bin `ddd-server-messages` (plain PHP,
no kernel):

```bash
vendor/bin/ddd-server-messages build            # in a package: keys from src/, hints kept (written by hand)
vendor/bin/ddd-server-messages build --check    # CI: exit 1 when outdated or a hint has < 40 characters
vendor/bin/ddd-server-messages collect --vendor-dir vendor --out var/server-messages.json   # in an application
```

- **What is listed:** `new <BadRequest|NotFound|Forbidden|Conflict|Unauthorized|…>Exception('literal'`,
  `…Exception::fromTemplate('template'`, message constants (`…MESSAGE…`, `ERROR_…` but not `ERROR_CODE…` or push
  texts) and `return 'literal';` in a `…Reason()` method; only sentences (no codes, no single words).
  `InternalErrorException` and PHP's own exceptions stay English.
- **Where:** `src/Domain`, `src/Presentation/Api`, `src/Presentation/Base`, `src/Symfony/EventListeners`,
  `src/Symfony/Security`; never MCP tools, message handlers, Argus repos or evaluation code (their texts reach a model
  or a developer). A manifest narrows it with `excludedFiles` (path patterns), widens or replaces it with
  `scanDirectories`; a file containing `@server-messages-ignore-file` is skipped.
- **Format:** `{ package, keys: [{ key, text, hint, origins }] }`, key = the English text, the same entry form as the
  npm packages' `i18n-keys.json`. `collect` merges every `vendor/mgamadeus/*/i18n/server-messages.json` into
  `{ packages, keys: [{ key, text, hint, origins, packages }] }` (a key listed twice keeps the longest hint).
- **Application export:** add the collected keys like the UI texts of the npm manifests, and never prune a key a
  package lists.

### Renames of packages (machine-readable)

A release that renames a skill, a constant, a class or namespace, a configuration key, a console command, a package id
or an environment variable ships the rename as data, so an application does not rewrite the names by hand from
changelog prose. A package lists every rename in `renames.json` at its root (JSON schema:
`tools/Renames/renames.schema.json`); the Composer bin `ddd-renames` (plain PHP, no kernel) applies them.

```json
{
    "format": 1,
    "package": "acme/agents",
    "renames": [
        { "version": "2.0.0", "kind": "skillName", "old": "Agent.Web", "new": "Web.Research", "note": "optional" },
        { "version": "2.0.0", "kind": "class", "old": "Acme\\Agents\\Tools\\WebTool", "new": "Acme\\Agents\\Tools\\ResearchTool" },
        { "version": "2.0.0", "kind": "constant", "old": "Acme\\Agents\\AgentSkills::WEB", "new": "Acme\\Agents\\AgentSkills::WEB_RESEARCH" }
    ]
}
```

Kinds: `skillName`, `constant` (`Class::NAME`, the class stays; a moved class is its own `class` entry), `class`,
`namespace`, `configKey`, `consoleCommand`, `packageId`, `envVar`. `version` is the regular release that made the
rename.

```bash
vendor/bin/ddd-renames validate                 # in a package: schema, versions, old != new, no chain/swap/duplicate within a version
vendor/bin/ddd-renames                          # in an application: dry run, lists file:line:column of every hit
vendor/bin/ddd-renames --apply                  # writes the certain hits, records the installed versions in .ddd-renames.json
vendor/bin/ddd-renames --check                  # CI: exit 1 while renames are pending
vendor/bin/ddd-renames --format=json            # the same report for tools
```

- **Which renames are pending:** for every installed package with a `renames.json` (`vendor/composer/installed.json`),
  the entries newer than the version recorded in the application's `.ddd-renames.json` and not newer than the installed
  version (a branch install counts every entry). Without a record every entry is pending (old names that no longer
  occur cost nothing); `--from-version <package>=<version>` overrides the record, `--mark-applied` records the
  installed versions without touching files (renames already done by hand). Commit `.ddd-renames.json`.
- **Baseline before the first map:** `--apply` and `--mark-applied` also record the installed version of every package
  that could ship a map but ships none yet — every DDD module (`extra.ddd-module`) and every `mgamadeus/*` package,
  Core included; other packages are not recorded. A map a package ships later, backfilled to older versions, then
  makes only the renames after that baseline pending. A recorded version is never lowered.
- **Root and vendor folder may differ:** `--root` is the folder that is scanned and holds `.ddd-renames.json`;
  `--vendor-dir` is composer's vendor folder (default `<root>/vendor`). A repository root with docs, skills and a
  frontend beside an `app/` folder holding composer's vendor runs `vendor/bin/ddd-renames --root . --vendor-dir
  app/vendor` from the repository root (or `php app/vendor/bin/ddd-renames …`). The writing modes record a vendor
  folder below the root as `"vendorDir": "app/vendor"` in the state file, used when the option is left out, so later
  runs and `--check` need no option. The root itself is not stored (the state file lives in it).
- **Order:** versions are applied in ascending order, each version in one pass, so a chain across versions
  (`A -> B` in 2.0.0, `B -> C` in 2.1.0) ends at `C`, also in a dry run. Within one version a chain, a swap or a name
  renamed twice is an error of `validate`.
- **What is scanned:** every text file below the root (`--root`, default the current folder): `src/`, `config/`,
  `tests/`, templates, docs, eval fixtures, `.env*`, frontend sources; never `vendor/`, `node_modules/`, `.git/`,
  `var/`, symbolic links, lock files. `--include` / `--exclude` (folder prefix or glob, repeatable) or the keys
  `include` / `exclude` of `.ddd-renames.json` narrow it. `--apply` and `--mark-applied` add the options to those
  keys; a dry run and `--check` use them for that run only.
- **Whole names only.** `Agent.Web` never touches `Agent.Web.Search` (unless listed itself), `x.Agent.Web` or
  `Agent.Web-x`; `app:x:web` never `app:x:web:all`; `OLD_VAR` never `OLD_VAR_X`. A class matches as `A\B\C`, `\A\B\C`
  and escaped `A\\B\\C`, never `X\A\B\C` or `A\B\CD`, and by its short name in a PHP file that imports it; a
  namespace and a configuration key move their children along (`A\B\Anything`, `AI.web.enabled`); a constant matches
  as `A\B\C::OLD`, as `C::OLD` or an alias where the file imports `C`, and as `self/static/parent::OLD` in a subclass
  of `C`. Of overlapping hits the longest wins (a renamed class over its renamed namespace).
- **Ambiguous hits are reported, never written:** a single plain word outside quotes, `C::OLD` without an import of `C`
  or outside PHP code, an inherited constant whose parent is another class, a short class name in its old namespace
  when the class moved, a new short name already imported. Files named after an old dotted name
  (`skills/Agent/Web.md`, `config/app/AI/webSearch.php`) are listed to be moved by hand, each with the path it moves
  to (`replacement`, the last name of a chain). The old name in slash form must match whole trailing path segments;
  when the path also ends with the new name in slash form, the longer match wins, so a file already at the new place
  (`Skills/Agent/Communication/Messages.md` for `Communication.Messages` -> `Agent.Communication.Messages`) is no hit,
  while the old place of a shortening rename still is. Not covered: grouped imports
  (`use A\{B, C}`), configuration keys written as nested array keys, moving files.
- **Application wiring:** run `vendor/bin/ddd-renames` after `composer update` (it lists what the update renamed),
  `--apply`, review the diff, commit it with `.ddd-renames.json`; add `vendor/bin/ddd-renames --check` to CI.

### Untrusted Input

Bytes and strings the server did not produce are never executed or interpreted as code. Core ships the helpers
(`DDD\Infrastructure\Untrusted`): `RasterImageDecoder` (fixed-format Imagick decode of JPEG/PNG/GIF/WebP/HEIC only),
`GuardedHttpFetchService` (public-address guard per hop, pinned connect, byte and time caps), `SafeXml`,
`ContainedPath`, `IsolatedProcess`, `ArchiveInspector`, `UntrustedContentResponseDto` (attachment, `nosniff`,
`CSP: sandbox`), `ExcelDocument::writeCellValue()` and `Datafilter::sanitizeHtml()`.

What an application does:

1. Add the sink scan to its suite and list its own justified raw calls in `config/untrusted-sinks-allowlist.php`:
   ```php
   class UntrustedSinkScanTest extends \DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase
   {
       protected function getPackageRootDirectory(): string { return dirname(__DIR__); }
   }
   ```
2. Reuse the behaviour tests: `use \DDD\Testing\UntrustedInput\UntrustedInputBehaviourTestTrait;` in a test class.
3. Ship an ImageMagick allow-list `policy.xml`, remove Ghostscript, and run `php bin/console
   app:untrusted-input:check-runtime` in the image build (exit 1 when ImageMagick reads PS/PDF/SVG/MVG or `gs` is on
   the PATH). PDF pages and text need poppler (`pdftoppm`, `pdftotext`), OCR needs `tesseract`.

Details: skill `ddd-untrusted-input-specialist`; changes per version: `CHANGELOG.md`.

### Entity Attributes

| Attribute | Purpose |
|-----------|---------|
| `#[LazyLoadRepo]` | Bind entity to repository class |
| `#[LazyLoad]` | Deferred relationship loading |
| `#[ChangeHistory]` | Automatic created/updated timestamps |
| `#[Translatable]` | Multi-language properties with fulltext search |
| `#[QueryOptions]` | Custom OData-style query support |
| `#[DatabaseColumn]` | Column mapping and SQL type control |
| `#[DatabaseVirtualColumn]` | Computed/extracted database columns |
| `#[DatabaseIndex]` | Index definitions (unique, composite) |
| `#[DatabaseForeignKey]` | Foreign key relationships |
| `#[DatabaseTrigger]` | SQL trigger integration |
| `#[SubclassIndicator]` | Single Table Inheritance (discriminator) |
| `#[HideProperty]` | Exclude from API serialization |
| `#[HidePropertyOnSystemSerialization]` | Exclude from DB persistence |
| `#[DontPersistProperty]` | Exclude from persistence (visible in API) |
| `#[OverwritePropertyName]` | Rename property in serialized output |
| `#[Aliases]` | Backward-compatible property aliases |
| `#[NoRecursiveUpdate]` | Prevent cascade updates from parent |
| `#[RolesRequiredForUpdate]` | Role-based write authorization |
| `#[RequestCache]` | GET endpoint response caching |
| `#[Choice]` | Enum-like validation with dynamic choices |
| `#[Length]` | String length validation |
| `#[UniqueProperty]` | Database uniqueness validation |

### Infrastructure Utilities

```php
Config::get('database.host');                          // Hierarchical config (dot-notation)
Config::getEnv('DATABASE_URL');                        // Env with type coercion
Cache::instance();                                     // Auto-selects APC/Redis/PhpFiles
Encrypt::encrypt($data, $password);                    // AES-256-CBC
JWTPayload::createJWTFromParameters($params, 3600);   // JWT tokens
StringFuncs::generateAlias('Hello World!');             // URL slugs
Datafilter::validEmail($email);                        // Input validation
__('key', 'de', 'DE', 'FORMAL', ['%name%' => 'Max']); // Translation
```

### Object Storage

Named stores behind one service; a consumer asks for its store by name and the provider is configuration.

```php
$objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
$pictureStorage = $objectStorageService->getBasicObjectStorage('pictures'); // put, get, delete, exists; null = not configured
$exportStorage = $objectStorageService->getObjectStorage('exports');        // the full contract: list, presign, multipart, ranges
```

```php
// config/app/Common/ObjectStorage.php
return [
    'drivers' => ['pictures-gateway' => \App\Infrastructure\ObjectStorage\PicturesGatewayObjectStorage::class],
    'stores' => [
        'exports' => ['driver' => 's3', 'endpoint' => 'https://fsn1.your-objectstorage.com', 'region' => 'fsn1', 'bucket' => 'exports'],
        'backups' => ['driver' => 's3', 'region' => 'eu-central-1', 'bucket' => 'backups', 'credentials' => 'ambient'],
        'pictures' => ['driver' => 'pictures-gateway', 'gatewayUrl' => 'https://pictures.example.com'],
    ],
];
```

- **Drivers:** `s3` (AWS S3, Hetzner, MinIO, R2, SeaweedFS; needs `async-aws/s3`) and `filesystem` are built in. An
  application registers its own under `drivers`: a class implementing `ObjectStorageDriver` (the static factory
  `fromSettings(ObjectStorageSettings $settings, ?LoggerInterface $logger = null): static` plus at least the four methods
  of `BasicObjectStorage`). It reads its own keys with `$settings->getOption('gatewayUrl')` (env
  `OBJECT_STORAGE_PICTURES_GATEWAY_URL` overrides the committed key). An unknown driver name throws, naming the
  registered drivers.
- **Two levels:** `BasicObjectStorage` (put, get, delete, exists) and `ObjectStorage` (extends it: list, temporary
  URLs, presigned uploads, multipart, ranges, head, copy). `getObjectStorage()` refuses a store whose driver is narrow;
  type a consumer that only keeps files by key against `BasicObjectStorage`.
- **Credentials (s3):** `OBJECT_STORAGE_<NAME>_ACCESS_KEY` and `_SECRET_KEY` from the environment. Without both the
  store is not configured (null, the reason logged once) unless it declares `'credentials' => 'ambient'`
  (`OBJECT_STORAGE_<NAME>_CREDENTIALS=ambient`): then async-aws's credential chain of the host is used (environment,
  profile, instance metadata).
- Every committed setting is overridden per host by `OBJECT_STORAGE_<NAME>_<SETTING>`;
  `getObjectStorageNotConfiguredReason($name)` says what is missing.

### Files and file sources

Files of every store of an application (a media library, ticket attachments, a drive) behind one port. A reference
`<source>/<id>` (`media/901`) names a FILE, never a record, and is a name, not a grant: every read resolves it again
under the caller's rights.

- **Port `FileSource`** (`DDD\Domain\Common\Services\Files`, tag `ddd.file_source`, autoconfigured): a store answers
  `getSourceName()`, `findFilesFacts(string[] $ids): FileFactsSet` (only what the caller may read) and
  `readFileContent(string $id, int $maxBytes): FileContent`. It reads through its entity's repository, decides the
  audience per file (`PUBLIC` < `SHARED` < `INTERNAL` < `PERSONAL`), checks the cap before the read, measures type,
  size and sha256 from the bytes and hands out no store URL (at most an application path via `setOpenLinkIfSafe()`).
- **`FileSourcesService`**: resolves references, reads facts (`findFilesFacts(FileReferences)`) and bytes with a cap,
  renders previews through `RasterImageDecoder` (upright, metadata removed) and removes image metadata.
- **`FileFlowPolicyService`**: the one flow rule every sink asks before it copies (STORE), hands bytes to a model
  (MODEL) or shows a file (OPERATOR). Core's default never copies a file to more readers than it has, never copies a
  PERSONAL file and keeps no durable copy of one for a model; metadata is removed when a file becomes PUBLIC. An
  application replaces it with `App\Domain\Common\Services\Files\FileFlowPolicyService` (same path).
- **`FileSourcesControllerTrait`**: `GET /{source}/{id}/facts` and `/{source}/{id}/content?variant=ORIGINAL|PREVIEW|THUMBNAIL`,
  served through the backend and the session as untrusted content.

A project adds sources (one class per prefix) and may replace the flow rule; `FileSourcesService` and every tool or
route that takes references stay unchanged. Each sink checks in this order: the source is registered, the caller may
write the destination (write right, a read-only mount), on the agent's consent mount the store's precheck (refusals
known without bytes are never put to the owner), owner consent, the source's read right (facts), the flow rule (before
any byte is read), the size and pixel limits (previews: 25 MB, 50 megapixels, edge 16–2048 px), the bytes measured,
metadata removed when the target is public (a HEIC picture becomes a JPEG), durable copy and expiry as the decision
says, then the destination's own rules (scan, quotas).

Building a source, the flow matrix, a host rule and the routes: skill `ddd-files-specialist`. Built on it: `mgamadeus/ddd-clouddrive`
(the source `drive`, imports from other sources) and `mgamadeus/ddd-agents` (references in the drive tools, `viewImages`).

## Project Structure (Consuming Application)

```
your-app/
+-- src/
|   +-- Domain/                              # Business logic (DDD)
|   |   +-- {DomainName}/
|   |       +-- Entities/{Group}/            # Entity.php, Entities.php
|   |       +-- Repo/DB/{Group}/             # DBEntity.php, DBEntities.php (+ auto-generated Model)
|   |       +-- Services/                    # EntitiesService.php
|   |       +-- MessageHandlers/             # AppMessage.php, AppMessageHandler.php
|   +-- Infrastructure/                      # Cross-cutting: AuthService, AppService
|   +-- Presentation/Api/                    # Controllers & DTOs
|   |   +-- Admin/                           # Admin endpoints (ROLE_ADMIN)
|   |   +-- Client/                          # Client endpoints (JWT)
|   |   +-- Public/                          # Public endpoints (no auth)
|   |   +-- Batch/                           # Integration endpoints
|   +-- Symfony/Commands/                    # Console commands
+-- config/
|   +-- app/                                 # App-specific config
|   +-- symfony/default/                     # services.yaml, routes.yaml, messenger.yaml
+-- vendor/mgamadeus/ddd/src/                # This framework
```

### Domain Directory Pattern

```
Domain/{DomainName}/
+-- Entities/{Group}/{Entity}.php, {Entity}s.php
+-- Repo/DB/{Group}/DB{Entity}.php, DB{Entity}s.php, DB{Entity}Model.php (auto-generated)
+-- Services/{Entity}sService.php
+-- MessageHandlers/{Action}Message.php, {Action}Handler.php

Presentation/Api/{Audience}/{DomainName}/
+-- Controller/{Entity}Controller.php
+-- Dtos/{Entity}*Dto.php
```

## Conventions

- **Always** `declare(strict_types=1)` in every file
- **Never** use `private` -- always `protected` (the framework is built for extensibility)
- **Never** manually edit `DB*Model.php` files (auto-generated from entity attributes)
- **Never** cache services in class properties -- always resolve from the container
- **Never** use PHP's `\DateTime` -- use `DDD\Infrastructure\Base\DateTime\DateTime` or `Date`
- **Always** pass entity objects to functions, not raw IDs
- Traits are comma-separated on a single line: `use TraitA, TraitB;`
- Constants over magic values: `self::STATUS_ACTIVE` not `'ACTIVE'`
- Services via container: `Products::getService()` or `AppService::instance()->getService(ProductsService::class)`

## Object Hierarchy

```
BaseObject (abstract)
  +- DefaultObject -- SerializerTrait, ValidatorTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait
      +- Entity -- domain entities with identity
      +- ValueObject -- immutable objects
      |   +- ObjectSet -> EntitySet -- typed collections
      +- Other domain objects
```

## License

MIT
