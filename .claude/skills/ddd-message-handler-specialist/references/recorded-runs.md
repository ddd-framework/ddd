# Recording and replaying a run: dispatch capture, message queue, statement audit

Classes for an application that records a run of its code (a console command, an evaluation) and replays it later
to compare the two runs: what was dispatched, what was written to the database. All of them are plain objects and
statics — no services, nothing to fetch with `getService()`.

## Contents

- [Rules](#rules)
- [Capture the dispatches of a call](#capture-the-dispatches-of-a-call)
- [Run captured messages in the same process](#run-captured-messages-in-the-same-process)
- [Audit the statements that write](#audit-the-statements-that-write)
- [Compare two runs](#compare-two-runs)
- [Where the classes live](#where-the-classes-live)

## Rules

- **Start / stop in try/finally.** `startCapturing()` / `stopCapturing()` and the audit's `start()` / `stop()` are
  pairs; a second start throws `LogicException`. `stopCapturing()` does nothing unless this capture is the active
  one, so a stop never removes another capture.
- **The middleware is opt-in.** `DDD\Symfony\Messenger\Middleware\MessageDispatchCaptureMiddleware` is not
  registered by the framework; an application that records runs lists it in its bus's middleware
  (`framework.messenger.buses.<bus>.middleware`), ahead of middleware that must not see a captured message. Without an
  active capture and for a consumed message (`ReceivedStamp`) it passes the envelope on.
- **The worker-state reset calls the capture's reset LAST.** An application's `WorkerStateResetterInterface` ends with
  `RecordedRunMessageQueue::resetDispatchCaptureForNewJob()`: it clears a capture left by a run and, while a queue
  runs its messages in this process, makes the queue the active capture again and asks its host to set its switches.
  Anything reset after it undoes the host's switches.
- **Nothing is guessed.** Id families, high-water marks, derived-moment columns and keys, durations and clock-text
  prefixes are declared by the caller; everything not declared is compared raw.
- **Callbacks are interfaces.** The object that drives a queue implements `RecordedRunMessageQueueHostInterface` and
  is set on `$queue->host`; a different way to run one message is a subclass overriding `runQueuedEnvelope()`.

## Capture the dispatches of a call

```php
$messageDispatchCapture = new MessageDispatchCapture();
$messageDispatchCapture->startCapturing();
try {
    $ordersService->placeOrder($order);              // every dispatch is kept, never sent
} finally {
    $messageDispatchCapture->stopCapturing();        // the capture active before is back
}
$comparableDispatches = $messageDispatchCapture->buildComparableDispatches(
    ['orderId' => 'order'],                          // body key → id family, at any depth
    ['order' => $highWaterMarksByFamily['order']],   // only ids above the mark become <order#n>
    wallClockNormalization: $wallClockNormalization
);
$storedDispatches = $comparableDispatches->toArray(); // the stored document
```

## Run captured messages in the same process

`RecordedRunMessageQueue` is a capture whose `runPendingMessages()` runs every kept message first in first out, each
as a received message through `messenger.default_bus` (the application's middleware and handler as a worker runs
them); a message dispatched meanwhile joins the end. Per message the host hears the phase that dispatched it
(`applyPhaseOrdinal()`) and restores its state afterwards (`restoreStateAfterQueuedMessage()`). A failing message is
recorded (`buildDispatchedMessagesWithOutcomes()`: `handled`, `failed: …`, `not run: …`, `pending`) and the next one
runs; more than `MAX_MESSAGES_PER_RUN` throws (a dispatch loop). Statics and the phase are put back on every exit.

## Audit the statements that write

```php
$connection = EntityManagerFactory::getInstance()->getConnection();
$highWaterMarksByTable = DatabaseWriteStatementAudit::findIdHighWaterMarksOfAllTables($connection);
$databaseWriteStatementAudit = new DatabaseWriteStatementAudit();
$databaseWriteStatementAudit->start();               // scopes: [EntityManagerFactory::SCOPE_DEFAULT]
try {
    // … the call
} finally {
    $databaseWriteStatementAudit->stop();
}
$statementShapes = $databaseWriteStatementAudit->buildComparableStatements($idFieldFamilies, $highWaterMarksByFamily);
$createdIds = DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(
    $connection,
    $highWaterMarksByTable,
    DatabaseRowScopeColumns::fromValuesByColumn(['ownerId' => [$ownerId]])   // priority order; unscoped tables are reported
);
```

- Hooked as the connection's DBAL `SQLLogger` (chained after one already set); a statement it cannot keep counts in
  `$auditFailureCount`, a connection replaced during the audit sets `$wasInterrupted`.
- Each statement is parsed in its own database's syntax (`SqlDialect::identifierQuoteCharacter()`,
  `upsertConflictClause()`); a statement the parser does not understand is compared by its raw SQL.
- The id reads: MariaDB `information_schema.TABLES.AUTO_INCREMENT`; PostgreSQL identity / serial sequences, a sequence
  never called (also after `setval(…, false)`) takes the table's `MAX(id)`.
- Not seen: transaction calls without SQL, connections not passed to `start()`, writes outside the DBAL.

## Compare two runs

`RecordedSequenceComparison::buildDifferences($label, $recorded, $current)` compares two `RecordedSequence`s position
by position after a count check and returns one line per difference (at most 30). A compared set gives its sequence
with `toRecordedSequence()`; a stored recording with `RecordedSequence::fromStoredDocuments($decodedEntries)`.
`RecordedRunWallClockNormalization` is the one wall-clock rule of all of them: `normalizeStructure()` takes and returns
decoded JSON of any shape, `normalizeJsonText()` JSON text; the window is a `RecordedRunClockWindow`.

## Where the classes live

| Classes | Namespace |
|---|---|
| `MessageDispatchCapture`, `RecordedRunMessageQueue`, `RecordedRunMessageQueueHostInterface`, `CapturedMessageDispatch(es)`, `ComparableMessageDispatch(es)`, `PendingMessageEnvelope(s)` | `DDD\Domain\Base\Entities\MessageHandlers` |
| `MessageDispatchCaptureMiddleware` | `DDD\Symfony\Messenger\Middleware` |
| `DatabaseWriteStatementAudit`, `DatabaseWriteStatement(s)`, `DatabaseWriteStatementShape(s)`, `DatabaseWriteStatementNormalization`, `DatabaseRowScopeColumn(s)`, `DatabaseIdsAboveHighWaterMarks` and their value objects | `DDD\Domain\Base\Repo\DB\Doctrine\Audit` |
| `ComparisonIdOrdinals`, `RecordedSequence`, `RecordedSequenceComparison` | `DDD\Domain\Base\Entities\Comparisons` |
| `RecordedRunWallClockNormalization`, `RecordedRunClockWindow` | `DDD\Domain\Base\Entities\RecordedRuns` |
