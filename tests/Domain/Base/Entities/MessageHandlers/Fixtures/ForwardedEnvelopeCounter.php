<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/** The next middleware of a bus stack (the send and handle middlewares of a real bus): counts what reaches it. */
class ForwardedEnvelopeCounter implements MiddlewareInterface
{
    public int $forwardedEnvelopeCount = 0;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->forwardedEnvelopeCount++;
        return $envelope;
    }
}
