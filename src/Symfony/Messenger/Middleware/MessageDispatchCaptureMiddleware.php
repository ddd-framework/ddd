<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Keeps a dispatched message in the active {@see MessageDispatchCapture} instead of sending it, while a recorded or
 * replayed call runs. The envelope is returned unsent and unhandled, so neither a transport nor a `sync://` handler
 * sees it. Without an active capture (every normal run) and for a consumed message (a `ReceivedStamp`), it passes the
 * envelope on untouched — one static read per dispatch.
 *
 * Not registered by the framework: an application that records runs lists it in its bus's middleware
 * (`framework.messenger.buses.<bus>.middleware`), ahead of any middleware that must not see a captured message.
 */
class MessageDispatchCaptureMiddleware implements MiddlewareInterface
{
    /**
     * @param Envelope $envelope
     * @param StackInterface $stack
     * @return Envelope
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $activeCapture = MessageDispatchCapture::$activeCapture;
        if ($activeCapture === null || $envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        $activeCapture->captureEnvelope($envelope);
        return $envelope;
    }
}
