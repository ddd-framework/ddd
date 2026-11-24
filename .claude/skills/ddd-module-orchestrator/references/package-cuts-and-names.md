# Package cuts and names

The rules every DDD package and every host follows when it places code, names a package, an agent skill, a tool
package or a right. They hold before the first line of a new package or host is written; renaming later costs a
release of every package and an adoption in every host.

Contents: 1 Domains and placement · 2 The line between ddd-ai and ddd-agents · 3 Agent skills · 4 Package ids and
binding · 5 The other layers · 6 Rights · 7 Package or host · 8 A new host · 9 Anti-patterns · 10 Lint

## 1. Domains and placement

| Rule | Example | Reason |
|---|---|---|
| A domain is a functional area that several packages share: `Base`, `Common`, `AI` | ddd-ai, ddd-agents, ddd-agent-sandbox and ddd-agent-browser all live in `DDD\Domain\AI` | a domain per package gives a new word for every feature and no place for what two packages share |
| A package owns ONE feature word and shows it in every outward layer | ddd-clouddrive: `CloudDrive` in PHP, config, skill topic, package id | one word finds everything of a package; a second word needs a documented reason |
| Placement follows the main outward interface: agent tools and skills → `AI`; types other domains use → `Common`; a self-contained product area → an own domain | sandbox → `AI`; money → `Common`; clouddrive → own domain | the place follows who uses it, not who wrote it |
| Inside a shared domain a package takes one feature folder per layer and declares it (`getOwnedNamespaces()`) | `DDD\Domain\AI\{Entities,Services,Repo\DB}\Sandboxes` | `ModuleNamespacesTestCase` fails on two packages in one namespace |
| `ddd-agent-<word>` (singular) is an organ of the agent: a thing the agent uses to act, useless without an agent, yet without a dependency on ddd-agents | `ddd-agent-sandbox`, `ddd-agent-browser`; not ddd-clouddrive (people use the drive too) | the name says what installs it |
| Regular versions on the default channel, never `-rc.N` / `next` | `0.5.5`, `3.41.0` | a pre-release is installed by nobody by default and by everybody on a pin: fixes stop arriving |

## 2. The line between ddd-ai and ddd-agents

| Question about a piece of code | Then |
|---|---|
| True for one model call with no before and no after? | ddd-ai: model catalog, call parameters, authentication, usage, cost, the outcome of one attempt |
| Needs a stored row of an earlier call, or decides about the next one? | ddd-agents: reasoning replay, cache marks, retries, pins, the body of a request form |
| A new request form (wire) | lands in ddd-agents alone; ddd-ai passes catalog values through as written and validates no fact only the agent reads |

## 3. Agent skills

| Rule | Right | Wrong | Reason |
|---|---|---|---|
| `<Topic>.<Feature>`; the topic is a functional subject, never an audience, persona or folder | `Orders.Invoices` | `Admin.Invoices`, `Assistant.Tasks` | an audience says who talks, not what the skill is about |
| `Agent.*` holds the agent's own tools and organs: memory, tasks, mandates, settings, budget, web, sandbox, the user's UI | `Agent.Memory`, `Agent.Web.Search`, `Agent.UI` | `Memory.Management`, `Web.Search` | test: would the skill exist in a host with no domain of its own? Then it is the agent's |
| Every other topic is the world the agent works on | `Orders.Invoices`, `CloudDrive.Files` | `Agent.Invoices` | |
| One topic per subject; a subject with features of its own is a topic | `Stores.Teams` | `Partners.Stores` | a subject filed under its owner is found by nobody who looks for the subject |
| One subject, one skill | store files inside `CloudDrive.Files` | `Partners.StoreFiles` beside it | two skills for one store are two ways to do the same thing |
| Plural for a collection, singular for one thing or area | `Accounts`, `Orders`; `Agent`, `Website` | | |
| The feature names what is done or read; never the topic again, never two subjects | `Accounts.Profiles`, `System.Workers`, `System.Crons` | `Accounts.Accounts`, `System.WorkersAndCrons` | a repeated word carries nothing; "And" hides two skills |
| Three parts at most; siblings flat; the hierarchy is `subSkillNames`, not the name | `Agent.Sandbox.Documents` → `Agent.Sandbox.Pptx` | `Agent.Sandbox.Documents.Pptx` | the name is an address, the tree is data |
| No skill file beside a folder of the same name, in any config root | `Website/Builder.md` + `Website/Build/*.md` | `Website/Build.md` + `Website/Build/` | Core's config lookup reads the file and never the folder |
| A rename is code and config, no alias | | | an old name in a stored conversation is skipped; the model loads the new one |

## 4. Package ids and binding

| Rule | Right | Wrong |
|---|---|---|
| `<provider>.<topic>.<feature>`, the words of the skill | `acme.orders.invoices` | `admin.orders.invoices` |
| Provider: the host's prefix (`AI.packageIdProvider`), `ddd.` for a feature of Core, or a module's word | `ddd.system.issues`, `clouddrive.files` | `acme.system.issues` for Core's issues registry |
| Where provider and topic are one word it is written once: the id is the skill name in lower case | `Agent.Memory` ↔ `agent.memory` | `agent.agent.memory`, `agent.memories` |
| Never derived from a folder: `Presentation/Mcp/Admin/…` is the audience of the endpoint | `Presentation/Mcp/Admin/Orders/` holds `acme.orders.invoices` | |
| A package several skills bind keeps a feature of its own | `acme.admin.finder`, `agent.drive` | |
| `skillPackages.php` binds by class | `[OrdersInvoicesMCPPackage::class]` | `['acme.orders.invoices']` (a typo fails only at runtime) |

## 5. The other layers

| Layer | Rule | Example |
|---|---|---|
| Composer, npm, repository | one name: `mgamadeus/ddd-<name>`, `@mgamadeus/ddd-<name>`, `ddd-framework/ddd-<name>` | `ddd-agent-sandbox` in all three |
| Module class | `DDD\Modules\<Name>\<Name>Module` | `DDD\Modules\AgentSandbox\AgentSandboxModule` |
| Presentation, commands | `Presentation\Api\<Audience>\<Word>`, `Presentation\Mcp\<Audience>\<Word>`, `Symfony\Commands\<Word>`; inside a shared domain the short word | `Sandbox*`, not `AgentSandbox*`, in `DDD\Domain\AI` |
| Config | own domain `config/app/<Word>/`; shared domain `config/app/<Domain>/<Word>/`; the key is the path with dots | `AI.Sandbox.runner`, `CloudDrive.*` |
| Console | `app:<package word>:`; a renamed command keeps its old name in `AsCommand` `aliases` | `app:agent-sandbox:check-runner` |
| Environment | `<PACKAGE_WORD>_…`, read in a config file the host can override | `AGENT_BROWSER_RENDERER_TOKEN` |
| Image | `ddd-<name>-<role>`, tag = package version | `ddd-agent-browser-renderer:0.2.0` |
| Class, table, tool | the short word; a tool name is global and never carries the package | `Sandbox*`, `getCurrentScreen` |
| Doc skill | follows the package word | `ddd-module-agent-sandbox-specialist` |

Each module's README states its word per layer in one filled-in copy of this table.

## 6. Rights

| Rule | How |
|---|---|
| A package declares the right its tools need as a constant of its own rights class | `AgentAccountRights::USE_WEB`, a sandbox package's `USE_SANDBOX` |
| The package class carries `MCPRequiresAccountRights`; the skill names the same right in `requiredAccountRights` | the skill key only filters the offer; the executor checks the attribute |
| A host maps rights to its roles; no role name in a package or skill | `AI.accountRights` (`operatePlatform => [Role::SUPERADMIN]`) or its own `AgentAccountRightsInterface` |
| An unmapped right is not held | a platform skill stays closed until the host opens it |
| Platform skills (`System.*`, `Translations.*`) are for superadmins: the right plus `MCPRequiresAdminWithAccessToAllResources` | they read across every account |

## 7. Package or host

| Belongs to a package | Belongs to the host |
|---|---|
| tools and skills for a framework feature: issues, workers, crons, health, texts, drive, browser, sandbox, the user's UI | its domain topics |
| the mechanism of a capability: channel, protocol, state note, defaults | adapters and ports: area catalog, record descriptions, navigation targets, health sections |
| generic recipes (document building) | its know-how as preloaded content and sentences in a same-path skill override |
| right constants | the mapping to roles, feature switches |

Test before a host writes a tool: does it read a table or call a service of `vendor/mgamadeus`? Then the tool belongs
to that package — ask for it there. Core ships no agent part; tools for Core's features ship with ddd-agents.

## 8. A new host

1. Choose the package-id prefix once and set `AI.packageIdProvider`.
2. Install in dependency order (orchestrator graph), then the organs it needs: ddd-clouddrive, ddd-agent-sandbox
   (runner image), ddd-agent-browser (renderer image). Build no file store, shell or page reader of its own.
3. Map the rights in `AI.accountRights`; open the platform skills for its superadmin role. Build no tools for issues,
   workers, crons, health or texts.
4. Write its domain skills by §3 and their packages by §4, bound by class.
5. `Agent.UI`: implement the screen catalog port on the server and the four client adapters (ddd-admin-ui ships
   adapters for an admin built from its blocks).
6. Document know-how goes into preloaded sandbox content, not into document skills of its own.
7. Run the skill lint, `ModuleNamespacesTestCase` and the package suites in its own suite from the first commit.

## 9. Anti-patterns

| Seen | Rule broken |
|---|---|
| package ids `admin.*` taken from `Presentation/Mcp/Admin` | §4: provider, topic, feature |
| `Assistant.*`, `Memory.*`, `Web.*` as topics of the agent's own tools | §3: `Agent.*` |
| two words for one package (`Workspace.*` skills of a sandbox package) | §1: one feature word |
| `Accounts.Accounts`, `System.WorkersAndCrons` | §3: the feature names what is done |
| framework-feature tools built in a host | §7 |
| a general capability named after its first screen and built as host code | §3 `Agent.*` test, §7 |
| a domain per package (`DDD\Domain\Browser`) | §1 |
| `-rc.N` versions | §1 |
| a rule kept only as examples in the docs | §10 lint |

## 10. Lint

| Rule | Checked by |
|---|---|
| a skill has two or three upper-camel-case parts, no part repeats its topic, no `And` | ddd-agents `findSkillNamingProblems()` |
| no skill file beside a folder of the same name | the same |
| a package id has the form of §4 with the configured provider, `ddd` or a module word; never an audience word | ddd-agents `findBindingNamingProblems()` |
| a package bound by exactly one skill carries that skill's words | the same |
| `skillPackages.php` names classes only | the same |
| a right a skill requires is on every package it binds | the same |
| no two packages own one namespace, no class outside the declared ones | Core `ModuleNamespacesTestCase` |

Older names go into `AI.skillLintAllowList`, a list that only shrinks.
