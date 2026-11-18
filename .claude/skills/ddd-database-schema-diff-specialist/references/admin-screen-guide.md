# Schema diff admin screen

How to build the schema-diff screen of an admin interface on the endpoints of `DatabaseModelsControllerTrait`. Paths
are relative to the prefix of the application's controller (e.g. `/api/admin/common/databaseModels`).

## Contents

1. Endpoints
2. Payload
3. Screen layout
4. Apply flow and errors
5. Rules the screen must follow
6. Checklist

## 1. Endpoints

| Method and path | Query / body | Response |
|---|---|---|
| `GET /diff` | — | `diffs` |
| `POST /applyDiff` | `{"sqlTableName": "Orders", "expectedDiffSignature": "<diffSignature>", "disableForeignKeyChecks": true}` | `diffs` (recomputed after the apply) |
| `POST /applyDiffs` | `{"sqlTableNames": ["Orders", "Accounts"] \| null, "expectedDiffSignatures": {"elements": [{"sqlTableName", "signature"}]}, "disableForeignKeyChecks": true}` | `diffs` (recomputed) |
| `GET /generateDatabaseTables` | — | text/plain: the SQL of every table |
| `GET /generateDoctrineModels` | — | text/plain: the model code of every DB entity |
| `GET /generateForEntity` | `entityName` (short or fully qualified) | text/plain: one entity's model code |

- Every apply answers the whole recomputed diff: replace the screen's state with it, no follow-up GET.
- `sqlTableNames: null` applies every pending diff; an empty list is a 400.
- The text routes are tools for a developer (copy, download); they never execute or write anything.
- A process that shares another stage's database answers 403 on both applies (the message is the application's
  `getSchemaWritesDisabledMessage()`): show the message, hide the apply actions. The diff and the text routes stay
  available there.

## 2. Payload

`diffs.elements[]` — one `DBTableDiff` per table with a pending change (wire shape: SKILL.md › DBTableDiff Wire Shape):
`sqlTableName`, `entityClassWithNamespace`, `changeType` (`CREATE_TABLE` / `DROP_TABLE` / `ALTER_TABLE`), `severity`
(`ADDITIVE` / `DESTRUCTIVE` / `MIXED`), `columnDiffs`, `virtualColumnDiffs`, `indexDiffs`, `foreignKeyDiffs`,
`triggerDiffs`, `collationChange`, `sql` (display), `sqlStatements.elements[].sql` (execution order), `diffSignature`,
`tableSizeMb`, `tableRowCount`, `directApplyBlocked`, `directApplyBlockReason`, `copyForcingOperations.elements[].description`.

## 3. Screen layout

- **Header:** total and counts per severity, "Apply all" (confirm always; name the number of destructive and mixed
  diffs), a link to download the full SQL.
- **List:** one card per table: table name, entity class, change-type badge, severity badge, size badge
  (`374 MB · 290,067 rows`), summary (`+2 ±1 -3 columns · -1 index · …`), Apply button. Expanded: the per-aspect
  diffs (`changedAttributes` of a MODIFY), and `sqlStatements` in a `<pre>` block.
- **Blocked card** (`directApplyBlocked`): red border, an "Apply blocked" badge, NO Apply button, a banner with the
  table's size and rows against the thresholds (100 MB / 100,000 rows), the `copyForcingOperations` as bullets and one
  line on the online-schema-change path. Never mention a bypass.
- **Empty state:** "Schema matches" when `diffs` is empty.
- **Selection:** checkboxes for a partial "Apply selected" (`applyDiffs` with `sqlTableNames`), blocked cards not
  selectable.

## 4. Apply flow and errors

1. Keep `diffSignature` of every rendered card; send it with every apply (`expectedDiffSignature`, or
   `expectedDiffSignatures` for all rendered cards — the endpoint narrows them to `sqlTableNames`).
2. Destructive or mixed card: confirm dialog naming the table and that data is dropped or rewritten.
3. Replace the list with the response's `diffs`.

| Answer | Meaning | Screen |
|---|---|---|
| 400 starting `[DIFF_SIGNATURE_MISMATCH]` | the diff changed or was applied since it was shown (other tab, other admin, entity change) | banner "The schema changed since you loaded it", reload the diff |
| 400 starting `[DIFF_APPLY_LOCK_BUSY]` | another apply is running | "Another apply is running — retry in a few seconds" |
| 400 other | the production guard refused (large table), or the table list was invalid | show the message |
| 403 | schema writes disabled on this process | show the message, hide applies |
| 404 (`applyDiff` without signature) | the table has no pending diff | reload |

## 5. Rules the screen must follow

- Always send the signatures; a screen never applies what it did not show.
- Never offer an Apply action on a blocked card — not disabled, absent.
- Never render the blocked reason only as a tooltip.
- Before applying a tightening change (NOT NULL, UNIQUE, narrower column, new FK), the operator decides which side is
  wrong (SKILL.md › Reason Before You Apply); the card says so for destructive diffs.
- The screen is for the highest admin role only; the endpoints carry no role check of their own.

## 6. Checklist

- [ ] Controller with `DatabaseModelsControllerTrait` mounted, path restricted to the highest admin role
- [ ] OpenAPI lists the six paths; SDK regenerated
- [ ] Signatures captured and sent on every apply
- [ ] Blocked cards without Apply, with size, thresholds and operations
- [ ] Confirm on destructive / mixed and on "Apply all"
- [ ] Mismatch, lock-busy, 403 handled as above
- [ ] State replaced from the apply response
