<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Slot within the day.
 *
 * Enumerated rather than free-text: it's a closed set, and the
 * `is_complete_log` heuristic ("last item after ~18:00 local") reasons
 * about it without normalising strings first.
 */
enum MealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
