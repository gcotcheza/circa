<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The class of thing a `sources` row represents.
 *
 * Source *priority* is configured against these values (config/health.php),
 * not raw device strings — a rename must not fork history, and raw strings
 * are hostile to matching anyway. Observed in the 107 captured payloads:
 *
 *   "Demo\u{2019}s Apple\u{00A0}Watch"        → Watch     (curly apostrophe + NBSP)
 *   "Demo\u{2019}s iphone"                    → Phone
 *   "FITAGE", "Fitdays"                       → Scale     (two apps, one scale)
 *   "AutoSleep", "Blood Oxygen"               → App
 *   "Demo\u{2019}s Apple\u{00A0}Watch|Demo\u{2019}s iphone" → Composite
 *   ""                                        → Unknown   (apple_stand_hour)
 */
enum DeviceKind: string
{
    case Watch = 'watch';
    case Phone = 'phone';
    case Scale = 'scale';

    /** A third-party app writing into HealthKit rather than a device. */
    case App = 'app';

    /**
     * Health Auto Export merges contributing devices into one pipe-joined
     * string when summarising ("A|B"). Kept verbatim as its own source rather
     * than split, so the row still round-trips to the raw payload.
     */
    case Composite = 'composite';

    /** Including the empty-string source HAE emits for apple_stand_hour. */
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
