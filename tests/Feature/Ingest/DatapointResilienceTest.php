<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\Source;
use App\Models\Workout;
use App\Models\IngestRun;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\IngestRunStatus;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ONE BAD DATAPOINT MUST NOT COST THE OTHER 250.
 *
 * PayloadParser has always caught MalformedDatapoint per datapoint. Four input
 * classes escaped that catch, failed the DB::transaction and threw away every
 * good reading in the same POST: a number outside numeric(16,6) (`(float)`
 * makes "1e400" INF, which Postgres refuses); a unit with no conversion
 * (UnknownUnitConversion extended RuntimeException, not MalformedDatapoint); a
 * metric name, unit or bucket width past its varchar; a device name whose slug
 * exceeded sources.slug(128). The other two writers get the same treatment — a
 * sleep stage outside numeric(9,3), a workout name or HealthKit id past
 * varchar(64) — since a fix stopping at `health_metrics` would only move the
 * failure to the next table an export reached.
 *
 * NONE OF THESE NEEDS AN ATTACKER: each is what a legitimate export looks like
 * the week Health Auto Export ships a new metric, renames a unit or the user
 * renames a watch, so an unfixed one loses a week of history quietly. Every
 * case here puts the offending datapoint FIRST, the ordering that used to
 * guarantee the loss.
 */
final class DatapointResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const HOUR = '2026-08-07 10:00:00 +0200';

    private const NEXT_HOUR = '2026-08-07 11:00:00 +0200';

    /** `qty: "1e400"` is INF as a float, and numeric(16,6) cannot hold it. */
    public function test_an_unstorable_number_costs_one_datapoint(): void
    {
        $payload = PayloadBuilder::make()
            ->metric('step_count', 'count', [
                ['date' => self::HOUR, 'qty' => '1e400', 'source' => PayloadBuilder::WATCH],
                ['date' => self::NEXT_HOUR, 'qty' => 1200, 'source' => PayloadBuilder::WATCH],
            ])
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertSame(1, HealthMetric::query()->count());
        self::assertSame('1200.000000', HealthMetric::query()->sole()->value);

        // Named by shape, never by value: `ingest_runs.error` is not for readings.
        self::assertStringContainsString('Skipped 1 datapoint(s)', (string) $run->error);
        self::assertStringNotContainsString('1e400', (string) $run->error);
    }

    /** Still not written — but it now costs one datapoint, not the POST. */
    public function test_an_unconvertible_unit_costs_one_datapoint(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('active_energy', 'furlongs', self::HOUR, 900)
            ->quantity('step_count', 'count', self::NEXT_HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(1, HealthMetric::query()->count());
        self::assertSame('step_count', HealthMetric::query()->sole()->metric);
        self::assertStringContainsString('No conversion from', (string) IngestRun::query()->sole()->error);
    }

    /**
     * `health_metrics.metric` is varchar(64), filled from `data.metrics[].name`
     * straight off the wire. Folded rather than skipped: the READING is fine,
     * and the raw payload keeps the full spelling.
     */
    public function test_an_over_long_metric_name_is_folded_and_the_payload_lands(): void
    {
        $long = str_repeat('n', 200);

        $payload = PayloadBuilder::make()
            ->quantity($long, 'count', self::HOUR, 7)
            ->quantity('step_count', 'count', self::NEXT_HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(2, HealthMetric::query()->count());
        self::assertSame(
            mb_substr($long, 0, 64),
            HealthMetric::query()->where('metric', 'like', 'n%')->sole()->metric
        );
    }

    /** Same for the unit: varchar(32), kept verbatim for metrics MetricCatalog does not know. */
    public function test_an_over_long_unit_is_folded(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('a_metric_nobody_has_seen', str_repeat('u', 90), self::HOUR, 7)
            ->quantity('step_count', 'count', self::NEXT_HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(2, HealthMetric::query()->count());
        self::assertSame(
            32,
            mb_strlen((string) HealthMetric::query()->where('metric', 'a_metric_nobody_has_seen')->sole()->unit)
        );
    }

    /**
     * `period` is varchar(16) from the `automation-aggregation` header, kept
     * verbatim when unrecognised (BucketWidth) — and a header is a string typed
     * into a text box on a phone.
     */
    public function test_an_over_long_bucket_width_header_is_folded(): void
    {
        $payload = PayloadBuilder::make()
            ->header('automation-aggregation', str_repeat('w', 60))
            ->quantity('step_count', 'count', self::HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(16, mb_strlen((string) HealthMetric::query()->sole()->period));
    }

    /**
     * A device name is user-editable and `sources.slug` is varchar(128); an
     * over-long one used to throw out of SourceResolver with the payload.
     */
    public function test_an_over_long_device_name_still_resolves_to_a_source(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', self::HOUR, 1200, source: str_repeat('D', 400))
            ->quantity('heart_rate', 'count/min', self::NEXT_HOUR, 61)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(2, HealthMetric::query()->count());

        // Anchored on the repeated character, not a bare "d%": the payload
        // builder's own default device also slugs to a d-word.
        $source = Source::query()->where('slug', 'like', 'dddd%')->sole();

        self::assertLessThanOrEqual(128, mb_strlen((string) $source->slug));
        self::assertLessThanOrEqual(191, mb_strlen((string) $source->raw_name));
    }

    /**
     * `sleep_sessions` stage columns are numeric(9,3), fed fractional hours
     * multiplied by 60. A night is one datapoint, so an unstorable stage costs
     * the night, not the payload.
     */
    public function test_an_unstorable_sleep_stage_costs_one_night(): void
    {
        $payload = PayloadBuilder::make()
            ->sleep(overrides: ['rem' => '1e400'])
            ->quantity('step_count', 'count', self::HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(0, SleepSession::query()->count());
        self::assertSame(1, HealthMetric::query()->count());
    }

    /**
     * `workouts.type` is varchar(64) holding HAE's `name` verbatim, so a new
     * activity lands without a migration. Folded like a metric name: an hour of
     * training is not lost over a label.
     */
    public function test_an_over_long_workout_name_is_folded_and_the_session_lands(): void
    {
        $payload = PayloadBuilder::make()
            ->workout(name: str_repeat('R', 200))
            ->quantity('step_count', 'count', self::HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame(64, mb_strlen((string) Workout::query()->sole()->type));
        self::assertSame(1, HealthMetric::query()->count());
    }

    /**
     * THE UUID IS THE EXCEPTION, deliberately the opposite of the name beside
     * it: `apple_uuid` is the idempotency key, so an over-long one is skipped,
     * not folded. Two sessions truncated to the same 64 characters would
     * collapse into one row — a silent loss no re-export would repair.
     */
    public function test_an_over_long_workout_id_skips_the_session_and_not_the_payload(): void
    {
        $payload = PayloadBuilder::make()
            ->workout(uuid: str_repeat('E', 200))
            ->quantity('step_count', 'count', self::HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertSame(0, Workout::query()->count());
        self::assertSame(1, HealthMetric::query()->count());
        self::assertStringContainsString('Skipped 1 datapoint(s)', (string) $run->error);
    }

    /** All of them at once, ahead of the good data: "the export format moved". */
    public function test_a_payload_of_nothing_but_new_shapes_still_lands_its_good_rows(): void
    {
        $payload = PayloadBuilder::make()
            ->metric('step_count', 'count', [
                ['date' => self::HOUR, 'qty' => '1e400', 'source' => PayloadBuilder::WATCH],
            ])
            ->quantity('active_energy', 'furlongs', self::HOUR, 900)
            ->quantity(str_repeat('n', 200), 'count', self::HOUR, 7)
            ->quantity('step_count', 'count', self::NEXT_HOUR, 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        // The folded one and the good one; the other two are skips.
        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertSame(2, HealthMetric::query()->count());
        self::assertSame(2, $run->row_count);
        self::assertStringContainsString('Skipped 2 datapoint(s)', (string) $run->error);
    }
}
