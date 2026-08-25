<?php

declare(strict_types=1);

namespace Tests\Unit\Rollup;

use App\Enums\DeviceKind;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use App\Services\Rollup\MetricBucket;
use App\Services\Rollup\BucketSelector;
use App\Services\Rollup\SourcePriority;

/**
 * The selection algorithm, with no database in the way. Two genuinely different
 * failure modes: two sources reporting the SAME bucket (the classic
 * double-count), and two buckets that PARTIALLY overlap because a "Since Last
 * Sync" export anchored its hours at the previous sync instant — which
 * `DISTINCT ON` does nothing about.
 */
final class BucketSelectorTest extends TestCase
{
    private BucketSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        // The real config, inlined: this test must not depend on a booted app.
        $this->selector = new BucketSelector(
            new SourcePriority([
                'default'          => ['watch', 'phone', 'scale', 'app', 'composite', 'unknown'],
                'step_count'       => ['watch', 'composite', 'phone'],
                'active_energy'    => ['watch', 'composite', 'phone'],
                'weight_body_mass' => ['scale', 'app', 'phone'],
            ]),
            [
                'min_seconds'   => 60,
                'kcal_per_hour' => [
                    'basal_energy_burned' => 200.0,
                    'active_energy'       => 1500.0,
                ],
            ]
        );
    }

    public function test_adjacent_hourly_buckets_are_all_kept(): void
    {
        // [start, end) is half-open, so 09:00-10:00 and 10:00-11:00 do not
        // overlap — if they did, selection would discard half of every day.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 100),
            $this->bucket(2, DeviceKind::Watch, '10:00', '11:00', 200),
            $this->bucket(3, DeviceKind::Watch, '11:00', '12:00', 300),
        ];

        $selection = $this->selector->select('active_energy', $buckets);

        self::assertCount(3, $selection->accepted);
        self::assertSame(600.0, $selection->total());
        self::assertSame(3 * 3600, $selection->coveredSeconds());
    }

    public function test_the_watch_beats_the_phone_for_the_same_bucket(): void
    {
        $buckets = [
            $this->bucket(1, DeviceKind::Phone, '09:00', '10:00', 40),
            $this->bucket(2, DeviceKind::Watch, '09:00', '10:00', 100),
        ];

        $selection = $this->selector->select('step_count', $buckets);

        // Never 140. Summing across sources roughly doubles the day.
        self::assertSame(100.0, $selection->total());
        self::assertSame(2, $selection->accepted[0]->id);
        self::assertCount(1, $selection->rejected);
    }

    public function test_a_partially_overlapping_bucket_is_rejected_whole(): void
    {
        // The 2026-08-07 shape: the phone's sync-anchored 14:30:53 bucket
        // overlaps the composite's 15:42:55 one by 48 minutes.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '14:00', '15:00', 300),
            $this->bucket(2, DeviceKind::Phone, '14:30:53', '15:30:53', 400),
            $this->bucket(3, DeviceKind::Composite, '15:42:55', '16:42:55', 1291),
        ];

        $selection = $this->selector->select('step_count', $buckets);

        // The watch hour wins; the phone bucket straddling it is dropped whole
        // rather than pro-rated, because a bucket's value is not spread evenly
        // across its span. The composite overlaps nothing accepted, so it lives.
        self::assertSame([1, 3], array_map(fn ($b) => $b->id, $selection->accepted));
        self::assertSame(1591.0, $selection->total());

        // And the rejection is visible rather than silent.
        self::assertSame([2], array_map(fn ($b) => $b->id, $selection->rejected));
    }

    public function test_a_lower_priority_bucket_fills_a_gap_the_watch_left(): void
    {
        // The Watch was on the charger 12:00-13:00; the phone's bucket overlaps
        // nothing accepted, and an undercount is worse than a real measurement.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '11:00', '12:00', 100),
            $this->bucket(2, DeviceKind::Phone, '12:00', '13:00', 60),
            $this->bucket(3, DeviceKind::Watch, '13:00', '14:00', 120),
        ];

        $selection = $this->selector->select('step_count', $buckets);

        self::assertSame(280.0, $selection->total());
        self::assertSame(3 * 3600, $selection->coveredSeconds());

        // But that hour was not from the wrist, and the coverage figure driving
        // `active_kcal_is_partial` knows it.
        self::assertSame(2 * 3600, $selection->coveredSecondsBy(['watch', 'composite']));
    }

    public function test_duplicate_identical_buckets_from_one_source_cannot_double_count(): void
    {
        // Impossible (the unique key forbids it), which is why the rollup must
        // not be the thing relying on that.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 100),
            $this->bucket(2, DeviceKind::Watch, '09:00', '10:00', 100),
        ];

        self::assertSame(100.0, $this->selector->select('active_energy', $buckets)->total());
    }

    public function test_an_instant_metric_picks_the_priority_source_then_the_latest_sample(): void
    {
        $morning = $this->instant(1, DeviceKind::Scale, '08:00', 56.2);
        $evening = $this->instant(2, DeviceKind::Scale, '20:00', 56.8);
        $phone = $this->instant(3, DeviceKind::Phone, '22:00', 61.0);

        $pick = $this->selector->pickInstant('weight_body_mass', [$morning, $phone, $evening]);
        self::assertNotNull($pick);

        // The scale outranks a phone entry and the later weigh-in is the
        // correction: stepping on it twice does not make you heavier.
        self::assertSame(2, $pick->id);
        self::assertSame(56.8, $pick->value);
    }

    public function test_no_buckets_is_null_rather_than_zero(): void
    {
        $selection = $this->selector->select('active_energy', []);

        self::assertNull($selection->total());
        self::assertTrue($selection->isEmpty());
    }

    // --- the catch-up bucket -------------------------------------------------

    public function test_an_hour_no_body_could_burn_is_dropped_and_its_span_left_uncovered(): void
    {
        // The 2026-08-18 row, verbatim: the Watch reconnected at 09:01 and Apple
        // wrote ~eleven hours of modeled basal under a one-hour stamp. The
        // biggest genuine basal bucket in captured history is 82.155 kcal.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '08:00', '09:00', 54.0),
            $this->bucket(2, DeviceKind::Watch, '09:01', '10:01', 1283.0),
        ];

        $selection = $this->selector->select('basal_energy_burned', $buckets);

        // Not 1,337 — and the hour it claimed is NOT covered, so the day reads
        // as the floor it is rather than being inflated by a phantom half-day.
        self::assertSame(54.0, $selection->total());
        self::assertSame(3600, $selection->coveredSeconds());

        // Kept as a reject: "why is this day lower than yesterday" has to stay
        // answerable from the selection.
        self::assertCount(1, $selection->rejected);
        self::assertSame(2, $selection->rejected[0]->id);
    }

    public function test_a_bucket_exactly_at_the_ceiling_is_still_a_measurement(): void
    {
        // 200 kcal in an hour is the most extreme hour still allowed to be real:
        // the guard is for what cannot happen, not for what is unusual.
        $at = $this->selector->select('basal_energy_burned', [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 200.0),
        ]);

        self::assertSame(200.0, $at->total());
        self::assertSame(3600, $at->coveredSeconds());

        $over = $this->selector->select('basal_energy_burned', [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 200.01),
        ]);

        self::assertNull($over->total());
        self::assertSame(0, $over->coveredSeconds());
    }

    public function test_the_guard_never_fires_on_a_day_that_is_merely_hard(): void
    {
        // The largest active bucket in captured history is 319.642 kcal, and a
        // real day is a run of ordinary ones. Nothing here is refused.
        $buckets = [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 319.642),
            $this->bucket(2, DeviceKind::Watch, '10:00', '11:00', 280.0),
            $this->bucket(3, DeviceKind::Watch, '11:00', '12:00', 41.5),
        ];

        $selection = $this->selector->select('active_energy', $buckets);

        self::assertCount(3, $selection->accepted);
        self::assertSame([], $selection->rejected);
        self::assertSame(3 * 3600, $selection->coveredSeconds());
    }

    public function test_a_metric_with_no_ceiling_is_never_judged_on_its_rate(): void
    {
        // 7,319 steps in an hour is the observed maximum and an absurd "rate"
        // against any energy ceiling. Only metrics named in config are judged.
        $selection = $this->selector->select('step_count', [
            $this->bucket(1, DeviceKind::Watch, '09:00', '10:00', 7319.39),
        ]);

        self::assertSame(7319.39, $selection->total());
    }

    public function test_a_very_short_bucket_is_not_condemned_by_extrapolation(): void
    {
        // A ten-second bucket carrying 0.4 kcal implies 144 kcal/h taken
        // literally; `min_seconds` stops the guard convicting a rounding
        // artifact of being a catch-up hour.
        $selection = $this->selector->select('basal_energy_burned', [
            $this->bucket(1, DeviceKind::Watch, '09:00:00', '09:00:10', 0.4),
        ]);

        self::assertSame(0.4, $selection->total());
    }

    public function test_a_bucket_stamped_backwards_has_no_rate_to_judge(): void
    {
        // ended_at before started_at is a broken row, not a slow hour: dividing
        // by it would flip the comparison and pass anything.
        $selection = $this->selector->select('basal_energy_burned', [
            $this->bucket(1, DeviceKind::Watch, '10:00', '09:00', 1283.0),
        ]);

        self::assertSame(1283.0, $selection->total());
    }

    private function bucket(int $id, DeviceKind $kind, string $start, string $end, float $value): MetricBucket
    {
        return new MetricBucket(
            id: $id,
            metric: 'x',
            sourceId: $id,
            deviceKind: $kind,
            startedAt: CarbonImmutable::parse("2026-06-15 {$start}", 'UTC'),
            endedAt: CarbonImmutable::parse("2026-06-15 {$end}", 'UTC'),
            value: $value,
        );
    }

    private function instant(int $id, DeviceKind $kind, string $at, float $value): MetricBucket
    {
        return $this->bucket($id, $kind, $at, $at, $value);
    }
}
