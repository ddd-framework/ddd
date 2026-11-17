<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\SourceMapResolverService;
use DDD\Presentation\Base\OpenApi\Attributes\Required;
use Throwable;

/**
 * One logged occurrence of a group, read from the log backend: the payload of `app:issues:show --format=json` mapped
 * to typed fields (message, request, frames with code context, causes, client context and breadcrumbs, worker context).
 */
class IssueEventDetail extends ValueObject
{
    /** @var string|null The event id (event.id) */
    #[Required]
    public ?string $eventId = null;

    /** @var DateTime|null When it was logged */
    #[Required]
    public ?DateTime $timestamp = null;

    /** @var string|null Log level (error, critical, …) */
    #[Required]
    public ?string $level = null;

    /** @var string|null The logged message: Type: message, or Type (no message) at file:line */
    #[Required]
    public ?string $message = null;

    /** @var string|null The error's class, fully qualified (error.type); null for events without one */
    #[Required]
    public ?string $errorType = null;

    /** @var string|null Route name, CLI command or message handler the event happened in */
    #[Required]
    public ?string $route = null;

    /** @var int|null HTTP status of the response, for HTTP events */
    #[Required]
    public ?int $httpStatus = null;

    /** @var string|null HTTP method of the request (GET, POST, …), for HTTP events */
    #[Required]
    public ?string $httpMethod = null;

    /** @var IssueEventKeyValues The matched route's parameters (orderId, …), for HTTP events; empty otherwise */
    #[Required]
    public IssueEventKeyValues $routeParameters;

    /** @var string|null The console command, for CLI events */
    #[Required]
    public ?string $command = null;

    /** @var string|null The release (service.version) that logged the event */
    #[Required]
    public ?string $release = null;

    /** @var string|null The client build's commit (the client tag commit); null for backend events */
    #[Required]
    public ?string $commit = null;

    /** @var string|null The full stack trace */
    #[Required]
    public ?string $stackTrace = null;

    // deliberately not #[Required]: a generated client drops the nullability of a required class reference
    /** @var IssueEventClientContext|null Who reported it, for client events (sections WEB, WEB_SERVER, MOBILE); absent otherwise */
    public ?IssueEventClientContext $client = null;

    /** @var IssueEventBreadcrumbs What happened in the client before the error, oldest first; empty for backend events */
    #[Required]
    public IssueEventBreadcrumbs $breadcrumbs;

    /** @var IssueEventFrames The structured stack frames of a client event, innermost first; empty for backend events */
    #[Required]
    public IssueEventFrames $frames;

    /** @var IssueEventFrames The stack frames of a backend event (error.frames), innermost first, frame 0 = the throw site; empty for client events and events logged before the field existed */
    #[Required]
    public IssueEventFrames $backendFrames;

    /** @var IssueEventCauses The errors this one wraps (getPrevious chain), outermost cause first; empty when none */
    #[Required]
    public IssueEventCauses $causes;

    // deliberately not #[Required], like client
    /** @var IssueEventWorkerContext|null The failed worker message, for MESSENGER events; absent otherwise */
    public ?IssueEventWorkerContext $worker = null;

    /** @var string|null The stack trace with original positions, once a source map for the release resolved it; null otherwise */
    #[Required]
    public ?string $resolvedStackTrace = null;

    /** @var string|null Why the frames could not be resolved although a source-map store is configured; null otherwise */
    #[Required]
    public ?string $resolveError = null;

    public function __construct()
    {
        $this->breadcrumbs = new IssueEventBreadcrumbs();
        $this->frames = new IssueEventFrames();
        $this->backendFrames = new IssueEventFrames();
        $this->causes = new IssueEventCauses();
        $this->routeParameters = new IssueEventKeyValues();
        parent::__construct();
    }

    /**
     * The event from one payload of `app:issues:show --format=json` (the raw ECS line as an array, dotted or nested
     * keys); the stack trace is cut at `$maxStackTraceBytes` (the issues log's event cap). Client events (sections
     * WEB, WEB_SERVER, MOBILE) also carry their client context, breadcrumbs and structured frames; backend events their
     * frames, causes, route parameters, command and, for a failed worker message, the worker context. Every field is
     * optional: an event logged before a field existed maps with it empty.
     *
     * @param array<string, mixed> $eventPayload
     */
    public static function fromEventPayload(array $eventPayload, int $maxStackTraceBytes): static
    {
        $issueEventDetail = new static();
        $issueEventDetail->eventId = self::readEventField($eventPayload, 'event.id');
        $timestamp = self::readEventField($eventPayload, '@timestamp');
        $issueEventDetail->timestamp = self::parseEventTimestamp($timestamp);
        $issueEventDetail->level = self::readEventField($eventPayload, 'log.level');
        $issueEventDetail->message = self::readEventField($eventPayload, 'message');
        $issueEventDetail->route = self::readEventField($eventPayload, 'route_name')
            ?? self::readEventField($eventPayload, 'error.entry_point');
        $httpStatus = self::readEventField($eventPayload, 'http_status')
            ?? self::readEventField($eventPayload, 'http.response.status_code');
        $issueEventDetail->httpStatus = $httpStatus !== null && is_numeric($httpStatus) ? (int)$httpStatus : null;
        $issueEventDetail->errorType = self::readEventField($eventPayload, 'error.type');
        $issueEventDetail->httpMethod = self::readEventField($eventPayload, 'http.request.method');
        $issueEventDetail->routeParameters = self::buildKeyValuesFromMap(self::readEventValue($eventPayload, 'route_parameters'));
        $issueEventDetail->command = self::readEventField($eventPayload, 'command');
        $issueEventDetail->release = self::readEventField($eventPayload, 'service.version');
        $issueEventDetail->commit = self::readClientTagFromEventPayload($eventPayload, 'commit');
        $issueEventDetail->backendFrames = self::buildFramesFromFramePayloads(self::readEventList($eventPayload, 'error.frames'));
        $issueEventDetail->causes = self::buildCausesFromEventPayload($eventPayload);
        if (self::readEventField($eventPayload, 'messenger.message_class') !== null) {
            $issueEventDetail->worker = self::buildWorkerContextFromEventPayload($eventPayload);
        }
        $stackTrace = self::readEventField($eventPayload, 'error.stack_trace');
        $issueEventDetail->stackTrace = $stackTrace !== null && strlen($stackTrace) > $maxStackTraceBytes
            ? mb_strcut($stackTrace, 0, max(0, $maxStackTraceBytes))
            : $stackTrace;
        if (in_array(self::readEventField($eventPayload, 'section'), IssuesLogService::CLIENT_SECTIONS, true)) {
            $issueEventDetail->client = self::buildClientContextFromEventPayload($eventPayload);
            $issueEventDetail->breadcrumbs = self::buildBreadcrumbsFromEventPayload($eventPayload);
            $issueEventDetail->frames = self::buildFramesFromEventPayload($eventPayload);
            $issueEventDetail->applyResolvedFramesFromEventPayload($eventPayload);
        }
        return $issueEventDetail;
    }

    /**
     * The source-map resolution `app:issues:show --resolve` added to a client event (`resolvedFrames`, one per frame in
     * frame order, and `resolvedStackTrace`). `resolvedStackTrace` is set only when at least one frame was resolved (the
     * drawer's "Original" view), `resolveError` only when the store is configured and failed: a store that is not
     * configured or a release without maps is not an error.
     *
     * @param array<string, mixed> $eventPayload
     */
    protected function applyResolvedFramesFromEventPayload(array $eventPayload): void
    {
        $resolvedFramePayloads = $eventPayload['resolvedFrames'] ?? null;
        if (!is_array($resolvedFramePayloads) || !array_is_list($resolvedFramePayloads) || $resolvedFramePayloads === []) {
            return;
        }
        $issueEventFrames = new IssueEventFrames();
        $anyFrameIsResolved = false;
        foreach ($resolvedFramePayloads as $resolvedFramePayload) {
            if (!is_array($resolvedFramePayload)) {
                continue;
            }
            $issueEventFrame = new IssueEventFrame();
            $issueEventFrame->file = self::readScalarAsString($resolvedFramePayload['file'] ?? null);
            $issueEventFrame->line = self::readScalarAsInt($resolvedFramePayload['line'] ?? null);
            $issueEventFrame->column = self::readScalarAsInt($resolvedFramePayload['column'] ?? null);
            $issueEventFrame->function = self::readScalarAsString($resolvedFramePayload['function'] ?? null);
            $issueEventFrame->inApp = (bool)($resolvedFramePayload['inApp'] ?? false);
            $issueEventFrame->resolved = (bool)($resolvedFramePayload['resolved'] ?? false);
            if ($issueEventFrame->resolved) {
                $anyFrameIsResolved = true;
                $issueEventFrame->resolvedFile = self::readScalarAsString($resolvedFramePayload['originalFile'] ?? null);
                $issueEventFrame->resolvedLine = self::readScalarAsInt($resolvedFramePayload['originalLine'] ?? null);
                $issueEventFrame->resolvedColumn = self::readScalarAsInt($resolvedFramePayload['originalColumn'] ?? null);
                $issueEventFrame->resolvedFunction = self::readScalarAsString($resolvedFramePayload['originalFunction'] ?? null);
                $contextLines = $resolvedFramePayload['contextLines'] ?? [];
                $issueEventFrame->contextLines = is_array($contextLines)
                    ? array_values(array_map(static fn (mixed $contextLine): string => (string)$contextLine, array_filter($contextLines, 'is_scalar')))
                    : [];
                $issueEventFrame->contextStartLine = self::readScalarAsInt($resolvedFramePayload['contextStartLine'] ?? null);
            }
            $note = self::readScalarAsString($resolvedFramePayload['note'] ?? null);
            if ($note === SourceMapResolverService::NOTE_STORE_ERROR) {
                $this->resolveError = 'The source maps of this release could not be read from the store.';
            }
            $issueEventFrames->add($issueEventFrame);
        }
        $this->frames = $issueEventFrames;
        $resolvedStackTrace = $eventPayload['resolvedStackTrace'] ?? null;
        $this->resolvedStackTrace = $anyFrameIsResolved && is_string($resolvedStackTrace) ? $resolvedStackTrace : null;
    }

    /**
     * The client context of a client line (`client.*`, `user_agent.*`, `url.original`, `route_name`)
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function buildClientContextFromEventPayload(array $eventPayload): IssueEventClientContext
    {
        $issueEventClientContext = new IssueEventClientContext();
        $issueEventClientContext->runtime = self::readEventField($eventPayload, 'client.runtime');
        $issueEventClientContext->app = self::readEventField($eventPayload, 'client.app');
        $issueEventClientContext->release = self::readEventField($eventPayload, 'service.version');
        $issueEventClientContext->browserName = self::readEventField($eventPayload, 'user_agent.name');
        $issueEventClientContext->browserVersion = self::readEventField($eventPayload, 'user_agent.version');
        $issueEventClientContext->osName = self::readEventField($eventPayload, 'user_agent.os.name');
        $viewport = self::readEventField($eventPayload, 'client.viewport');
        if ($viewport !== null && preg_match('/^(\d+)x(\d+)$/', $viewport, $viewportMatches) === 1) {
            $issueEventClientContext->viewportWidth = (int)$viewportMatches[1];
            $issueEventClientContext->viewportHeight = (int)$viewportMatches[2];
        }
        $issueEventClientContext->tabId = self::readEventField($eventPayload, 'client.tab_id');
        $issueEventClientContext->routePattern = self::readEventField($eventPayload, 'route_name');
        $issueEventClientContext->url = self::readEventField($eventPayload, 'url.original');
        return $issueEventClientContext;
    }

    /**
     * The breadcrumbs of a client line (`client.breadcrumbs`, already redacted by Core)
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function buildBreadcrumbsFromEventPayload(array $eventPayload): IssueEventBreadcrumbs
    {
        $issueEventBreadcrumbs = new IssueEventBreadcrumbs();
        foreach (self::readEventList($eventPayload, 'client.breadcrumbs') as $breadcrumbPayload) {
            if (!is_array($breadcrumbPayload)) {
                continue;
            }
            $issueEventBreadcrumb = new IssueEventBreadcrumb();
            $issueEventBreadcrumb->at = self::readScalarAsString($breadcrumbPayload['at'] ?? null);
            $issueEventBreadcrumb->category = self::readScalarAsString($breadcrumbPayload['category'] ?? null);
            $issueEventBreadcrumb->message = self::readScalarAsString($breadcrumbPayload['message'] ?? null);
            $issueEventBreadcrumb->url = self::readScalarAsString($breadcrumbPayload['url'] ?? null);
            $issueEventBreadcrumb->from = self::readScalarAsString($breadcrumbPayload['from'] ?? null);
            $issueEventBreadcrumb->to = self::readScalarAsString($breadcrumbPayload['to'] ?? null);
            $issueEventBreadcrumb->status = self::readScalarAsInt($breadcrumbPayload['status'] ?? null);
            $issueEventBreadcrumbs->add($issueEventBreadcrumb);
        }
        return $issueEventBreadcrumbs;
    }

    /**
     * The structured frames of a client line (`client.frames`), unresolved; {@see self::applyResolvedFramesFromEventPayload()}
     * fills the resolved* fields
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function buildFramesFromEventPayload(array $eventPayload): IssueEventFrames
    {
        $issueEventFrames = new IssueEventFrames();
        foreach (self::readEventList($eventPayload, 'client.frames') as $framePayload) {
            if (!is_array($framePayload)) {
                continue;
            }
            $issueEventFrame = new IssueEventFrame();
            $issueEventFrame->file = self::readScalarAsString($framePayload['file'] ?? null);
            $issueEventFrame->line = self::readScalarAsInt($framePayload['line'] ?? null);
            $issueEventFrame->column = self::readScalarAsInt($framePayload['column'] ?? null);
            $issueEventFrame->function = self::readScalarAsString($framePayload['function'] ?? null);
            $issueEventFrame->inApp = (bool)($framePayload['in_app'] ?? false);
            $issueEventFrames->add($issueEventFrame);
        }
        return $issueEventFrames;
    }

    /**
     * Backend frames (`error.frames`, a cause's `frames`): `{file, line, function, in_app, code_context?: {start_line,
     * lines}}` each, innermost first
     *
     * @param mixed[] $framePayloads
     */
    protected static function buildFramesFromFramePayloads(array $framePayloads): IssueEventFrames
    {
        $issueEventFrames = new IssueEventFrames();
        foreach ($framePayloads as $framePayload) {
            if (!is_array($framePayload)) {
                continue;
            }
            $issueEventFrame = new IssueEventFrame();
            $issueEventFrame->file = self::readScalarAsString($framePayload['file'] ?? null);
            $issueEventFrame->line = self::readScalarAsInt($framePayload['line'] ?? null);
            $issueEventFrame->function = self::readScalarAsString($framePayload['function'] ?? null);
            $issueEventFrame->inApp = (bool)($framePayload['in_app'] ?? false);
            $codeContext = $framePayload['code_context'] ?? null;
            if (is_array($codeContext) && is_array($codeContext['lines'] ?? null)) {
                $issueEventFrame->codeContextStartLine = self::readScalarAsInt($codeContext['start_line'] ?? null);
                $issueEventFrame->codeContextLines = array_values(array_map(
                    static fn (mixed $codeContextLine): string => (string)$codeContextLine,
                    array_filter($codeContext['lines'], 'is_scalar')
                ));
            }
            $issueEventFrames->add($issueEventFrame);
        }
        return $issueEventFrames;
    }

    /**
     * The cause chain of a backend line (`error.causes`: `{type, message, file, frames}` each, outermost first)
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function buildCausesFromEventPayload(array $eventPayload): IssueEventCauses
    {
        $issueEventCauses = new IssueEventCauses();
        foreach (self::readEventList($eventPayload, 'error.causes') as $causePayload) {
            if (!is_array($causePayload)) {
                continue;
            }
            $issueEventCause = new IssueEventCause();
            $issueEventCause->type = self::readScalarAsString($causePayload['type'] ?? null);
            $issueEventCause->message = self::readScalarAsString($causePayload['message'] ?? null);
            $issueEventCause->file = self::readScalarAsString($causePayload['file'] ?? null);
            $causeFramePayloads = $causePayload['frames'] ?? null;
            $issueEventCause->frames = self::buildFramesFromFramePayloads(
                is_array($causeFramePayloads) && array_is_list($causeFramePayloads) ? $causeFramePayloads : []
            );
            $issueEventCauses->add($issueEventCause);
        }
        return $issueEventCauses;
    }

    /**
     * The worker context of a MESSENGER line (`messenger.*`, `transports`, `will_retry`). An event logged before attempt /
     * final existed has none: they are derived from `messenger.retry_count` and `will_retry` when those exist.
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function buildWorkerContextFromEventPayload(array $eventPayload): IssueEventWorkerContext
    {
        $issueEventWorkerContext = new IssueEventWorkerContext();
        $issueEventWorkerContext->messageClass = self::readEventField($eventPayload, 'messenger.message_class');
        $issueEventWorkerContext->handler = self::readEventField($eventPayload, 'messenger.handler');
        $issueEventWorkerContext->messageKeys = self::buildKeyValuesFromMap(self::readEventValue($eventPayload, 'messenger.message_keys'));
        $issueEventWorkerContext->retryCount = self::readScalarAsInt(self::readEventValue($eventPayload, 'messenger.retry_count'));
        $issueEventWorkerContext->attempt = self::readScalarAsInt(self::readEventValue($eventPayload, 'messenger.attempt'))
            ?? ($issueEventWorkerContext->retryCount !== null ? $issueEventWorkerContext->retryCount + 1 : null);
        $final = self::readScalarAsBool(self::readEventValue($eventPayload, 'messenger.final'));
        $willRetry = self::readScalarAsBool(self::readEventValue($eventPayload, 'will_retry'));
        $issueEventWorkerContext->final = $final ?? ($willRetry !== null ? !$willRetry : null);
        $transports = self::readEventValue($eventPayload, 'transports');
        $issueEventWorkerContext->transports = is_array($transports)
            ? array_values(array_map(static fn (mixed $transport): string => (string)$transport, array_filter($transports, 'is_scalar')))
            : (is_string($transports) ? [$transports] : []);
        $issueEventWorkerContext->ageSeconds = self::readScalarAsInt(self::readEventValue($eventPayload, 'messenger.age_seconds'));

        $issueEventMessageOrigin = new IssueEventMessageOrigin();
        $issueEventMessageOrigin->route = self::readEventField($eventPayload, 'messenger.origin.route');
        $issueEventMessageOrigin->command = self::readEventField($eventPayload, 'messenger.origin.command');
        $issueEventMessageOrigin->accountId = self::readScalarAsInt(self::readEventValue($eventPayload, 'messenger.origin.account_id'));
        $issueEventMessageOrigin->workspace = self::readEventField($eventPayload, 'messenger.origin.workspace');
        $issueEventMessageOrigin->dispatchedAt = self::parseEventTimestamp(self::readEventField($eventPayload, 'messenger.origin.dispatched_at'));
        if ($issueEventMessageOrigin->hasAnyOriginField()) {
            $issueEventWorkerContext->origin = $issueEventMessageOrigin;
        }
        return $issueEventWorkerContext;
    }

    /**
     * A map field of a line (`route_parameters`, `messenger.message_keys`) as a key/value list in the logged order;
     * non-scalar values are skipped, booleans read true / false
     */
    protected static function buildKeyValuesFromMap(mixed $map): IssueEventKeyValues
    {
        $issueEventKeyValues = new IssueEventKeyValues();
        if (!is_array($map)) {
            return $issueEventKeyValues;
        }
        foreach ($map as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                continue;
            }
            $issueEventKeyValue = new IssueEventKeyValue();
            $issueEventKeyValue->key = (string)$key;
            $issueEventKeyValue->value = is_bool($value) ? ($value ? 'true' : 'false') : self::readScalarAsString($value);
            $issueEventKeyValues->add($issueEventKeyValue);
        }
        return $issueEventKeyValues;
    }

    /**
     * One tag of a client line (`client.tags`, a map; a list of `{key, value}` is read too); null when absent
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function readClientTagFromEventPayload(array $eventPayload, string $tagKey): ?string
    {
        $tags = self::readEventValue($eventPayload, 'client.tags');
        if (!is_array($tags)) {
            return null;
        }
        if (!array_is_list($tags)) {
            return self::readScalarAsString($tags[$tagKey] ?? null);
        }
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag['key'] ?? null) === $tagKey) {
                return self::readScalarAsString($tag['value'] ?? null);
            }
        }
        return null;
    }

    /**
     * A field of an ECS line, any type: the flat dotted key (`log.level`) or the nested shape (`{"log": {"level": …}}`).
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function readEventValue(array $eventPayload, string $key): mixed
    {
        $value = $eventPayload[$key] ?? null;
        if ($value === null && str_contains($key, '.')) {
            $value = $eventPayload;
            foreach (explode('.', $key) as $keyPart) {
                $value = is_array($value) ? ($value[$keyPart] ?? null) : null;
            }
        }
        return $value;
    }

    /**
     * A scalar field of an ECS line: the flat dotted key (`log.level`) or the nested shape (`{"log": {"level": …}}`).
     *
     * @param array<string, mixed> $eventPayload
     */
    protected static function readEventField(array $eventPayload, string $key): ?string
    {
        return self::readScalarAsString(self::readEventValue($eventPayload, $key));
    }

    /**
     * A list field of an ECS line (`client.frames`, `client.breadcrumbs`); empty when absent or not a list
     *
     * @param array<string, mixed> $eventPayload
     * @return mixed[]
     */
    protected static function readEventList(array $eventPayload, string $key): array
    {
        $value = self::readEventValue($eventPayload, $key);
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    protected static function readScalarAsString(mixed $value): ?string
    {
        return is_scalar($value) ? (string)$value : null;
    }

    protected static function readScalarAsInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /** A boolean as JSON, or as the text / number a log shipper may have turned it into; null when absent or unreadable */
    protected static function readScalarAsBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            return match (strtolower((string)$value)) {
                '1', 'true' => true,
                '0', 'false', '' => false,
                default => null,
            };
        }
        return null;
    }

    /** `@timestamp` of an ECS line (ISO 8601 with fraction and offset); null when absent or unreadable */
    protected static function parseEventTimestamp(?string $timestamp): ?DateTime
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }
        try {
            $parsedTimestamp = DateTime::fromString($timestamp);
            if ($parsedTimestamp instanceof DateTime) {
                return $parsedTimestamp;
            }
        } catch (Throwable) {
            // falls through to strtotime(), which reads every ISO 8601 variant the collectors write
        }
        $unixTimestamp = strtotime($timestamp);
        return $unixTimestamp !== false ? DateTime::fromTimestamp($unixTimestamp) : null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->eventId ?? (string)spl_object_id($this));
    }
}
