<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\MealMemory;

/**
 * "You have had this before" — and how sure we are.
 *
 * `exact` is not `score === 1.0` for readability; it's a different claim.
 * An exact match means the item-name sets are identical — the only case
 * where the remembered portions describe THIS plate rather than one like
 * it. A 0.75 containment says the dinner had an extra item, and the UI is
 * entitled to say "looks like" rather than "this is".
 */
final readonly class MemoryMatch
{
    public function __construct(
        public MealMemory $memory,
        public float $score,
        public bool $exact,
    ) {}
}
