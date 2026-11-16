<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One dispatched message as two runs compare it ({@see MessageDispatchCapture::buildComparableDispatches()}): its class
 * and its body, decoded and normalised (the raw body when it is no JSON object or list).
 */
class ComparableMessageDispatch extends ValueObject
{
    /** @var string The message class */
    public string $messageClass = '';

    /**
     * @var mixed The decoded, normalised body (decoded JSON of no fixed shape); the raw body text when it does not
     *      decode to a JSON object or list. Read through {@see getComparableBody()} or {@see toArray()}
     */
    protected mixed $comparableBody = null;

    /**
     * @param string $messageClass
     * @param mixed $comparableBody
     * @return static
     */
    public static function forMessageClass(string $messageClass, mixed $comparableBody): static
    {
        $comparableMessageDispatch = new static();
        $comparableMessageDispatch->messageClass = $messageClass;
        $comparableMessageDispatch->comparableBody = $comparableBody;
        return $comparableMessageDispatch;
    }

    /**
     * @return mixed The decoded, normalised body — decoded JSON of no fixed shape (whatever the message carries), or
     *         the raw body text when it is no JSON object or list
     */
    public function getComparableBody(): mixed
    {
        return $this->comparableBody;
    }

    /**
     * The stored document of the dispatch: `messageClass`, `body`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['messageClass' => $this->messageClass, 'body' => $this->comparableBody];
    }
}
