<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonInterval;
use Carbon\CarbonImmutable;

/**
 * The bucket width a payload's datapoints cover — `health_metrics.period`.
 *
 * The two automation headers are swapped from their names:
 * `automation-aggregation` ("Hours") is the BUCKET WIDTH read here;
 * `automation-period` ("Since Last Sync") is the EXPORT WINDOW logged on
 * ingest_runs, never a bucket. Reading the wrong one would fork the
 * six-column unique key on every window-mode change — SPEC.md v2 had these
 * swapped; the fix lives here.
 */
final class BucketWidth
{
    /**
     * Falls back to the observed setting, not something inert — `period`
     * sits in the unique key, so a missing header producing a different
     * value would duplicate rows instead of upserting. Absence is far more
     * likely a stripped header than a genuinely different width.
     */
    public const DEFAULT = 'hour';

    /** @var array<string, string> */
    private const HEADER_TO_PERIOD = [
        'seconds' => 'second',
        'minutes' => 'minute',
        'hours'   => 'hour',
        'days'    => 'day',
        'weeks'   => 'week',
        'months'  => 'month',
        'years'   => 'year',
    ];

    /**
     * @param  array<string, mixed>  $headers  as banked on raw_ingest_payloads
     */
    public static function fromHeaders(array $headers): string
    {
        $raw = $headers['automation-aggregation'] ?? null;

        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return self::DEFAULT;
        }

        $key = mb_strtolower(trim($raw));

        if (isset(self::HEADER_TO_PERIOD[$key])) {
            return self::HEADER_TO_PERIOD[$key];
        }

        // Kept verbatim (lowercased, de-pluralised), not coerced to 'hour'
        // — better an unqueried period than a mislabelled bucket colliding
        // with real hourly rows in the key.
        return rtrim($key, 's');
    }

    /**
     * How long one bucket lasts, for `ended_at` on non-instant rows.
     *
     * Calendar-aware on purpose: `addMonth()` respects month lengths, and a
     * DST-crossing interval adds wall time, not 3600 seconds — what a
     * "1 hour bucket" means to the reporting device.
     */
    public static function endOf(CarbonImmutable $start, string $period): CarbonImmutable
    {
        return $start->add(self::interval($period));
    }

    public static function interval(string $period): CarbonInterval
    {
        return match ($period) {
            'second' => CarbonInterval::second(),
            'minute' => CarbonInterval::minute(),
            'hour'   => CarbonInterval::hour(),
            'day'    => CarbonInterval::day(),
            'week'   => CarbonInterval::week(),
            'month'  => CarbonInterval::month(),
            'year'   => CarbonInterval::year(),
            // Unknown width -> zero-length bucket: round-trips and upserts
            // fine, just claims no span it can't describe.
            default => CarbonInterval::seconds(0),
        };
    }
}
