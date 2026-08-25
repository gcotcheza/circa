<?php

declare(strict_types=1);

namespace Tests\Feature\Rollup;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\Source;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\HealthMetric;
use App\Enums\MetricAggregation;
use App\Services\Rollup\DailySummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The rollup, against the real schema.
 *
 * Everything is keyed on 2026-06-15, a CEST day whose local midnight is
 * 2026-06-14 22:00 UTC — what `health_metrics.local_date` (a Postgres generated
 * column using AT TIME ZONE 'Europe/Amsterdam') resolves to. Building buckets in
 * UTC and letting the database pick the day is the point: a PHP-side date would
 * be testing the test.
 */
final class DailySummaryRollupTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    /** Local midnight of DATE, in UTC. */
    private const DAY_START = '2026-06-14 22:00:00';

    public function test_expenditure_takes_one_source_per_bucket_and_never_sums_them(): void
    {
        $watch = Source::factory()->watch()->create();
        $phone = Source::factory()->phone()->create();

        // Both devices report every hour of the day, as they really do.
        $this->hourlyDay('active_energy', $watch, 25.0);
        $this->hourlyDay('active_energy', $phone, 10.0);
        $this->hourlyDay('basal_energy_burned', $watch, 60.0);

        $summary = $this->build();

        // 24 x 25, not 24 x 35: summing across sources roughly doubles
        // expenditure and poisons every TDEE estimate downstream.
        self::assertSame(600.0, (float) $summary->active_kcal);
        self::assertSame(1440.0, (float) $summary->resting_kcal);
        self::assertSame(2040.0, $summary->totalKcalOut());
    }

    public function test_overlapping_sync_anchored_buckets_do_not_double_count(): void
    {
        $watch = Source::factory()->watch()->create();
        $phone = Source::factory()->phone()->create();

        // 22 hour-aligned watch buckets, 50 kcal each, 00:00-22:00 local. Then
        // the export pattern that made this necessary: the phone syncs at
        // 22:30:53 and reports its own hour, straddling the watch's 22:00-23:00
        // bucket by 29 minutes.
        for ($hour = 0; $hour < 22; $hour++) {
            $this->bucket('active_energy', $watch, $hour, $hour + 1, 50.0);
        }

        $this->bucketAt(
            'active_energy',
            $watch,
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(22),
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(23),
            50.0,
        );

        $this->bucketAt(
            'active_energy',
            $phone,
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(22)->addMinutes(30)->addSeconds(53),
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(23)->addMinutes(30)->addSeconds(53),
            40.0,
        );

        $summary = $this->build();

        // 23 watch hours at 50 kcal. The overlapping phone bucket is dropped
        // whole: adding it double-counts 29 minutes, and pro-rating it assumes
        // the 40 kcal spreads evenly across the hour, which is exactly what a
        // 20-minute walk is not.
        self::assertSame(1150.0, (float) $summary->active_kcal);

        // And every row is still there: nothing was deleted to make it work.
        self::assertSame(24, HealthMetric::query()->where('metric', 'active_energy')->count());
    }

    public function test_steps_exercise_and_distance_land_on_the_summary(): void
    {
        $watch = Source::factory()->watch()->create();
        $composite = Source::factory()->composite()->create();

        // The real pattern: the composite "Watch|iPhone" source reports most
        // step hours, the watch alone the rest.
        for ($hour = 0; $hour < 12; $hour++) {
            $this->bucket('step_count', $composite, $hour, $hour + 1, 700.0);
        }

        for ($hour = 12; $hour < 24; $hour++) {
            $this->bucket('step_count', $watch, $hour, $hour + 1, 20.0);
        }

        $this->bucket('apple_exercise_time', $watch, 9, 10, 22.0, 'min');
        $this->bucket('walking_running_distance', $watch, 9, 10, 3.421, 'km');

        $summary = $this->build();

        self::assertSame(8640.0, (float) $summary->steps);
        self::assertSame(22.0, (float) $summary->exercise_minutes);
        self::assertSame(3.421, (float) $summary->distance_km);
    }

    public function test_a_weigh_in_is_picked_by_priority_and_never_summed(): void
    {
        $scale = Source::factory()->scale()->create();
        $phone = Source::factory()->phone()->create();

        $this->instant('weight_body_mass', $scale, 8, 56.2);
        $this->instant('weight_body_mass', $scale, 20, 56.8);
        $this->instant('weight_body_mass', $phone, 22, 61.0);

        $summary = $this->build();

        // Not 174.0: the scale outranks the phone, and its later reading is the
        // correction.
        self::assertSame(56.8, (float) $summary->weight_kg);
    }

    public function test_a_day_with_no_weigh_in_stores_null_not_zero(): void
    {
        $this->hourlyDay('active_energy', Source::factory()->watch()->create(), 20.0);

        self::assertNull($this->build()->weight_kg);
    }

    public function test_intake_is_the_quadrature_band_of_confirmed_items(): void
    {
        $meal = Meal::withoutEvents(fn (): Meal => Meal::factory()->eatenAt(
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(13)
        )->create());

        // Two items, each 300 ± 60 kcal (100 g at 240-360 kcal/100 g).
        foreach ([1, 2] as $ignored) {
            MealItem::withoutEvents(fn () => MealItem::factory()->create([
                'meal_id'              => $meal->id,
                'portion_g_min'        => 100,
                'portion_g_max'        => 100,
                'kcal_per_100g_min'    => 240,
                'kcal_per_100g_max'    => 360,
                'protein_per_100g_min' => 10,
                'protein_per_100g_max' => 10,
                'carbs_per_100g_min'   => 20,
                'carbs_per_100g_max'   => 20,
                'fat_per_100g_min'     => 5,
                'fat_per_100g_max'     => 5,
            ]));
        }

        $summary = $this->build();

        self::assertSame(600.0, (float) $summary->kcal_in_mid);

        // Quadrature: sqrt(60^2 + 60^2) = 84.85. A linear sum would say ±120.
        self::assertEqualsWithDelta(600.0 - sqrt(2) * 60, (float) $summary->kcal_in_min, 0.02);
        self::assertEqualsWithDelta(600.0 + sqrt(2) * 60, (float) $summary->kcal_in_max, 0.02);

        // Certain macros stay certain: zero-width in, zero-width out.
        self::assertSame(20.0, (float) $summary->protein_g_mid);
        self::assertSame(20.0, (float) $summary->protein_g_min);
        self::assertSame(40.0, (float) $summary->carbs_g_mid);
        self::assertSame(10.0, (float) $summary->fat_g_mid);
    }

    public function test_an_unconfirmed_meal_is_not_intake(): void
    {
        $draft = Meal::withoutEvents(fn (): Meal => Meal::factory()->draft()->eatenAt(
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(13)
        )->create());

        MealItem::withoutEvents(fn () => MealItem::factory()->create(['meal_id' => $draft->id]));

        // Nothing is logged without a tap: an unconfirmed meal must not move
        // the band on its own.
        self::assertNull($this->build()->kcal_in_mid);
    }

    public function test_today_is_provisional_even_when_its_coverage_is_perfect(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Amsterdam'));

        $watch = Source::factory()->watch()->create();

        // Every hour so far today, reported by the watch.
        for ($hour = 0; $hour < 12; $hour++) {
            $this->bucket('active_energy', $watch, $hour, $hour + 1, 30.0);
            $this->bucket('basal_energy_burned', $watch, $hour, $hour + 1, 60.0);
        }

        $summary = $this->build();

        // The hours that have not happened cannot have been measured.
        self::assertFalse($summary->has_full_metric_coverage);

        // But what HAS been measured is complete, so the burn figure is not
        // also flagged as an undercount.
        self::assertFalse($summary->active_kcal_is_partial);
        self::assertSame(360.0, (float) $summary->active_kcal);
    }

    public function test_a_finished_day_with_full_coverage_is_trustworthy(): void
    {
        $watch = Source::factory()->watch()->create();

        $this->hourlyDay('active_energy', $watch, 30.0);
        $this->hourlyDay('basal_energy_burned', $watch, 60.0);

        $summary = $this->build();

        self::assertTrue($summary->has_full_metric_coverage);
        self::assertFalse($summary->active_kcal_is_partial);
        self::assertTrue($summary->expenditureIsTrustworthy());
        self::assertSame(1.0, (float) $summary->active_kcal_coverage);
    }

    public function test_a_day_the_watch_spent_on_the_charger_is_flagged_partial(): void
    {
        $watch = Source::factory()->watch()->create();

        // Worn for 14 of 24 hours: 58% coverage, under the 80% threshold.
        for ($hour = 0; $hour < 14; $hour++) {
            $this->bucket('active_energy', $watch, $hour, $hour + 1, 30.0);
            $this->bucket('basal_energy_burned', $watch, $hour, $hour + 1, 60.0);
        }

        $summary = $this->build();

        // The number is shown — a floor, not a fiction — but carries the
        // caveat, because a 420 kcal day with the watch off looks exactly like
        // a genuinely sedentary one.
        self::assertSame(420.0, (float) $summary->active_kcal);
        self::assertTrue($summary->active_kcal_is_partial);
        self::assertFalse($summary->has_full_metric_coverage);
        self::assertEqualsWithDelta(14 / 24, (float) $summary->active_kcal_coverage, 0.002);
    }

    public function test_a_day_with_no_metrics_at_all_is_flagged_rather_than_zeroed(): void
    {
        $summary = $this->build();

        self::assertNull($summary->active_kcal);
        self::assertNull($summary->resting_kcal);
        self::assertNull($summary->totalKcalOut());
        self::assertTrue($summary->active_kcal_is_partial);
        self::assertFalse($summary->has_full_metric_coverage);
    }

    // --- the sync catch-up bucket -------------------------------------------

    public function test_a_catch_up_bucket_is_left_out_of_the_burn_and_its_hours_left_uncovered(): void
    {
        $watch = Source::factory()->watch()->create();

        // The 2026-08-18 shape on this file's day: nine real basal hours, a gap
        // the Watch spent disconnected, then one hour-stamped bucket carrying
        // the modeled basal for all of it.
        for ($hour = 0; $hour < 9; $hour++) {
            $this->bucket('basal_energy_burned', $watch, $hour, $hour + 1, 47.0);
        }

        $start = CarbonImmutable::parse(self::DAY_START, 'UTC');

        $this->bucketAt(
            'basal_energy_burned',
            $watch,
            $start->addHours(9)->addMinute(),
            $start->addHours(10)->addMinute(),
            1283.0,
        );

        $summary = $this->build();

        // 423, not 1,706: the phantom half-day never enters the total and its
        // stamped hour is not claimed either, so the day reads as the floor it
        // is and the coverage flag says why.
        self::assertSame(423.0, (float) $summary->resting_kcal);
        self::assertFalse($summary->has_full_metric_coverage);
    }

    public function test_the_guard_leaves_an_ordinary_day_exactly_as_it_was(): void
    {
        $watch = Source::factory()->watch()->create();

        // The post-heal shape: every hour present, every value ordinary — the
        // day the guard may never touch.
        $this->hourlyDay('active_energy', $watch, 13.0);
        $this->hourlyDay('basal_energy_burned', $watch, 46.2);

        $summary = $this->build();

        self::assertSame(312.0, (float) $summary->active_kcal);
        self::assertEqualsWithDelta(1108.8, (float) $summary->resting_kcal, 0.001);
        self::assertTrue($summary->has_full_metric_coverage);
        self::assertFalse($summary->active_kcal_is_partial);
        self::assertSame(1.0, (float) $summary->active_kcal_coverage);
    }

    public function test_a_real_bucket_for_the_same_hour_still_wins_the_span_back(): void
    {
        $watch = Source::factory()->watch()->create();
        $phone = Source::factory()->phone()->create();

        // The self-heal path at bucket level: once genuine data arrives for the
        // stamped hour the impossible row is out of the running, not merely
        // outranked, so the hour is covered by whatever measured it — even a
        // source that would otherwise have lost the overlap.
        $this->bucket('basal_energy_burned', $watch, 9, 10, 1283.0);
        $this->bucket('basal_energy_burned', $phone, 9, 10, 44.0);

        self::assertSame(44.0, (float) $this->build()->resting_kcal);
    }

    public function test_rebuilding_is_idempotent(): void
    {
        $watch = Source::factory()->watch()->create();
        $this->hourlyDay('active_energy', $watch, 25.0);

        $first = $this->build();
        $second = $this->build();

        self::assertSame(1, DailySummary::query()->count());
        self::assertSame((float) $first->active_kcal, (float) $second->active_kcal);
    }

    // --- helpers ----------------------------------------------------------

    private function build(string $date = self::DATE): DailySummary
    {
        return app(DailySummaryBuilder::class)->build($date);
    }

    private function hourlyDay(string $metric, Source $source, float $value): void
    {
        for ($hour = 0; $hour < 24; $hour++) {
            $this->bucket($metric, $source, $hour, $hour + 1, $value);
        }
    }

    private function bucket(
        string $metric,
        Source $source,
        int $startHour,
        int $endHour,
        float $value,
        string $unit = 'kcal',
    ): void {
        $start = CarbonImmutable::parse(self::DAY_START, 'UTC');

        $this->bucketAt($metric, $source, $start->addHours($startHour), $start->addHours($endHour), $value, $unit);
    }

    private function bucketAt(
        string $metric,
        Source $source,
        CarbonImmutable $start,
        CarbonImmutable $end,
        float $value,
        string $unit = 'kcal',
    ): void {
        HealthMetric::query()->create([
            'metric'                    => $metric,
            'aggregation'               => MetricAggregation::Sum,
            'period'                    => 'hour',
            'value'                     => $value,
            'unit'                      => $unit,
            'started_at'                => $start,
            'ended_at'                  => $end,
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $source->id,
            'ingested_at'               => CarbonImmutable::now(),
        ]);
    }

    private function instant(string $metric, Source $source, int $hour, float $value): void
    {
        $at = CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours($hour);

        HealthMetric::query()->create([
            'metric'                    => $metric,
            'aggregation'               => MetricAggregation::Instant,
            'period'                    => 'hour',
            'value'                     => $value,
            'unit'                      => 'kg',
            'started_at'                => $at,
            'ended_at'                  => $at,
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $source->id,
            'ingested_at'               => CarbonImmutable::now(),
        ]);
    }
}
