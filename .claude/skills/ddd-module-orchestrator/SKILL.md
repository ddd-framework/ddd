---
name: ddd-module-orchestrator
description: Orchestrates all DDD modules from Core — study, release, propagate. Contains the dependency graph, release order, the dependency-floor cascade (exact-version floors, transitive re-release), consuming-app rules, the npm client @mgamadeus/ddd-web, the no-scripts policy (modules yes, apps NEVER), the platform-flag policy (apps pin config.platform.php, plain composer update -W), package vs repo names, the package cuts and names rulebook (shared domains, one feature word per package, ddd-agent organs, agent skills Topic.Feature, package ids provider.topic.feature bound by class, right constants, package vs host), doc layers (AGENTS.md, vendor skill links by ddd-link-skills), rename maps (renames.json, ddd-renames), handoff-doc ingestion. Use when creating a package or host, naming a module, skill, package id or right, shipping renames, making cross-module changes, choosing release order, raising floors, running composer update in modules or apps, updating docs across repos, or ingesting a handoff doc.
metadata:
  author: mgamadeus
  version: "2.4.0"
---

# DDD Module Orchestrator

Centralized control for studying, updating, releasing, and propagating changes across the entire DDD module ecosystem.

## When to Use

- Making changes that affect multiple DDD modules
- Wiring or checking the generated vendor skill links of an app (`ddd-link-skills`, `ddd-web link-skills`)
- Releasing a new version of Core and propagating to dependent modules
- Updating AGENTS.md, skills, or README across modules
- Studying a module's codebase before making changes
- Running `composer update` in dependent modules after a release

## Module Ecosystem

### Packages

| Package | Description |
|---------|-------------|
| `mgamadeus/ddd` | Core framework (entities, repos, services, QueryOptions, presentation) |
| `mgamadeus/ddd-common-money` | Currency and MoneyAmount value objects |
| `mgamadeus/ddd-argus` | External API repository layer with multi-tier caching |
| `mgamadeus/ddd-common-political` | Countries, languages, locales, states, localities |
| `mgamadeus/ddd-ai` | AI model management, prompts, Argus AI traits |
| `mgamadeus/ddd-common-geo` | Addresses, geocoding, GeoRegion hierarchy |
| `mgamadeus/ddd-common-translations` | Text translation, app UI translations, embeddings |
| `mgamadeus/ddd-design-studio` | Design review studio: HTML design boards of a checkout on a canvas, comments, statuses, decisions, thumbnails; npm counterpart `@mgamadeus/ddd-design-studio` in `web/` |
| `mgamadeus/ddd-agents` | The agentic loop on top of `ddd-ai`: conversations, task plan, agent skills and purposes, MCP tools, widgets, delegation and schedules, decision layer, observability; npm counterpart `@mgamadeus/ddd-agents` in `web/` (pre-release) |
| `mgamadeus/ddd-site-insights` | Search Console (later analytics) for the admin and for agents: reads Google through `ddd-argus` external private calls, caches, stores nothing, states its coverage; console `app:site-insights:*`; npm counterpart `@mgamadeus/ddd-site-insights` in `web/` (pre-release) |

**Module skills of `ddd-agents`** — nine skills, one owner per source file
(`references/source-ownership.tsv` of the entry skill; the Core skill gate requires the entry skill plus the owning
topic skill before a file under the module's `src/` or `config/` changes):

| Skill | Covers |
|---|---|
| `ddd-module-agents-specialist` | entry: install, wiring, host duties (console commands, crons, middlewares, transports), configuration, folder → skill table |
| `ddd-module-agents-loop-specialist` | how a turn runs, from the owner's message to the answer |
| `ddd-module-agents-context-specialist` | what the model sees on each call: prompts, cache, reduction, recall, state delivery |
| `ddd-module-agents-skills-and-purposes-specialist` | agent skills, catalog and loading, purposes, done criteria |
| `ddd-module-agents-tools-specialist` | MCP tool layer: attributes, DTOs, executor, batches |
| `ddd-module-agents-widgets-specialist` | widgets, the owner's answer, signal sources, realtime signal |
| `ddd-module-agents-autonomy-specialist` | delegation, schedules, mandates, supervisor, owner reports |
| `ddd-module-agents-decisions-and-guards-specialist` | decision sites, owner consent, injection screening |
| `ddd-module-agents-observability-specialist` | diagnosis, record and replay, evaluation, metrics |

### Dependency Graph (Determines Release & Update Order)

```
Level 0 (root -- nothing depends on the framework below it):
  mgamadeus/ddd                    (Core)   [EVERY module requires mgamadeus/ddd ^2.10]

Level 1 (depends only on ddd):
  mgamadeus/ddd-common-money       (Money)  -> ddd
  mgamadeus/ddd-argus              (Argus)  -> ddd

Level 2 (depends on ddd + Level 1):
  mgamadeus/ddd-common-political   -> ddd, ddd-argus
  mgamadeus/ddd-ai                 -> ddd, ddd-argus, ddd-common-money
  mgamadeus/ddd-design-studio      -> ddd, ddd-argus
  mgamadeus/ddd-site-insights      -> ddd, ddd-argus

Level 3 (depends on ddd + Level 1/2):
  mgamadeus/ddd-common-geo         -> ddd, ddd-common-political
  mgamadeus/ddd-common-translations -> ddd, ddd-ai, ddd-common-money, ddd-common-political
  mgamadeus/ddd-agents             -> ddd, ddd-ai, ddd-argus, ddd-common-money
```

### Reverse Dependencies (Who Depends on What)

| If you release a fix in... | These children must bump their floor + re-release: |
|------------------|-------------------------------|
| **ddd** (Core) | Money, Argus, Political, AI, Geo, Translations, Design Studio, Agents, Site Insights (ALL) |
| **ddd-common-money** | AI, Translations, Agents |
| **ddd-argus** | Political, AI, Design Studio, Agents, Site Insights |
| **ddd-common-political** | Geo, Translations |
| **ddd-ai** | Translations, Agents |
| **ddd-common-geo** | (none -- leaf module) |
| **ddd-common-translations** | (none -- leaf module) |
| **ddd-design-studio** | (none -- leaf module) |
| **ddd-agents** | (none -- leaf module) |
| **ddd-site-insights** | (none -- leaf module) |

> **The repositories live in the GitHub organisation `ddd-framework`; the package names stay `mgamadeus/*`.**
> **GitHub repo names ≠ package names for the four `ddd-common-*` packages** — the repo drops the `common-` segment:
> `ddd-common-money` → repo `ddd-framework/ddd-money`, `ddd-common-political` → `ddd-framework/ddd-political`,
> `ddd-common-geo` → `ddd-framework/ddd-geo`, `ddd-common-translations` → `ddd-framework/ddd-translations`.
> (`ddd`, `ddd-ai`, `ddd-argus`, `ddd-design-studio`, `ddd-agents`, `ddd-site-insights` match their repo names.) Never create a repository of the same
> name under `mgamadeus` — it would break GitHub's redirect from the old URLs. When pushing, resolve the real repo with
> `git remote get-url origin` (or the Packagist `source.url`) — do not assume the repo matches the package name.

### Dependency-Floor Cascade (propagating a fix DOWNSTREAM, not just locally)

**A `composer update` in a child only refreshes that child's OWN lock — it does NOT change what downstream consumers
can resolve.** The published constraint is what gates consumers. If `ddd-ai` declares `ddd-common-money: ^1.0`, then
*any* app pulling `ddd-ai` may legally resolve money `1.0.x` and silently miss a fix shipped in `1.1.1` — even after
you `composer update`-d the `ddd-ai` working tree. (Real incident: the Currency `#[Choice]` DRY fix shipped in money
`1.1.1`, but `ddd-ai` + `ddd-common-translations` both still floored money at `^1.0`, so a consuming app resolved the buggy
money behind `ddd-ai`. Fix: floors raised to `^1.1.1`, both children re-released → `ddd-ai 1.4.1`, `ddd-translations 1.0.23`.)

**Two distinct actions — do not confuse them:**

| Action | What it does | Forces downstream onto the fix? |
|--------|--------------|---------------------------------|
| `composer update` in the child | refreshes the child's resolved deps (its lock) | ❌ No — published `require` floor unchanged |
| Bump the `require` floor + **re-release** the child | raises the minimum the child (and its consumers) may resolve | ✅ Yes |

**The rule:** when you release a module with a fix/feature that consumers **must not be able to resolve around**,
walk the reverse-dependency graph and, for **every** dependent (transitively, down to the leaves):

1. Raise the `require` floor in the dependent's `composer.json` to the **exact fixed version** — `^1.1.1`, NOT `^1.1`
   (a caret on the full `MAJOR.MINOR.PATCH` excludes the buggy `1.1.0`; `^1.1` would re-admit it).
2. Bump the dependent's own version (PATCH for a pure floor raise) and **re-release** it (commit + tag + push).
3. That re-release makes the dependent a *new upstream release* → repeat for **its** children. The cascade is
   transitive: `money 1.1.1` → bump `ddd-ai` floor (re-release `1.4.1`) → `ddd-ai` is now upgraded → bump
   `ddd-common-translations`'s `ddd-ai` floor (and its `money` floor) → re-release `translations 1.0.23` → done
   (translations is a leaf).
4. Only after all module floors are raised + re-released do you `composer update` the **consuming apps** — they then
   resolve the whole fixed graph (verify with `composer why mgamadeus/<pkg>` that the floor, not luck, pins it).

**Release the cascade in dependency order** (parent before child, so the child can resolve the parent's new tag —
wait for Packagist between each; see [Multi-Module Release](#workflow-release-core--propagate-to-all)). A floor raise
that the existing caret already satisfies (e.g. bumping `^2.10`→`^2.10` for a Core patch) needs no child re-release —
but a *new minimum* (`^1.0`→`^1.1.1`) always does.

### Frontend client: `@mgamadeus/ddd-web` (npm)

The TypeScript counterpart of `mgamadeus/ddd` for web, mobile and desktop frontends: an RTK Query SDK generated from the
backend's DDD OpenAPI document (bin `ddd-web-sdk`), QueryOptions builders, an RTK base query with Bearer token and
refresh, token utilities, and TS types of the DDD wire format. No UI components, no domain logic.

| | |
|---|---|
| Package | `@mgamadeus/ddd-web` on npm (public) — `pnpm add @mgamadeus/ddd-web` |
| Repo | `github.com/ddd-framework/ddd-web` |
| Release | not Packagist: tag `vX.Y.Z` → GitHub Actions `release.yml` (npm trusted publishing with provenance); never part of a Core release |
| Contract | the OpenAPI document Core emits (`src/Presentation/Base/OpenApi/`); planned `x-ddd-*` extensions (`x-ddd-contract`, `x-ddd-query`, `x-ddd-side-effects`, `x-ddd-entity`) and a golden spec test in Core. A change to the OpenAPI output is a contract change: it must show up in the golden spec test, be listed as a contract change in the CHANGELOG and be checked against ddd-web (github.com/ddd-framework/ddd-web) before release |
| Skills | in the package, `node_modules/@mgamadeus/ddd-web/.claude/skills`, linked into an app's `.claude/skills` like the vendor skills — consumer use and maintaining the package are documented there, not here |

Not part of the Composer dependency graph: a Core release needs no ddd-web release unless the OpenAPI contract
changed.

### A module with an npm counterpart: `ddd-design-studio` (and `ddd-agents`, `ddd-site-insights`)

The repository carries the Composer package at its root and the npm package `@mgamadeus/ddd-design-studio` in
`web/`, versions in lockstep (`composer.json` = `web/package.json`). One tag `vX.Y.Z` releases both: Packagist reads
it, and `release.yml` publishes `web/` through npm trusted publishing with provenance (it skips a version already on
npm). The release workflow checks that the tag, `composer.json` and `web/package.json` agree.

### Package cuts and names (read before a new package, a new host or a rename)

The rulebook: [references/package-cuts-and-names.md](references/package-cuts-and-names.md). In short:

- **Two levels.** A domain is a functional area several packages share (`Base`, `Common`, `AI`); a package owns ONE
  feature word and shows it in every outward layer (Composer/npm/repo name, module class, config path, console prefix,
  env prefix, image, skill topic, package id). Placement follows the main outward interface: agent tools → `AI`,
  types other domains use → `Common`, a self-contained product area → an own domain.
- **`ddd-agent-<word>`** (singular) is an organ of the agent with no dependency on ddd-agents (`ddd-agent-sandbox`,
  `ddd-agent-browser`); ddd-ai is one model call, ddd-agents the conversation.
- **Agent skills** are `<Topic>.<Feature>` (functional topic, never an audience or persona; `Agent.*` for the agent's
  own tools and organs; three parts at most; no skill file beside a folder of the same name; no alias on rename).
- **Package ids** are `<provider>.<topic>.<feature>` (host prefix, `ddd.` for Core features, or the module word; written
  once where provider and topic coincide), never derived from a folder, bound by class in `skillPackages.php`.
- **Rights**: a package declares right constants and puts `MCPRequiresAccountRights` on its packages; the host maps
  rights to roles (`AI.accountRights`); an unmapped right is not held.
- **Package or host**: a tool over a table or service of a `mgamadeus` package belongs to that package; the host builds
  its domain topics, adapters and ports.
- Regular versions only, never `-rc.N`. The ddd-agents skill lint and `ModuleNamespacesTestCase` enforce the
  mechanical part.

### Renames ship as data (`renames.json`, `ddd-renames`)

A release that renames a skill name, constant, class, namespace, config key, console command, package id or env var
adds one entry per rename to `renames.json` at the package root (`{ version, kind, old, new, note? }`; schema
`tools/Renames/renames.schema.json` in Core) in the same commit, and runs `vendor/bin/ddd-renames validate` (no chain,
swap or duplicate within a version, old != new). The CHANGELOG names the renames; the map carries them.
Applications run `vendor/bin/ddd-renames` after `composer update` (dry run, file:line), then `--apply` and commit the
result with `.ddd-renames.json`; CI runs `--check`. Spec: README › "Renames of packages (machine-readable)".

### Several packages in one domain: owned namespaces

A module in a shared domain (`DDD\Domain\AI`, `DDD\Domain\Common`) keeps its classes below `<Layer>\<Feature>\` and its
config below `config/app/<Domain>/<Feature>/`, and returns those prefixes from `DDDModule::getOwnedNamespaces()`. Every
module suite and host suite runs one subclass of `DDD\Testing\Modules\ModuleNamespacesTestCase` (only
`getProjectDirectory()`); it fails on a class name or a `config/app/` file shipped by two installed packages and on a
class outside the declared prefixes. A shared config file kept during a move goes into `getAcceptedSharedConfigFiles()`,
a list that only shrinks.

### npm-only packages of the ecosystem

`@mgamadeus/ddd-web` (contract client, SDK generator) and `@mgamadeus/ddd-admin-ui` (the admin building blocks:
primitives, list, page, form, translations, media, data; repo `ddd-framework/ddd-admin-ui`) have no Composer part.
Both release by tag through `release.yml` (npm trusted publishing). A new npm package name needs the owner once: the
first publish and `npm trust` with 2FA (never a token), then every tag publishes on its own. A new Composer package
needs its Packagist registration and the repository's Packagist webhook (owner, browser).

### Consuming Applications

Any application using DDD modules needs a `composer update` after module releases. Consuming apps set `config.platform.php`, so a plain `composer update -W` is correct — no `--ignore-platform-reqs` (see the platform note below). Check the application's `composer.json` for `mgamadeus/*` dependencies.

Keep the list of consuming apps, their checkout paths and their `DATABASE_TABLE_PREFIX` in local, untracked notes —
not in this public repository. An app with a table prefix (e.g. `Entity`) is the one the `--no-scripts` rule below
protects.

#### CRITICAL: never pass `--no-scripts` when updating a consuming app

Consuming apps wire the Doctrine model generator into composer's lifecycle:

```jsonc
"scripts": {
  "post-update-cmd":  ["php bin/console cache:clear",
                       "php bin/console app:generate-doctrine-models-for-entities"],
  "post-install-cmd": ["php bin/console cache:clear",
                       "php bin/console app:generate-doctrine-models-for-entities"]
}
```

`composer update` overwrites `vendor/` with each package's **shipped** `DB*Model` files —
generated upstream with the upstream prefix (none) and without the consumer's entity
overrides. The `post-update-cmd` hook **regenerates** them against the consumer's own
`DATABASE_TABLE_PREFIX` and overridden entities. Skipping it with `--no-scripts` leaves the
vendor models on the upstream table names, so any framework-internal code that uses a vendor
repo directly (`new DBCacheScopeInvalidation()`, etc.) hits a non-existent table at runtime
(`SQLSTATE[42S02] … doesn't exist`) — surfacing only on the code path that touches it (e.g. a
cache-hit). This caused a real production 500 in an app with a table prefix
(`CacheScopeInvalidations` vs `EntityCacheScopeInvalidations`).

Therefore, the canonical update form **differs between modules and apps**:

```bash
# Framework MODULES (Core, AI, Argus, Geo, Money, Political, Translations) — no gen hook,
# --no-scripts is safe and faster. Module repos do NOT set config.platform, so if the local
# machine is missing an extension a module needs, add a TARGETED --ignore-platform-req=ext-<name>
# (never the broad --ignore-platform-reqs, never ignore php):
composer update mgamadeus/ddd --no-scripts -W

# Consuming APPS — let the scripts run, NEVER --no-scripts.
# Apps set config.platform.php, which already pins resolution — no platform flag needed:
composer update mgamadeus/ddd -W
```

If a throwaway resolver-only check genuinely needs `--no-scripts` on an app, run the generator
yourself immediately after: `php bin/console app:generate-doctrine-models-for-entities`.

A plain `composer update` (no platform flag) is correct in every consuming app: they
all set `config.platform.php`, which pins resolution to the deployment PHP. Do NOT add the broad
`--ignore-platform-reqs` — it overrides that pin and can pull newer-PHP-only packages into the lock
(this is how five PHP-8.4-only packages once landed in an app's committed 8.3 lock). If the local box is
missing an extension, ignore only that one (`--ignore-platform-req=ext-<name>`), never `php`. See the
`ddd-composer-update-version` skill §"Updating Consuming Apps". Still without `--no-scripts`.

---

## Workflow: Study a Module Before Making Changes

Before modifying any module, read these files in order:

1. **`composer.json`** -- package name, version, dependencies
2. **`AGENTS.md`** -- architecture, entity overview, patterns, conventions
3. **`.claude/skills/*/SKILL.md`** -- detailed domain knowledge
4. **`README.md`** -- public documentation
5. **`src/Modules/<Name>/<Name>Module.php`** -- DDDModule entry point (e.g. `src/Modules/Money/MoneyModule.php`, `src/Modules/Argus/ArgusModule.php`; source path, config path, public namespaces)
6. **Key entity files** -- read `src/Domain/` entities to understand the domain model
7. **Key service files** -- read services for business logic patterns

```bash
# Quick study script for any module (pass path as argument)
cat "$MODULE_PATH/composer.json" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Package: {d[\"name\"]} v{d[\"version\"]}'); print('Requires:'); [print(f'  {k}: {v}') for k,v in d.get('require',{}).items() if 'mgamadeus' in k]"
```

---

## Workflow: Release a Single Module

### 1. Make Changes

Edit files in the module's directory (entities, services, skills, docs).

### 2. Commit

```bash
cd "$MODULE_PATH"
git add <changed-files>
git commit -m "$(cat <<'EOF'
Description of changes

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

### 3. Bump Version + Tag + Push

Use the `ddd-composer-update-version` skill for the full process.

### 4. Propagate to Dependent Modules

**Wait ~10 seconds** after the tag push for Packagist to register the new version, then run `composer update` in all dependent modules (see reverse dependency table above). Module repos set no `config.platform`, so add a **targeted** `--ignore-platform-req=ext-<name>` only if the local box is missing an extension — never the broad `--ignore-platform-reqs`, never ignore `php`.

If `composer.lock` changed, commit and push it.

---

## Workflow: Release Core + Propagate to All

When releasing a new DDD Core version, ALL modules need updating.

### Process in dependency order:

```
1. Core           -> release, wait 10s
2. Money, Argus   -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
3. Political, AI, Design Studio -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
4. Geo, Translations -> composer update (--no-scripts ok), make changes if needed, release, wait 10s
5. Consuming apps -> composer update (NEVER --no-scripts — see "Consuming Applications")
```

**At each level:** modules within the same level can be processed in parallel (they don't depend on each other).

For each MODULE at each level (modules have no gen hook, so `--no-scripts` is safe and faster):
```bash
cd "$MODULE_PATH" && composer update --no-scripts -W   # add --ignore-platform-req=ext-<name> only if a local ext is missing
if [ -n "$(git diff composer.lock)" ]; then
  git add composer.lock
  git commit -m "Update composer dependencies

Co-Authored-By: Claude <noreply@anthropic.com>"
  git push
fi
```

For each consuming APP at step 5 (steps above are process order, not the graph's Levels 0-3; let the `post-update-cmd` model generator run — **no `--no-scripts`**):
```bash
cd "$APP_PATH" && composer update mgamadeus/ddd -W   # apps pin config.platform.php; no platform flag
# post-update-cmd auto-runs: cache:clear + app:generate-doctrine-models-for-entities
```

---

## Workflow: Update Documentation Across All Modules

When updating AGENTS.md, skills, or README across multiple modules:

1. **Study current state** -- check line counts and content of AGENTS.md, skills, README in each module
2. **Make changes** -- edit files directly in each module's directory
3. **Commit + Release each** -- process in dependency order, bump version, push, tag
4. **Propagate** -- `composer update` in dependents after each release

---

## Key Conventions

- **Platform flags:** consuming apps set `config.platform.php` → run a plain `composer update -W`, **no** `--ignore-platform-reqs` (the broad form overrides the pin and can poison the lock). Framework **module** repos set no platform pin → if the local machine lacks an extension a module needs, add a **targeted** `--ignore-platform-req=ext-<name>` only, never `php`, never the broad form. See `ddd-composer-update-version`.
- **`--no-scripts` on framework modules only, NEVER on consuming apps.** Modules have no composer lifecycle hooks; apps wire `app:generate-doctrine-models-for-entities` into `post-update-cmd`, and skipping it leaves vendor `DB*Model` files on upstream (prefix-less, override-less) table names → runtime `SQLSTATE[42S02]` 500. See [Consuming Applications](#consuming-applications).
- **Wait ~10 seconds** between pushing a tag and running `composer update` in dependents -- Packagist needs time to register the new version (in practice often longer; if `composer update` reports the old version, clear the cache with `composer clear-cache` and retry, or poll `https://repo.packagist.org/p2/<vendor>/<pkg>.json`)
- **Consumers never patch `mgamadeus/*`.** No cweagans `extra.patches`, no hand-edited `vendor/mgamadeus/` files in a consuming app. A DDD bug is fixed in the DDD repo and released as a patch version; the app updates. An unavoidable short-lived workaround lives in APP code with a TODO naming the target release — never in `vendor/`. A patch found in an app is an ingest candidate: upstream it, release, then the app drops the patch.
- **Always commit `composer.lock`** changes in modules after dependency updates
- **Never force-push tags** unless explicitly asked
- **Tag format:** `v` prefix required (e.g., `v2.10.12`) -- Packagist matches `v*` pattern
- **Version in `composer.json`** must match tag (without `v` prefix)
- **Co-Authored-By** header required on all commits — use the exact trailer your harness specifies for the current model (the templates above show the generic form)

### Ingesting a pull request

Follow AGENTS.md › "Ingesting a Pull Request": fetch `pull/<n>/head` without checking out, review the NET diff
against conventions, skills, behaviour, tests and the public-repository rule, squash with the contributor as author,
add your own follow-up commit for findings, verify, release.

### Ingesting an upstream handoff doc

A handoff/donation doc is a **proposal, not authoritative spec**. Workflow for each one:

1. **Convention-scan first** (not a verbatim copy): reject `array`-shaped public surface (use a `ValueObject`/`ObjectSet`; flat `string[]`/`int[]` OK), `private` (→ `protected`), `&$out` params, and any `\App\…` / consuming-app class names or repo-internal doc paths leaking into framework code (genericize them).
2. **Apply** — prefer matching the donor's byte-validated vendor copy (the change usually already runs there); for small changes apply via `Edit`. Confirm **byte-identity to the vendor copy** + `php -l` + Qodana (`get_file_problems`).
3. **Release** the module (correct semver bump) and **propagate** to consuming apps (see the release workflows above).
4. **ALWAYS mark the doc done — as the FINAL step — by renaming it** `<name>-2026-06.md` → `<name>-2026-06-ingested-on-<YYYY-MM-DD>.md`. **Never leave ingested content sitting bare.** A doc that is byte-identical to one already ingested (a re-issue) is marked too; a doc whose fix is NOT yet present in the donor's vendor stays **bare** (do not mark — it is genuinely open).

> Verify a doc's true state before (re-)ingesting: grep the *code* for the change's marker in BOTH our Core and the donor vendor — a doc can sit bare yet already be applied, or be marked yet diverge from a re-issue.

---

## Documentation Architecture

### Layered Knowledge System

```
DDD Core (vendor/mgamadeus/ddd/)
+-- AGENTS.md               [Framework architecture, conventions, best practices, utilities reference]
+-- README.md                [Public documentation for humans]
+-- .claude/skills/ddd-*     [14 framework skills]

DDD Modules (vendor/mgamadeus/ddd-{name}/)
+-- AGENTS.md               [Module architecture, entities, services -- references DDD Core AGENTS.md]
+-- README.md                [Public module documentation]
+-- .claude/skills/ddd-module-{name}-specialist  [Module-specific domain knowledge; a large module adds topic skills ddd-module-{name}-{topic}-specialist]

Consuming Applications (.claude/skills/)
+-- SYMLINKS to vendor/      [ddd-*, ddd-module-* and npm package skills; GENERATED by `ddd-link-skills` / `ddd-web link-skills` from the install hooks (new skills linked, removed ones cleaned up), git-ignored -- never add them by hand]
+-- {app}-entity-specialist  [Thin extension: app domains, file paths, strategy docs]
+-- {app}-endpoint-specialist [Thin extension: app API audiences, conventions]
+-- {app}-*                  [App-specific skills: tests, frontend, business strategy]
+-- AGENTS.md (project root) [App business context, domain vocabulary -- references vendor AGENTS.md]
```

### Cross-Reference Conventions

Every AGENTS.md and skill must reference its parent layer:

- **Module AGENTS.md** must contain: `> For base patterns, see vendor/mgamadeus/ddd/AGENTS.md and skills in vendor/mgamadeus/ddd.`
- **Module skills** must contain: `> Base patterns: See core skills in vendor/mgamadeus/ddd.`
- **App AGENTS.md** must contain: `> For all DDD patterns, see vendor/mgamadeus/ddd/AGENTS.md and the DDD Core skills (symlinked in .claude/skills/ddd-*).`
- **App extension skills** must contain: `> For base templates, see the ddd-{name}-specialist skill.`

Modules that depend on other modules must also reference them (e.g., "For Argus patterns, see `vendor/mgamadeus/ddd-argus`.").

### Skill Naming Convention

| Prefix | Meaning | Example |
|--------|---------|---------|
| `ddd-` | DDD Core framework skill | `ddd-entity-specialist` |
| `ddd-module-` | DDD module skill | `ddd-module-geo-specialist` |
| `ddd-module-<package word>-` | skill of a module, named after its package word | `ddd-module-agent-sandbox-specialist` |
| `{app}-` | App-specific extension | (defined per consuming application) |
| (no prefix) | Generic tooling | `planning-with-files`, `skill-creator` |

### Vendor Skill Links for Consuming Applications (generated)

Apps no longer track symlinks to vendor skills by hand. The Composer bin `ddd-link-skills` (Core, plain PHP, no kernel
boot) and `ddd-web link-skills` (npm `@mgamadeus/ddd-web`) generate them — same behaviour, same manifest; each scans
both `vendor/mgamadeus/*` and `node_modules/@mgamadeus/*`, so either hook alone links everything.

App wiring (Composer root `app/`, frontend `web/` as an example) — add to the existing scripts, never replace them:

```jsonc
"scripts": {
  "post-install-cmd": ["@php vendor/bin/ddd-link-skills", "php bin/console cache:clear", "php bin/console app:generate-doctrine-models-for-entities"],
  "post-update-cmd":  ["@php vendor/bin/ddd-link-skills", "php bin/console cache:clear", "php bin/console app:generate-doctrine-models-for-entities"]
}
```

and in `web/package.json` `"postinstall": "ddd-web link-skills"`. Then once: `git rm --cached` the formerly hand-made
links (the tool prints the exact command), commit with the generated `.gitignore` blocks. CI / session start:
`php app/vendor/bin/ddd-link-skills --check` (exit 1 on missing, stale or broken links, clashes, generated links still
tracked, edited vendor skills).

What it does: root = nearest ancestor with `.git` (else `.claude`, never the home folder; `--root` / `DDD_SKILLS_ROOT`);
links into the real location of `<root>/.claude/skills` with relative targets; mirrors into `.agents/skills` /
`.codex/skills` only when they exist as separate real folders (`../../.claude/skills/<name>`); never touches app
skills (a name clash is reported); deletes broken links into DDD vendor areas; records each link in
`<primary>/.ddd-skills.json` (package, version, sha256 of SKILL.md, mirrors); keeps a `# ddd-skills:start` …
`# ddd-skills:end` block in the `.gitignore` files. Full spec: README › "Agent Skills (vendor skill links)".

A new module skill therefore reaches every app with the next install — no symlink commit in the apps.

### What NOT to Put in DDD Core

- Project-specific domain knowledge (app-specific entities, business vocabulary)
- Project-specific file paths or directory structures
- Project-specific strategy documents or business context
- Project-specific environment variables beyond framework defaults
- References to specific consuming applications

### What Lives Where

| Content | Location | Purpose |
|---------|----------|---------|
| Framework patterns | Core `.claude/skills/ddd-*` | Entity, service, endpoint, QueryOptions, rights, message handler, CLI command |
| Module domain knowledge | Module `.claude/skills/ddd-module-*` | Module-specific entities, services, APIs |
| Architecture overview | `AGENTS.md` in each repo | High-level reference (must reference parent layer) |
| Public documentation | `README.md` in each repo | Installation, setup, usage for humans |
| Orchestration | Core `.claude/skills/ddd-module-orchestrator` | Cross-module workflows, documentation architecture |
| Version management | Core `.claude/skills/ddd-composer-update-version` | Release process |
| Code quality | Core `.claude/skills/ddd-code-inspect-with-qodana` | Static analysis |
| Rights system | Core `.claude/skills/ddd-rights-specialist` | Access control patterns |
| Schema migrations / diffs | Core `.claude/skills/ddd-database-schema-diff-specialist` | Live-vs-target schema diff + admin UI (the migration side of a release) |
