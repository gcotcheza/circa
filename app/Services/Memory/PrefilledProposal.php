<?php

declare(strict_types=1);

namespace App\Services\Memory;

/**
 * What memory did to a vision proposal: the rows to store, and why.
 *
 * The `match` is carried alongside the rows rather than folded into them
 * because it is a statement about the MEAL ("seen before, 7x") while the rows
 * are statements about foods, and the daily view needs both without having to
 * reconstruct one from the other.
 */
final readonly class PrefilledProposal
{
    /**
     * @param  list<array<string, mixed>>  $rows  meal_items shape
     */
    public function __construct(
        public array $rows,
        public ?MemoryMatch $match = null,
    ) {}

    public function adjustedCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (array $row): bool => ($row['memory_adjusted'] ?? false) === true
        ));
    }
}
