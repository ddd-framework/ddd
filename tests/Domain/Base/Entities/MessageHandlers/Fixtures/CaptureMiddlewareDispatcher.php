<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures;

use DDD\Symfony\Messenger\Middleware\MessageDispatchCaptureMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

/** Dispatches one envelope through the capture middleware the way the bus does. */
class CaptureMiddlewareDispatcher
{
    /**
     * @return bool Whether the envelope reached the next middleware (the transports)
     */
    public static function dispatchThroughCaptureMiddleware(Envelope $envelope): bool
    {
        $forwardedEnvelopeCounter = new ForwardedEnvelopeCounter();
        // a fresh stack per dispatch: a stack is consumed by one dispatch
        (new MessageDispatchCaptureMiddleware())->handle($envelope, new StackMiddleware($forwardedEnvelopeCounter));
        return $forwardedEnvelopeCounter->forwardedEnvelopeCount > 0;
    }
}
