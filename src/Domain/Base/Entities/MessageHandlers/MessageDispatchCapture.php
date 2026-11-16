<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\Comparisons\ComparisonIdOrdinals;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * The Messenger dispatches of a recorded or replayed call: while a capture is active ({@see startCapturing()} …
 * {@see stopCapturing()}), every message dispatched on the bus is KEPT here — message class and serialized body, in
 * dispatch order — and never sent or handled ({@see \DDD\Symfony\Messenger\Middleware\MessageDispatchCaptureMiddleware}
 * stops it before the transports). A consumed message (a worker receiving one) is never captured.
 *
 * Use it as a pair in try/finally: `$capture->startCapturing(); try { … } finally { $capture->stopCapturing(); }` —
 * the stop puts back the capture that was active before the start.
 *
 * {@see buildComparableDispatches()} turns the capture into the normalised list two runs are compared by: a declared
 * id key of a body becomes an ordinal only when its id was MINTED during the call (above its family's high-water mark,
 * {@see ComparisonIdOrdinals}) — a pre-existing id stays raw; the declared ignored keys are dropped; with a wall-clock
 * rule ({@see RecordedRunWallClockNormalization}, the one the statement audit applies) the moments the run's wall clock
 * wrote, the declared derived moments and the declared durations read as placeholders at any depth (a message body
 * serialises whole entity graphs with their change-history stamps); everything else is compared raw.
 */
class MessageDispatchCapture
{
    /** Body keys that only say where a message was dispatched from (the workspace), never what it carries. */
    public const array DEFAULT_IGNORED_BODY_KEYS = ['dispatchedFromWorkspaceDir', 'dispatchedFromEnvironment'];

    /** The capture of this moment; null outside a recorded or replayed call (every normal run). */
    public static ?MessageDispatchCapture $activeCapture = null;

    /** @var CapturedMessageDispatches The dispatched messages in dispatch order */
    public CapturedMessageDispatches $capturedDispatches;

    /** The capture that was active when {@see startCapturing()} ran, put back by {@see stopCapturing()}. */
    protected ?MessageDispatchCapture $captureActiveBeforeStart = null;

    /** Whether {@see startCapturing()} ran and {@see stopCapturing()} did not yet. */
    protected bool $isCapturing = false;

    public function __construct()
    {
        $this->capturedDispatches = new CapturedMessageDispatches();
    }

    /**
     * Makes this capture the active one; {@see stopCapturing()} in a `finally` puts the previous one back.
     *
     * @return void
     * @throws LogicException When this capture is already started
     */
    public function startCapturing(): void
    {
        if ($this->isCapturing) {
            throw new LogicException('MessageDispatchCapture: this capture is already started');
        }
        $this->captureActiveBeforeStart = self::$activeCapture;
        self::$activeCapture = $this;
        $this->isCapturing = true;
    }

    /**
     * Puts back the capture that was active when {@see startCapturing()} ran. Does nothing unless this capture is the
     * active one: a stop without a start, or after another capture took over, leaves that capture alone.
     *
     * @return void
     */
    public function stopCapturing(): void
    {
        if (self::$activeCapture !== $this) {
            return;
        }
        self::$activeCapture = $this->captureActiveBeforeStart;
        $this->captureActiveBeforeStart = null;
        $this->isCapturing = false;
    }

    /**
     * Keeps one dispatched envelope's message. Never throws: a message it cannot serialize is kept with an error body.
     *
     * @param Envelope $envelope
     * @return void
     */
    public function captureEnvelope(Envelope $envelope): void
    {
        $message = $envelope->getMessage();
        try {
            $body = static::serializeMessage($message);
        } catch (Throwable $throwable) {
            $body = '{"captureError":' . json_encode($throwable->getMessage()) . '}';
        }
        $capturedMessageDispatch = CapturedMessageDispatch::forMessageClass($message::class, $body);
        $this->capturedDispatches->add($capturedMessageDispatch);
    }

    /**
     * The body a transport would carry: an AppMessage's own JSON, else the public properties as JSON. Serialized with
     * the display hides ignored: code that ran earlier in the process may hide `objectType` for every object
     * (`Entity::addStaticPropertiesToHide(false, 'objectType')`, a process-static registry), so the same message would
     * otherwise carry `objectType` or not depending on what ran before it.
     *
     * @param object $message
     * @return string
     */
    public static function serializeMessage(object $message): string
    {
        if ($message instanceof AppMessage) {
            return (string)$message->toJSON(ignoreHideAttributes: true);
        }
        return (string)json_encode(get_object_vars($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The captured dispatches as the list two runs are compared by, in dispatch order.
     *
     * @param array<string, string> $idKeyFamilies Body key → id family, e.g. `['orderId' => 'order']`; the key matches
     *        at any depth of the body
     * @param array<string, int> $highWaterMarksByFamily Id family → the highest id of its table at call start; only ids
     *        above it are ordinals, a family without a mark stays raw
     * @param string[] $ignoredBodyKeys Body keys dropped before the comparison, at any depth
     * @param RecordedRunWallClockNormalization|null $wallClockNormalization The run's wall-clock rule; null: moments raw
     * @return ComparableMessageDispatches
     */
    public function buildComparableDispatches(array $idKeyFamilies = [], array $highWaterMarksByFamily = [], array $ignoredBodyKeys = self::DEFAULT_IGNORED_BODY_KEYS, ?RecordedRunWallClockNormalization $wallClockNormalization = null): ComparableMessageDispatches
    {
        $comparisonIdOrdinals = ComparisonIdOrdinals::withHighWaterMarks($highWaterMarksByFamily);
        $comparableMessageDispatches = new ComparableMessageDispatches();
        foreach ($this->capturedDispatches->getElements() as $capturedMessageDispatch) {
            $decodedBody = json_decode($capturedMessageDispatch->body, true);
            $comparableBody = $capturedMessageDispatch->body;
            if (is_array($decodedBody)) {
                $comparableBody = static::normalizeBody($decodedBody, $idKeyFamilies, $ignoredBodyKeys, $comparisonIdOrdinals);
                if ($wallClockNormalization !== null) {
                    $comparableBody = $wallClockNormalization->normalizeStructure($comparableBody);
                }
            }
            $comparableMessageDispatch = ComparableMessageDispatch::forMessageClass($capturedMessageDispatch->messageClass, $comparableBody);
            $comparableMessageDispatches->add($comparableMessageDispatch);
        }
        return $comparableMessageDispatches;
    }

    /**
     * A decoded body with the ignored keys dropped and the declared id keys normalised, at any depth.
     *
     * @param array<int|string, mixed> $body A decoded JSON body (no fixed keys)
     * @param array<string, string> $idKeyFamilies
     * @param string[] $ignoredBodyKeys
     * @param ComparisonIdOrdinals $comparisonIdOrdinals
     * @return array<int|string, mixed>
     */
    protected static function normalizeBody(array $body, array $idKeyFamilies, array $ignoredBodyKeys, ComparisonIdOrdinals $comparisonIdOrdinals): array
    {
        foreach ($body as $bodyKey => $bodyValue) {
            if (is_string($bodyKey) && in_array($bodyKey, $ignoredBodyKeys, true)) {
                unset($body[$bodyKey]);
                continue;
            }
            if (is_array($bodyValue)) {
                $body[$bodyKey] = static::normalizeBody($bodyValue, $idKeyFamilies, $ignoredBodyKeys, $comparisonIdOrdinals);
                continue;
            }
            if (is_string($bodyKey) && isset($idKeyFamilies[$bodyKey]) && (is_int($bodyValue) || is_string($bodyValue))) {
                $body[$bodyKey] = $comparisonIdOrdinals->normalizeId($idKeyFamilies[$bodyKey], $bodyValue);
            }
        }
        return $body;
    }
}
