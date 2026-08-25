<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\IngestRun;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\IngestRunStatus;
use App\Enums\MetricAggregation;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The three datapoint shapes Health Auto Export sends, end to end.
 */
final class ShapeHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function process(PayloadBuilder $builder): void
    {
        app(RawPayloadProcessor::class)->process($builder->store());
    }

    // ---------------------------------------------------------------- qty ---

    public function test_a_simple_quantity_becomes_one_hour_bucketed_row(): void
    {
        $this->process(
            PayloadBuilder::make()->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1234.5)
        );

        $row = HealthMetric::query()->sole();

        self::assertSame('step_count', $row->metric);
        self::assertSame(MetricAggregation::Sum, $row->aggregation);
        self::assertSame('hour', $row->period);
        self::assertSame('1234.500000', $row->value);
        self::assertSame('count', $row->unit);
        self::assertSame(120, $row->device_utc_offset_minutes);

        // 10:00 +0200 is 08:00Z, and the bucket is one wall-clock hour wide.
        self::assertSame('2026-08-07 08:00:00', $row->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 09:00:00', $row->ended_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07', $row->local_date->toDateString());
    }

    public function test_energy_is_stored_in_kilocalories_not_the_kilojoules_that_arrived(): void
    {
        $this->process(
            PayloadBuilder::make()
                ->quantity('active_energy', 'kJ', '2026-08-07 10:00:00 +0200', 4184.0)
                ->quantity('basal_energy_burned', 'kJ', '2026-08-07 10:00:00 +0200', 1337.383)
        );

        $active = HealthMetric::query()->where('metric', 'active_energy')->sole();
        $basal = HealthMetric::query()->where('metric', 'basal_energy_burned')->sole();

        self::assertSame('kcal', $active->unit);
        self::assertSame('1000.000000', $active->value);

        self::assertSame('kcal', $basal->unit);
        self::assertSame('319.642208', $basal->value);
    }

    /**
     * HAE hour-buckets body composition like everything else, but it is read at
     * a moment: zero-length, so the six-column unique key still catches a re-send.
     */
    public function test_an_instant_metric_is_a_zero_length_interval(): void
    {
        $this->process(
            PayloadBuilder::make()
                ->quantity('weight_body_mass', 'kg', '2026-05-03 12:00:00 +0200', 56.4, 'FITAGE')
        );

        $row = HealthMetric::query()->sole();

        self::assertSame(MetricAggregation::Instant, $row->aggregation);
        self::assertTrue($row->isInstant());
        self::assertSame(
            $row->started_at->utc()->format('Y-m-d H:i:s'),
            $row->ended_at->utc()->format('Y-m-d H:i:s')
        );
    }

    // -------------------------------------------------------- min/avg/max ---

    public function test_heart_rate_fans_out_into_three_rows_for_one_bucket(): void
    {
        $this->process(
            PayloadBuilder::make()->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74.36, 118)
        );

        $rows = HealthMetric::query()->orderBy('aggregation')->get();

        self::assertCount(3, $rows);

        $byAggregation = $rows->keyBy(fn (HealthMetric $m): string => $m->aggregation->value);

        [$min, $avg, $max] = [$byAggregation->get('min'), $byAggregation->get('avg'), $byAggregation->get('max')];
        self::assertNotNull($min);
        self::assertNotNull($avg);
        self::assertNotNull($max);

        self::assertSame('52.000000', $min->value);
        self::assertSame('74.360000', $avg->value);
        self::assertSame('118.000000', $max->value);

        // Separated only by `aggregation` — why that column is in the unique key.
        self::assertSame(1, $rows->pluck('started_at')->unique()->count());
        self::assertSame(1, $rows->pluck('source_id')->unique()->count());
        self::assertSame(['count/min'], $rows->pluck('unit')->unique()->values()->all());
    }

    public function test_a_partial_min_avg_max_datapoint_emits_only_what_it_carries(): void
    {
        $this->process(
            PayloadBuilder::make()->metric('heart_rate', 'count/min', [[
                'date'   => '2026-08-07 10:00:00 +0200',
                'Avg'    => 70.0,
                'source' => PayloadBuilder::WATCH,
            ]])
        );

        $rows = HealthMetric::query()->get();

        self::assertCount(1, $rows);
        self::assertSame(MetricAggregation::Avg, $rows->sole()->aggregation);
    }

    /** Shape-first routing: the fan-out is not hard-coded to heart_rate. */
    public function test_any_metric_with_min_avg_max_fans_out(): void
    {
        $this->process(
            PayloadBuilder::make()->minAvgMax(
                '2026-08-07 10:00:00 +0200',
                1, 2, 3,
                name: 'walking_speed',
                units: 'km/hr'
            )
        );

        self::assertSame(3, HealthMetric::query()->where('metric', 'walking_speed')->count());
    }

    // -------------------------------------------------------------- sleep ---

    public function test_a_sleep_night_becomes_one_session_row_in_minutes(): void
    {
        $this->process(PayloadBuilder::make()->sleep());

        $night = SleepSession::query()->sole();

        self::assertSame('2026-08-07', $night->night_date->toDateString());

        // Hours in, minutes out: 5.8720494 hr -> 352.323 min.
        self::assertSame('115.271', $night->rem_minutes);
        self::assertSame('352.323', $night->core_minutes);
        self::assertSame('60.637', $night->deep_minutes);
        self::assertSame('40.103', $night->awake_minutes);
        self::assertSame('528.231', $night->total_sleep_minutes);

        // The Watch reports 0. Zero is a fact; NULL would be a different one.
        self::assertSame('0.000', $night->in_bed_minutes);
        self::assertSame('0.000', $night->asleep_minutes);

        // Boundaries are absolute instants: 23:00:36 +0200 is 21:00:36Z.
        [$sleepStart, $sleepEnd, $inBedStart] = [$night->sleep_start, $night->sleep_end, $night->in_bed_start];
        self::assertNotNull($sleepStart);
        self::assertNotNull($sleepEnd);
        self::assertNotNull($inBedStart);
        self::assertSame('2026-08-06 21:00:36', $sleepStart->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 06:28:56', $sleepEnd->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-06 21:00:36', $inBedStart->utc()->format('Y-m-d H:i:s'));

        self::assertSame(120, $night->device_utc_offset_minutes);

        // Sleep never lands in health_metrics.
        self::assertSame(0, HealthMetric::query()->count());
    }

    /**
     * HAE's night date is device-local midnight; converting to UTC first would
     * move every record back a day.
     */
    public function test_the_night_key_is_haes_own_local_date_not_a_utc_derivation(): void
    {
        $this->process(PayloadBuilder::make()->sleep(nightDate: '2026-08-07'));

        self::assertSame('2026-08-07', SleepSession::query()->sole()->night_date->toDateString());
    }

    public function test_two_sources_may_report_the_same_night(): void
    {
        $this->process(PayloadBuilder::make()->sleep(source: PayloadBuilder::WATCH));
        $this->process(PayloadBuilder::make()->sleep(source: 'AutoSleep')->sessionId('OTHER'));

        self::assertSame(2, SleepSession::query()->where('night_date', '2026-08-07')->count());
    }

    public function test_missing_sleep_stages_stay_null(): void
    {
        $this->process(PayloadBuilder::make()->metric('sleep_analysis', 'hr', [[
            'date'       => '2026-08-07 00:00:00 +0200',
            'totalSleep' => 7.5,
            'source'     => PayloadBuilder::WATCH,
        ]]));

        $night = SleepSession::query()->sole();

        self::assertSame('450.000', $night->total_sleep_minutes);
        self::assertNull($night->rem_minutes);
        self::assertNull($night->sleep_start);
    }

    // ---------------------------------------------------------- unknown ------

    /**
     * An untaught shape must cost neither the rest of the payload nor next
     * hour's export.
     */
    public function test_an_unrecognised_shape_is_skipped_not_fatal(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 500)
            ->metric('mystery_metric', 'widgets', [[
                'date'         => '2026-08-07 10:00:00 +0200',
                'somethingNew' => 5,
                'source'       => PayloadBuilder::WATCH,
            ]])
            ->store();

        $result = app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(1, HealthMetric::query()->count());
        self::assertCount(1, $result->skipped);

        $run = IngestRun::query()->sole();
        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertStringContainsString('unrecognised datapoint shape', (string) $run->error);
    }

    public function test_a_payload_of_only_bad_datapoints_is_an_empty_run_not_a_failed_one(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', 'not a date', 500)
            ->store();

        $result = app(RawPayloadProcessor::class)->process($payload);

        self::assertTrue($result->producedNothing());
        self::assertSame(IngestRunStatus::Empty, IngestRun::query()->sole()->status);
    }
}
