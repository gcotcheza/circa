<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who asked for the report.
 *
 * Not "what is in it" — both kinds run the identical assembler, prompt and
 * schema, so a weekly report over seven days is byte-for-byte a manual
 * seven-day report. The column exists because two questions need it queryable:
 *
 *   THE CRON'S    "have I already written last week's?" — asked every Monday
 *                 against (kind, range_start, range_end), so a restart
 *                 doesn't produce two.
 *
 *   THE READER'S  "which of these did I ask for?" — so automatic and
 *                 deliberate reports stay distinguishable in the list.
 */
enum HealthReportKind: string
{
    /** The user picked a range and tapped Generate. */
    case Manual = 'manual';

    /** The Monday cron, covering the previous Monday–Sunday. */
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'On demand',
            self::Weekly => 'Weekly',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
