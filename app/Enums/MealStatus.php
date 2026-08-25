<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * draft → analyzing → proposed → confirmed | failed
 *
 * The model proposes, the user confirms: nothing counts toward intake until a
 * meal reaches Confirmed by an explicit tap.
 */
enum MealStatus: string
{
    /** Created client-side, possibly still offline in the IndexedDB queue. */
    case Draft = 'draft';

    /** Handed to the vision job. */
    case Analyzing = 'analyzing';

    /** Vision returned items; awaiting the user's confirm/edit. */
    case Proposed = 'proposed';

    /** User-confirmed. Only these rows feed `daily_summaries`. */
    case Confirmed = 'confirmed';

    case Failed = 'failed';

    public function countsTowardIntake(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
