<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use App\Models\SleepSession;
use Illuminate\Support\Facades\DB;
use App\Services\Rollup\MetricBucket;
use App\Services\Rollup\BucketSelector;

/**
 * Whether a night has fully ARRIVED, which is not the same as how long it was.
 *
 * A sleep session can't report hours that hadn't reached the server yet, so
 * a partly-delivered night arrives short and complete-looking: 2026-08-18
 * came in as 119 minutes beside six nights of 409-550, later corrected to
 * 507 — nothing in the session itself says which it is. The Watch's basal
 * buckets do, written every hour it's worn, so a stretch with neither a
 * bucket nor a session hasn't arrived.
 *
 * Both halves matter: a gap INSIDE the session proves nothing (the session
 * covering it is itself evidence the data arrived — the hole is just a
 * Watch on a charger, not ours to tell), while a gap OUTSIDE it is
 * unaccounted for, and that's the only thing this reports. A complete
 * 507-minute night with a charger hole in the middle therefore says
 * nothing, which is the point.
 *
 * The window is a typical night widened to the session's own span, since a
 * truncated session can't be asked how long the night was. Buckets go
 * through BucketSelector, the one implementation, so a sync catch-up
 * bucket can't fake coverage.
 *
 * See config/health.php `sleep.night` for the window and thresholds.
 */
final class NightCoverage
{
    public function __construct(private readonly BucketSelector $selector = new BucketSelector) {}

    /**
     * How much of this night is unaccounted for, or null when there's
     * nothing to say: a night that arrived whole, one whose only gaps are
     * inside the session, no clock at all, or no Watch behind the setup
     * (see `watchIsInPlay`).
     *
     * @return array{missingMinutes: int}|null
     */
    public function fragment(SleepSession $session): ?array
    {
        $config = (array) config('health.sleep.night');

        $tz = (string) config('health.timezone');

        if ($session->sleep_start === null || $session->sleep_end === null) {
            return null;
        }

        [$start, $end] = $this->window($session, $config, $tz);

        $span = $end->getTimestamp() - $start->getTimestamp();

        if ($span <= 0) {
            return null;
        }

        $missing = $this->unaccountedSeconds($session, $start, $end, $tz);

        if ($missing / $span <= (float) $config['missing_fraction']) {
            return null;
        }

        // Last, and only when the note would otherwise fire: it's the one
        // part that costs a second query, and most renders never reach here.
        if (! $this->watchIsInPlay($start, (int) $config['watch_lookback_days'])) {
            return null;
        }

        return ['missingMinutes' => (int) round($missing / 60)];
    }

    /**
     * The night: the evening before to the morning of, widened to the
     * session's own span and CLAMPED TO NOW.
     *
     * Same argument DailySummaryBuilder makes about a day in progress —
     * hours that haven't happened can't have been measured — without which
     * somebody awake at 02:00 would be judged against six hours of future.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(SleepSession $session, array $config, string $tz): array
    {
        $morning = $session->night_date->setTimezone($tz)->startOfDay();

        $start = $morning->subDay()->setTimeFromTimeString((string) $config['starts_at']);
        $end = $morning->setTimeFromTimeString((string) $config['ends_at']);

        $sleepStart = $session->sleep_start?->setTimezone($tz);
        $sleepEnd = $session->sleep_end?->setTimezone($tz);

        if ($sleepStart !== null && $sleepStart < $start) {
            $start = $sleepStart;
        }

        if ($sleepEnd !== null && $sleepEnd > $end) {
            $end = $sleepEnd;
        }

        $now = CarbonImmutable::now($tz);

        return [$start, $end > $now ? $now : $end];
    }

    /**
     * Seconds of the window that are outside the session AND outside every
     * watch-derived basal bucket — the night nothing has accounted for.
     *
     * Subtracting the session first is what keeps this a claim about delivery.
     */
    private function unaccountedSeconds(
        SleepSession $session,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $tz,
    ): int {
        $outside = $this->minus(
            [[$start->getTimestamp(), $end->getTimestamp()]],
            [[
                (int) $session->sleep_start?->setTimezone($tz)->getTimestamp(),
                (int) $session->sleep_end?->setTimezone($tz)->getTimestamp(),
            ]]
        );

        $unaccounted = $this->minus($outside, $this->wornIntervals($start, $end));

        $seconds = 0;

        foreach ($unaccounted as [$from, $to]) {
            $seconds += $to - $from;
        }

        return $seconds;
    }

    /**
     * `$spans` with every part of `$holes` cut out of it.
     *
     * @param  list<array{0: int, 1: int}>  $spans
     * @param  list<array{0: int, 1: int}>  $holes
     * @return list<array{0: int, 1: int}>
     */
    private function minus(array $spans, array $holes): array
    {
        foreach ($holes as [$holeFrom, $holeTo]) {
            $next = [];

            foreach ($spans as [$from, $to]) {
                if ($holeTo <= $from || $holeFrom >= $to) {
                    $next[] = [$from, $to];

                    continue;
                }

                if ($holeFrom > $from) {
                    $next[] = [$from, $holeFrom];
                }

                if ($holeTo < $to) {
                    $next[] = [$holeTo, $to];
                }
            }

            $spans = $next;
        }

        return $spans;
    }

    /**
     * Watch-derived basal buckets overlapping the window, clamped to it.
     *
     * ->utc() on both bounds is why they're converted here rather than
     * passed through: `started_at` is timestamptz and Eloquent serialises a
     * Carbon with the connection's plain date format, carrying no offset —
     * a 22:00 Europe/Amsterdam bound would reach a UTC session as bare
     * "22:00:00", read as UTC, shifting the window two hours and dropping
     * the first hours of every night. Same trap MealWriter documents on the
     * way in.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function wornIntervals(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $metric = (string) config('health.rollup.resting_metric');

        /** @var list<string> $kinds */
        $kinds = (array) config('health.rollup.coverage.watch_kinds');

        $rows = DB::table('health_metrics as hm')
            ->join('sources as s', 's.id', '=', 'hm.source_id')
            ->select([
                'hm.id',
                'hm.metric',
                'hm.source_id',
                's.device_kind',
                'hm.started_at',
                'hm.ended_at',
                'hm.value',
            ])
            ->where('hm.metric', $metric)
            ->where('hm.started_at', '<', $end->utc())
            ->where('hm.ended_at', '>', $start->utc())
            ->orderBy('hm.started_at')
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = MetricBucket::fromRow($row);
        }

        $intervals = [];

        foreach ($this->selector->select($metric, $candidates)->accepted as $bucket) {
            if (! in_array($bucket->deviceKind->value, $kinds, strict: true)) {
                continue;
            }

            $from = max($bucket->startedAt->getTimestamp(), $start->getTimestamp());
            $to = min($bucket->endedAt->getTimestamp(), $end->getTimestamp());

            if ($to > $from) {
                $intervals[] = [$from, $to];
            }
        }

        return $intervals;
    }

    /**
     * Is there a Watch behind this setup at all?
     *
     * Asked of the RECENT PAST, not this night — this night is exactly the
     * one whose emptiness is in question, and the worst outage (export
     * resumed in the morning, no overnight buckets) would otherwise say
     * nothing. A phone-only setup has no watch-derived basal ever and would
     * stay silent forever — the false positive this exists to stop.
     */
    private function watchIsInPlay(CarbonImmutable $start, int $lookbackDays): bool
    {
        /** @var list<string> $kinds */
        $kinds = (array) config('health.rollup.coverage.watch_kinds');

        return DB::table('health_metrics as hm')
            ->join('sources as s', 's.id', '=', 'hm.source_id')
            ->where('hm.metric', (string) config('health.rollup.resting_metric'))
            ->whereIn('s.device_kind', $kinds)
            ->where('hm.started_at', '<', $start->utc())
            ->where('hm.started_at', '>=', $start->subDays($lookbackDays)->utc())
            ->exists();
    }
}
