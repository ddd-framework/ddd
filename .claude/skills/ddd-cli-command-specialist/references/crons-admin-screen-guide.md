# Crons admin screen

How to build the Crons screen of an admin interface on the endpoints of `CronsControllerTrait`
([crons-admin-endpoints.md](crons-admin-endpoints.md)). Paths are relative to the prefix of the application's
controller (e.g. `/api/admin/common/crons`).

## Contents

1. Data per view
2. Screen layout
3. Forms
4. Rules the screen must follow
5. Checklist

## 1. Data per view

| View | Request | Refresh |
|---|---|---|
| Crons list and summary tiles | `GET /list` | every 30 s, and after every write |
| Details drawer | `GET /{cronId}` | on open, after a write to that Cron |
| Runs list | `GET /executions/list?result=…&cronId=…&limit=50&offset=…` | on filter change, every 30 s on the first page |
| Run output | `GET /executions/{executionId}` | on open |
| Timeline (heatmap) | `GET /timeline?hours=24` | on window change |
| Create / edit form | `GET /commands`, `GET /schedule-preview?schedule=…` (debounced while typing) | on open |

Every write answers the Cron as it is now (`cron`, the same shape as one `/list` row): replace the row with it instead
of reloading the list.

## 2. Screen layout

1. **Summary tiles** from `runner`: active, paused, running, overdue, failing, missing command, runs and failures of
   the last 24 h, last run start. Banner when `overdueCount` > 0 and every active Cron is overdue: the runner
   (`app:crons:execute` every minute) is not running.
2. **Crons table**, one row per Cron (`crons.elements`, sorted by name): status badge, name, description, schedule
   (with a human reading), command, next run as a countdown against `runner.serverNow` (never the browser clock), last
   run (result, duration, `outputPreview`), a strip of the newest 30 runs (`recentExecutions`, newest first: draw
   right to left), 24 h success rate. Row actions: Run now, On/Off, Edit, Delete.
3. **Details drawer** (`/{cronId}`): the definition, the next 5 slots (`nextRuns`), 14-day stats (`stats14d`), a
   duration chart of the last 100 ended runs (`durationHistory`, oldest first; failed runs in the error colour), the
   runs of this Cron (runs list with `cronId`).
4. **Runs view**: tabs ALL / FAILED / RUNNING / SUCCESSFUL (`result`), paged by `totalCount`; each row opens the full
   output in a monospace panel (`output`; long — virtualise or cap the rendered height). Above it the timeline: one
   lane per Cron, one cell per bucket (`bucketMinutes`), colour by `failureCount` / `runCount`, tooltip with the
   counts and `maxDurationMs`; a lane without buckets is an idle Cron, not a missing one.

Status badges: `MISSING_COMMAND` and `FAILING` red, `OVERDUE` orange, `RUNNING` blue, `PAUSED` grey, `OK` green.

## 3. Forms

- **Command**: a select of `/commands` (`name` with `description`, and "used by …" from `usedByCronNames`); free text
  is not accepted by the API.
- **Arguments**: one line, no quotes; show the composed command line (`commandName arguments`).
- **Schedule**: text field with the live preview (`isValid`, `error`, the next 5 `nextRuns`); offer presets
  (`*/5 * * * *`, `0 * * * *`, `0 3 * * *`).
- **Name**: kebab-case hint; on edit, renaming is allowed. Do not validate an unchanged stored name on the client: a
  Cron named before the rule saves with its old name (only a new name is held to the rule).
- **Description**: 16–255 characters, counter.
- Show field errors from the 400 response's `validationErrors` next to the field they name.
- Edit sends only the changed fields (PATCH); `arguments: ""` clears them.

## 4. Rules the screen must follow

- **Run now queues, it does not run.** After the click show "queued — starts within a minute" and keep refreshing
  `/list`; the run appears as RUNNING, then as the new last run. Never show a run-now as a result.
- **Disable, then explain, a 409.** Run now is offered only for an active Cron that is not RUNNING; Delete not while
  RUNNING; a 409 still possible (the runner started meanwhile) is shown as its message, with a retry after a minute.
- **MISSING_COMMAND** rows: no Run now; Edit to pick a command of this build, or Delete. The row stays: it came from
  another branch or a removed command.
- **Paused Crons** show no next-run countdown.
- **Delete** confirms with the Cron's name and says the run history goes with it.
- **Times**: the API gives the server's time zone (the runner's); label it, and count down against `serverNow`.
- Never offer to schedule arbitrary console commands: the API refuses commands without `#[CronSchedulable]`.

## 5. Checklist

- [ ] Controller mounts `CronsControllerTrait` behind the admin audience, path restricted to operators
- [ ] The runner `app:crons:execute` runs every minute on one host
- [ ] The application's cron-able commands carry `#[CronSchedulable]`
- [ ] Tiles, table, drawer, runs view, timeline, create / edit form
- [ ] Run now shows "queued" and follows the run through `/list`
- [ ] 400 field errors placed on their fields; 409 shown as message
