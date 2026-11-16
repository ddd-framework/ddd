<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The messages dispatched during a capture, in dispatch order; a message dispatched twice is two elements.
 *
 * @property CapturedMessageDispatch[] $elements
 * @method CapturedMessageDispatch first()
 * @method CapturedMessageDispatch[] getElements()
 */
class CapturedMessageDispatches extends ObjectSet
{
}
