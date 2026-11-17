# Crons admin endpoints — `CronsControllerTrait`

The Crons of an application, operated over its admin API: health, run history, timeline, the schedulable commands,
and the writes. The endpoints are a trait; the application mounts them with one controller of its own, which sets
the path prefix, the tag and the access.

## Contents

1. Mounting
2. Routes
3. Classes
4. Which commands can be scheduled — `#[CronSchedulable]`
5. Write rules
6. Run now
7. Access
8. Errors

## 1. Mounting

```php
use DDD\Presentation\Api\Admin\Common\Crons\CronsControllerTrait;

#[Route('/common/crons')]
#[Tag(group: 'Common', name: 'Crons', description: 'Scheduled console commands, their health and run history')]
class AdminCronsController extends AdminController
{
    use CronsControllerTrait;
}
```

Nothing else: the data comes from `DDD\Domain\Common\Services\CronsManagementService` (public, autowired). The
tables are the existing `Crons` and `CronExecutions`. The runner (`app:crons:execute` every minute) must run, or
nothing is executed — the screen shows that as OVERDUE Crons.

## 2. Routes

Paths relative to the controller's prefix. Both ids accept digits only; the literal routes come before `/{cronId}`.

| Method and path | Request DTO | Response DTO | Purpose |
|---|---|---|---|
| `GET /list` | — | `CronsListGetResponseDto` (`crons`, `runner`) | every Cron with status, newest 30 runs, 24 h stats; the runner summary |
| `GET /executions/list` | `CronExecutionsListGetRequestDto` (`cronId`, `result` ALL / SUCCESSFUL / FAILED / RUNNING, `limit` 1–200 = 50, `offset`) | `CronExecutionsListGetResponseDto` (`executions`, `totalCount`) | one page of runs, newest first |
| `GET /executions/{executionId}` | `CronExecutionGetRequestDto` | `CronExecutionDetailGetResponseDto` (`execution`, `output`) | one run with its full output |
| `GET /timeline` | `CronsTimelineGetRequestDto` (`hours` 1–168 = 24) | `CronsTimelineGetResponseDto` (`from`, `to`, `bucketMinutes`, `lanes`) | runs per Cron per bucket (15 min up to 24 h, 60 above) |
| `GET /commands` | — | `CronSchedulableCommandsGetResponseDto` (`commands`) | the schedulable commands with the Crons that run them |
| `GET /schedule-preview` | `CronSchedulePreviewGetRequestDto` (`schedule`) | `CronSchedulePreviewGetResponseDto` (`isValid`, `error`, `nextRuns`) | an invalid expression is a 200 with `isValid` false |
| `GET /{cronId}` | `CronGetRequestDto` | `CronDetailGetResponseDto` (`cron`, `nextRuns`, `stats14d`, `durationHistory`, `serverNow`) | details panel |
| `POST ''` (the prefix itself) | `CronCreateRequestDto` (`name`, `description`, `schedule`, `commandName`, `arguments`, `isActive`) | `CronGetResponseDto` (`cron`) | create |
| `PATCH /{cronId}` | `CronUpdateRequestDto` (same fields, all optional; `arguments: ""` clears) | `CronGetResponseDto` | partial update |
| `DELETE /{cronId}` | `CronGetRequestDto` | `CronDeleteResponseDto` (`deletedCronId`) | the Cron and its runs |
| `POST /{cronId}/activate` | `CronGetRequestDto` | `CronGetResponseDto` | on; re-anchors the next slot; idempotent |
| `POST /{cronId}/deactivate` | `CronGetRequestDto` | `CronGetResponseDto` | off; idempotent |
| `POST /{cronId}/run-now` | `CronGetRequestDto` | `CronGetResponseDto` | due now: the next tick runs it |

DTOs: `DDD\Presentation\Api\Admin\Common\Crons\Dtos\…`.

## 3. Classes

Domain value objects in `DDD\Domain\Common\Entities\Crons\` (every set serialises as `{"elements": [...]}`):

| Class | Fields |
|---|---|
| `CronOverview` / `CronOverviews` | `id`, `name`, `description`, `schedule`, `command`, `commandName`, `arguments`, `isActive`, `commandExists`, `commandSchedulable`, `status`, `lastExecutionStartedAt`, `nextExecutionScheduledAt`, `created`, `updated`, `lastExecution`, `recentExecutions`, `stats24h` |
| `CronExecutionOverview` / `CronExecutionOverviews` | `id`, `cronId`, `cronName`, `startedAt`, `endedAt`, `durationMs`, `state` (RUNNING / ENDED), `executionState` (SUCCESSFUL / FAILED / null), `outputPreview` (first non-empty line, ≤ 200), `outputLength` |
| `CronRunStatistics` | `runCount`, `failureCount`, `successRate` (0–1, null without runs), `averageDurationMs`, `maxDurationMs` — ended runs only |
| `CronRunnerSummary` | `serverNow`, `lastRunStartedAt`, `activeCount`, `pausedCount`, `runningCount`, `overdueCount`, `failingCount` (by the last ended run, not by status), `missingCommandCount`, `runCount24h`, `failureCount24h` |
| `CronScheduledRuns` | `elements[].scheduledAt` |
| `CronTimelineLanes` → `CronTimelineBuckets` | lane: `cronId`, `cronName`, `buckets`; bucket: `startsAt`, `runCount`, `failureCount`, `maxDurationMs` |
| `CronSchedulableCommands` | `name`, `description`, `usedByCronNames` (string[]) |

**Status** (`CronOverview::resolveStatus()`), first match wins: `MISSING_COMMAND` (the console of this build does
not know the command) → `PAUSED` → `RUNNING` (an execution in state RUNNING, the rule the tick skips by) → `OVERDUE`
(active, slot more than 120 s past: the runner does not tick) → `FAILING` (the last ENDED run failed) → `OK`.

Times are the framework's `DateTime` (`Y-m-d H:i:s`); schedules are evaluated in the PHP default time zone, as the
runner does.

## 4. Which commands can be scheduled — `#[CronSchedulable]`

A Cron runs its command with the full rights of the console. The admin API therefore offers and accepts only
commands marked `DDD\Symfony\Commands\Common\Crons\CronSchedulable` (inherited by subclasses) and the framework's
own cron-shaped commands (`CronsManagementService::FRAMEWORK_SCHEDULABLE_COMMAND_NAMES`: the issues registry job and
health check, `app:workers:sample`). Hidden commands are not offered. The console (`app:crons:upsert`) keeps
accepting every registered command.

```php
#[AsCommand(name: 'app:orders:close-stale', description: 'Closes orders left open for a day')]
#[CronSchedulable]
class CloseStaleOrders extends Command
```

An application that wants another rule extends the service (`App\Domain\Common\Services\CronsManagementService
extends …`; the framework's id then resolves to it) and overrides `describeConsoleCommand()`.

## 5. Write rules

- **Name**: 3–80 lowercase letters, digits and single hyphens (`send-reminders`), unique — checked on create and on a
  rename only, so an existing Cron with an older name keeps working.
- **Arguments**: one line, no quote characters (the runner splits on spaces and runs without a shell), whitespace
  collapsed, no `--loop`, `--env`, `-e`, `--profile`; ≤ 300 characters; command line ≤ 255.
- **Entity validation decides last** (expression, command registered, description 16–255), like `app:crons:upsert`;
  on an update only the changed properties are judged.
- Create is a strict INSERT (a duplicate name is a field error, never an overwrite) and is due at the next slot.
- Updates write only the changed columns (`updatePartialIgnoringRights`): the runner writes `lastExecutionStartedAt`
  and `nextExecutionScheduledAt` of the same row.
- A new schedule re-anchors the next slot to the new expression; it is refused (409) while the Cron runs or while it
  is due and a tick is in progress — that tick would advance the slot by the old expression. Name, description,
  command and switching are not blocked by a run.
- Activate goes through `Cron::activate()` (a Cron paused across its slot does not fire at once); deactivate keeps
  the slot.
- Delete locks the row, refuses a running Cron (409), and removes the runs with it in one transaction.

## 6. Run now

`POST /{cronId}/run-now` never runs the command in the request. It sets `nextExecutionScheduledAt` to now; the next
tick of `app:crons:execute` (within a minute) runs the Cron through `Cron::execute()` like any due Cron: the
execution row is written RUNNING, closed SUCCESSFUL or FAILED with the output, the slot advances, and a failure reaches
the issues log as in every tick. A Cron that is switched off or running answers 409; a command missing from this
build answers 400. The screen shows the run when it appears in `/list` (status RUNNING, then the new last run).

## 7. Access

Like the workers endpoints the trait checks no roles: the application's controller and its firewall decide. Mount it
behind the admin audience and restrict the path (e.g. `access_control` on the prefix) to the accounts that may operate
the system — creating a Cron means choosing a console command that runs on the server.

## 8. Errors

| Status | When |
|---|---|
| 400 | a write rule (`validationErrors` names the field: `name`, `description`, `schedule`, `commandName`, `arguments`); run-now of a command missing from this build; a query value out of range |
| 404 | unknown `cronId` / `executionId` |
| 409 | `DDD\Infrastructure\Exceptions\ConflictException`: run-now of a paused or running Cron, delete of a running Cron, re-schedule while it runs or is about to |

Screen guide: [crons-admin-screen-guide.md](crons-admin-screen-guide.md).
