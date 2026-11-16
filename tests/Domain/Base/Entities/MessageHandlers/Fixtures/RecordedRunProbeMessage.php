<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers\Fixtures;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/** A message with a handler name and one id, for the dispatch capture and the recorded-run queue. */
class RecordedRunProbeMessage extends AppMessage
{
    public static string $messageHandler = 'RecordedRunProbeHandler';

    public int $itemId = 0;

    public function __construct(int $itemId = 0)
    {
        parent::__construct();
        $this->itemId = $itemId;
    }
}
