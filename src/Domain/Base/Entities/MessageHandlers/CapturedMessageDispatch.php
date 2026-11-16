<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One message dispatched while a {@see MessageDispatchCapture} was active: its class and the body a transport would
 * carry; for a message a {@see RecordedRunMessageQueue} ran, also what running it did.
 */
class CapturedMessageDispatch extends ValueObject
{
    /** @var string The message class */
    public string $messageClass = '';

    /** @var string The serialized body ({@see MessageDispatchCapture::serializeMessage()}) */
    public string $body = '';

    /**
     * @var string|null What running the message did ({@see RecordedRunMessageQueue::OUTCOME_HANDLED} …); null where
     *      no outcome applies
     */
    public ?string $outcome = null;

    /**
     * @param string $messageClass
     * @param string $body
     * @param string|null $outcome
     * @return static
     */
    public static function forMessageClass(string $messageClass, string $body, ?string $outcome = null): static
    {
        $capturedMessageDispatch = new static();
        $capturedMessageDispatch->messageClass = $messageClass;
        $capturedMessageDispatch->body = $body;
        $capturedMessageDispatch->outcome = $outcome;
        return $capturedMessageDispatch;
    }
}
