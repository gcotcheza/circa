<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\ParseResult;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The direction the spec left unspecified, and the reason the trailing hour of
 * every export is not a bug.
 *
 * The phone syncs at 10:20 and says "400 steps in the 10:00 hour". It syncs
 * again at 11:05 and says "1200 steps in the 10:00 hour". Both are honest. The
 * second is more complete. Which one survives is the entire question.
 */
final class UpsertSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private int $session = 0;

    private function send(PayloadBuilder $builder): ParseResult
    {
        // A distinct session id per send: ingest_runs is keyed (session_id,
        // sha256(body)), and these tests re-send bodies differing in one number.
        return app(RawPayloadProcessor::class)->process(
            $builder->sessionId('SESSION-'.++$this->session)->store()
        );
    }

    private function steps(float $qty, string $source = PayloadBuilder::WATCH): PayloadBuilder
    {
        return PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', $qty, $source);
    }

    // ------------------------------------------------------- cumulative -----

    public function test_a_partial_hour_resent_larger_grows_the_row(): void
    {
        $this->send($this->steps(400));
        $result = $this->send($this->steps(1200));

        self::assertSame('1200.000000', HealthMetric::query()->sole()->value);
        self::assertSame(1, $result->metrics->updated);
        self::assertSame(0, $result->metrics->inserted);
    }

    /**
     * The one that matters: a later export can cover a SMALLER slice of the same
     * hour, and taking it would silently delete steps that really happened.
     */
    public function test_a_complete_hour_is_never_overwritten_downward(): void
    {
        $this->send($this->steps(1200));
        $result = $this->send($this->steps(400));

        self::assertSame('1200.000000', HealthMetric::query()->sole()->value);

        // Not merely "the value is the same" — the row was not written at all.
        self::assertSame(0, $result->metrics->touched());
        self::assertSame(1, $result->metrics->unchanged);
    }

    public function test_every_cumulative_metric_uses_greatest(): void
    {
        $bucket = '2026-08-07 10:00:00 +0200';

        $units = [
            'active_energy'            => 'kJ',
            'apple_exercise_time'      => 'min',
            'apple_stand_hour'         => 'count',
            'apple_stand_time'         => 'min',
            'basal_energy_burned'      => 'kJ',
            'cycling_distance'         => 'km',
            'flights_climbed'          => 'count',
            'handwashing'              => 's',
            'mindful_minutes'          => 'min',
            'step_count'               => 'count',
            'walking_running_distance' => 'km',
        ];

        $big = PayloadBuilder::make();
        $small = PayloadBuilder::make();

        foreach ($units as $metric => $unit) {
            $big->quantity($metric, $unit, $bucket, 100.0);
            $small->quantity($metric, $unit, $bucket, 10.0);
        }

        $this->send($big);
        $result = $this->send($small);

        self::assertSame(0, $result->metrics->touched(), 'A smaller re-send changed a cumulative row.');
        self::assertSame(count($units), HealthMetric::query()->count());

        foreach (array_keys($units) as $metric) {
            $row = HealthMetric::query()->where('metric', $metric)->sole();
            self::assertGreaterThan(10.0, (float) $row->value, "[{$metric}] was overwritten downward.");
        }
    }

    // -------------------------------------------------- last-writer-wins ----

    public function test_an_instant_sample_takes_the_newest_value_in_either_direction(): void
    {
        $weight = static fn (float $kg): PayloadBuilder => PayloadBuilder::make()
            ->quantity('weight_body_mass', 'kg', '2026-05-03 12:00:00 +0200', $kg, 'FITAGE');

        $this->send($weight(57.0));
        $this->send($weight(56.4));

        self::assertSame('56.400000', HealthMetric::query()->sole()->value);

        $this->send($weight(57.2));

        self::assertSame('57.200000', HealthMetric::query()->sole()->value);
        self::assertSame(1, HealthMetric::query()->count());
    }

    /**
     * GREATEST on a maximum is a trap: a re-exported hour covering fewer samples
     * legitimately reports a lower Max, and ratcheting would make one spurious
     * spike permanent.
     */
    public function test_a_heart_rate_maximum_may_go_down(): void
    {
        $this->send(PayloadBuilder::make()->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74, 180));
        $this->send(PayloadBuilder::make()->minAvgMax('2026-08-07 10:00:00 +0200', 55, 72, 120));

        $rows = HealthMetric::query()->get()->keyBy(fn ($m) => $m->aggregation->value);

        [$max, $min, $avg] = [$rows->get('max'), $rows->get('min'), $rows->get('avg')];
        self::assertNotNull($max);
        self::assertNotNull($min);
        self::assertNotNull($avg);

        self::assertSame('120.000000', $max->value);
        self::assertSame('55.000000', $min->value);
        self::assertSame('72.000000', $avg->value);
    }

    // ------------------------------------------------------ idempotence -----

    public function test_reprocessing_an_identical_payload_writes_nothing(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->quantity('active_energy', 'kJ', '2026-08-07 10:00:00 +0200', 900.5)
            ->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74.36, 118)
            ->sleep()
            ->store();

        $first = app(RawPayloadProcessor::class)->process($payload);
        $second = app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(5, $first->metrics->inserted);
        self::assertSame(1, $first->sleep->inserted);

        self::assertSame(0, $second->metrics->touched());
        self::assertSame(0, $second->sleep->touched());
        self::assertSame(5, $second->metrics->unchanged);
        self::assertSame(1, $second->sleep->unchanged);

        // row_count is rows REPRESENTED, so a replay is not a zero-row gap.
        self::assertSame($first->rowCount(), $second->rowCount());
    }

    /**
     * A multi-row INSERT ... ON CONFLICT fails outright if two VALUES tuples
     * share the conflict key, so duplicates inside one payload are collapsed
     * before the statement is built.
     */
    public function test_duplicate_datapoints_inside_one_payload_are_absorbed_silently(): void
    {
        $bucket = '2026-08-07 10:00:00 +0200';

        $result = $this->send(
            PayloadBuilder::make()->metric('step_count', 'count', [
                ['date' => $bucket, 'qty' => 400, 'source' => PayloadBuilder::WATCH],
                ['date' => $bucket, 'qty' => 1200, 'source' => PayloadBuilder::WATCH],
                ['date' => $bucket, 'qty' => 900, 'source' => PayloadBuilder::WATCH],
            ])
        );

        self::assertSame(1, HealthMetric::query()->count());
        // Collapsed the way the database would have: keep the largest.
        self::assertSame('1200.000000', HealthMetric::query()->sole()->value);
        self::assertSame(1, $result->metrics->inserted);
        self::assertSame(3, $result->datapoints);
    }

    public function test_duplicate_instants_inside_one_payload_keep_the_last(): void
    {
        $result = $this->send(
            PayloadBuilder::make()->metric('weight_body_mass', 'kg', [
                ['date' => '2026-05-03 12:00:00 +0200', 'qty' => 57.0, 'source' => 'FITAGE'],
                ['date' => '2026-05-03 12:00:00 +0200', 'qty' => 56.4, 'source' => 'FITAGE'],
            ])
        );

        self::assertSame('56.400000', HealthMetric::query()->sole()->value);
        self::assertSame(1, $result->metrics->inserted);
    }

    public function test_duplicate_nights_inside_one_payload_are_absorbed(): void
    {
        $record = fn (float $total): array => [
            'date'       => '2026-08-07 00:00:00 +0200',
            'totalSleep' => $total,
            'source'     => PayloadBuilder::WATCH,
        ];

        $this->send(
            PayloadBuilder::make()->metric('sleep_analysis', 'hr', [$record(7.0), $record(8.0)])
        );

        self::assertSame('480.000', SleepSession::query()->sole()->total_sleep_minutes);
    }

    // ------------------------------------------------------ separation ------

    /**
     * The Watch and the phone both report the same hour as separate legitimate
     * rows; the read side picks one by priority. Merging them here would double
     * every step count in the database.
     */
    public function test_two_sources_reporting_one_bucket_are_two_rows(): void
    {
        $this->send($this->steps(1200, PayloadBuilder::WATCH));
        $this->send($this->steps(1150, PayloadBuilder::PHONE));

        self::assertSame(2, HealthMetric::query()->where('metric', 'step_count')->count());
    }

    public function test_different_buckets_never_collide(): void
    {
        $this->send($this->steps(400));
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-08-07 11:00:00 +0200', 900)
        );

        self::assertSame(2, HealthMetric::query()->count());
    }

    /**
     * A night is replaced whole, never merged column by column. WHEN it may be
     * replaced has a longer answer — see SleepTruncationTest.
     */
    public function test_a_sleep_night_is_restated_wholesale(): void
    {
        $this->send(PayloadBuilder::make()->sleep(overrides: ['totalSleep' => 7.0, 'rem' => 1.0]));
        $result = $this->send(PayloadBuilder::make()->sleep(overrides: ['totalSleep' => 8.5, 'rem' => 1.5]));

        $night = SleepSession::query()->sole();

        self::assertSame('510.000', $night->total_sleep_minutes);
        self::assertSame('90.000', $night->rem_minutes);
        self::assertSame(1, $result->sleep->updated);
    }
}
