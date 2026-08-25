<?php

declare(strict_types=1);

namespace App\Enums;

/** Which of the three entry paths produced a meal. */
enum MealSource: string
{
    case Photo = 'photo';
    case Barcode = 'barcode';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
