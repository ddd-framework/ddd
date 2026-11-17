# Issues admin screen

How to build the issues screen of an admin interface on the endpoints of `IssuesControllerTrait`. Paths are relative
to the prefix of the application's controller (e.g. `/api/admin/common/issues`).

## Contents

1. Endpoints
2. Payloads
3. Screen layout
4. The details drawer
5. Rules the screen must follow
6. Checklist

## 1. Endpoints

| Method and path | Query / body | Response properties |
|---|---|---|
| `GET /groups/list` | `environment`, `status` (`OPEN` default, `RESOLVED`, `IGNORED`, `ALL`), `search`, `section`, `release`, `limit` (50, max 200), `offset` | `groups`, `statusCounts`, `environments`, `environment`, `health` |
| `GET /trend` | `environment`, `groupHash`, `status` (`ALL` default), `search`, `section`, `release`, `from`, `to` | `environment`, `groupHash`, `from`, `to`, `stepSeconds`, `series`, `total`, `trendError`, `isFilteredByGroups`, `matchingGroupCount`, `summedGroupCount`, `isTruncated` |
| `GET /groups/{environment}/{groupHash}` | — | `group`, `issues`, `events`, `eventsError`, `logBackendUrl`, `agentJson` |
| `POST /groups/{environment}/{groupHash}/resolve` | — | `group` |
| `POST /groups/{environment}/{groupHash}/ignore` | — | `group` |
| `POST /groups/{environment}/{groupHash}/reopen` | — | `group` |

- `environment`: a configured environment (`queryEnvironments`) or one that still holds groups; `all` for every one
  (list, counts and trend summed); omitted = the first known one (`environment` in the response says which). An
  unknown value is a 400. The group paths take a real environment, never `all`.
- `from` / `to`: ISO 8601 (`2026-10-28T10:00:00Z`); default the last 24 hours, at most 31 days; the step follows the
  window (60 s up to 1 h, 600 s up to 6 h, 3600 s up to 24 h, then 21600 s).
- `groupHash` on `/trend` needs a single environment and no list filter (400 otherwise); an unknown group is a 404.
- Every set serialises as `{"elements": [...]}`; date-times of entities are `Y-m-d H:i:s` UTC, the trend's `x` and
  `from` / `to` ISO 8601 UTC.
- The same data from the console: `app:issues:list`, `show`, `trend`, `resolve` / `ignore` / `reopen` with
  `--format=json` (SKILL.md › Discovery CLI).

## 2. Payloads

**Group** (`groups.elements[]`, `group`): `environment`, `groupHash`, `status`, `exceptionType`, `sampleMessage`,
`slug`, `logOrigin`, `section`, `routePattern` (the entry point readable: `POST /api/admin/orders`,
`SendEmailMessage (send_email)`, `app:x:y`, `/orders/[orderId]`; null when unknown), `firstSeenAt`, `lastSeenAt`,
`eventCount` (approximate), `releaseFirstSeen`, `releaseLastSeen`, `isNew`, `isRegression`, `resolvedAt`.

**`statusCounts`**: `open`, `resolved`, `ignored` of the selected environment (summed for `all`) — the tab badges.

**`health`**: `lastSuccessfulRunAt`, `newestEventAt`, `jobStalled`, `collectorSilent`, `lastError` of the selected
environment (the overall health for `all`).

**Trend**: `series` is a gap-free `DateTimeValueSequence` (`{x, y}` per bucket, 0 where nothing happened). Without a
list filter it counts every error event of the scope in the log backend (also of groups the registry has not seen
yet); with one (`isFilteredByGroups` true) it is the sum of the matching groups' trends: `matchingGroupCount` groups had
events in the window, `summedGroupCount` are in the sum (at most 50, most events first, within a 15 s budget),
`isTruncated` = the points are a lower bound. `trendError`: `backend_unavailable` (every point 0),
`backend_not_configured` (every point 0), `backend_partial` (some environments or groups missing); null = complete.

**Issue** (`issues.elements[]`, most events first): `issueHash`, `entryPoint`, `eventCount`, `lastSeenAt` — "where it
happens".

**Event** (`events.elements[]`, newest first, up to 20): `eventId`, `timestamp`, `level`, `message`, `errorType`,
`route`, `httpStatus`, `httpMethod`, `routeParameters` (key/value list), `command`, `release`, `commit` (client tag),
`stackTrace` (cut at the event cap), `backendFrames` (`file`, `line`, `function`, `inApp`, `codeContextStartLine`,
`codeContextLines`), `causes` (`type`, `message`, `file`, `frames`), and

- for a client event (sections `WEB`, `WEB_SERVER`, `MOBILE`): `client` (`runtime`, `app`, `release`, `browserName`,
  `browserVersion`, `osName`, `viewportWidth`, `viewportHeight`, `tabId`, `routePattern`, `url`), `breadcrumbs`
  (`at`, `category`, `message`, `url`, `from`, `to`, `status`), `frames` (minified `file` / `line` / `column` /
  `function`, and `resolved*` + `contextLines` where a source map of the release resolved them),
  `resolvedStackTrace` (only when a frame was resolved), `resolveError` (the source-map store failed);
- for a failed worker message: `worker` (`messageClass`, `handler`, `messageKeys`, `attempt`, `final`, `retryCount`,
  `transports`, `ageSeconds`, `origin` with `route` / `command` / `accountId` / `workspace` / `dispatchedAt`).

`client`, `worker` and `worker.origin` are absent (not null-typed as required) when the event has none.

**`agentJson`**: the group, its issues and the newest 3 events exactly as `app:issues:show --format=json` prints them —
the payload for "Copy for agent".

**`logBackendUrl`**: the registry's `alertLinkTemplate` filled for the group; null when not configured.

## 3. Screen layout

1. **Header**: environment switch (`environments`, plus "All"), status tabs Open / Resolved / Ignored / All with the
   badges of `statusCounts`, search box, section and release filters.
2. **Health banner** from `health`: job stalled (with `lastError`), collector silent. An empty list with an unhealthy
   registry is "unknown", never "no errors".
3. **Trend chart** above the list (`/trend` with the same environment and filters as the list): bars per bucket;
   label it with `stepSeconds`; when `isFilteredByGroups`, say "sum of N groups" (`summedGroupCount` of
   `matchingGroupCount`) and mark it as a lower bound when `isTruncated`; show `trendError` as a note on the chart.
4. **List**: type · message · route pattern · events · last seen · release; flags "new" and "regression"; an
   environment column when "All" is selected; paging with `limit` / `offset`.
5. **Row → details drawer** (section 4) with Resolve / Ignore / Reopen.

## 4. The details drawer

- Header: type, message, status, flags, `routePattern`, first / last seen, releases, link "Open in log backend"
  (`logBackendUrl`, hidden when null), "Copy for agent" (`agentJson`).
- Sparkline: `/trend?environment=<group env>&groupHash=<hash>` (no list filters).
- "Where it happens": `issues`.
- "Last occurrences": `events`; per event the stack (backend frames with their code context, client frames with the
  original position when resolved, the raw `stackTrace` as fallback), causes, request (method, route, parameters),
  command, the worker block, the client block and breadcrumbs. Rendering of frames and code context:
  [admin-error-detail.md](admin-error-detail.md).
- `eventsError` set: show it in place of the occurrences; the group and its issues stay usable.

## 5. Rules the screen must follow

- **Status changes are idempotent** and answer the group with its new status: replace the row with it.
- **Resolve means "fixed in a deploy"**: a later event reopens the group as a regression (`isRegression`). Ignore
  silences it for good. Say so next to the buttons.
- **Counts are approximate** (collector replays); show them as such, not as billing numbers.
- **A trend of 0 is not "no errors"** while `trendError` is set.
- **The payloads carry stack traces, request parameters and account ids** of every tenant: the screen is for trusted
  admins only. The trait has no role check of its own; restrict its prefix in `access_control` when the admin API
  also serves less trusted accounts.

## 6. Checklist

- [ ] Controller with `IssuesControllerTrait` mounted; access restricted to trusted admins
- [ ] Registry job running (`app:issues:sync-registry-from-log-backend`), `queryEnvironments` configured
- [ ] Environment switch with "All", status tabs with badges, filters passed to both list and trend
- [ ] Health banner; trend notes for `trendError`, `isTruncated`
- [ ] Drawer: sparkline, issues, occurrences, `eventsError`, log backend link, "Copy for agent"
- [ ] Resolve / Ignore / Reopen replace the row with the returned group
