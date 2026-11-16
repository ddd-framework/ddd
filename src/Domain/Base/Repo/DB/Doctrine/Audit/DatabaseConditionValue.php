<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One bound value of the condition of an UPDATE or DELETE, with the column it is compared with (null when the
 * placeholder follows no plain column comparison).
 */
class DatabaseConditionValue extends ValueObject
{
    /** @var string|null The compared column; null when none stands right before the placeholder */
    public ?string $column = null;

    /** @var mixed The bound value (a scalar, or a list for an IN placeholder) */
    public mixed $value = null;

    /**
     * @param string|null $column
     * @param mixed $value
     * @return static
     */
    public static function forColumn(?string $column, mixed $value): static
    {
        $databaseConditionValue = new static();
        $databaseConditionValue->column = $column;
        $databaseConditionValue->value = $value;
        return $databaseConditionValue;
    }

    /**
     * The stored document of this value: `column`, `value`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['column' => $this->column, 'value' => $this->value];
    }
}
