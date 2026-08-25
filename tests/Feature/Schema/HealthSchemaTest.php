<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Tests\TestCase;
use App\Models\Source;
use App\Enums\DeviceKind;
use App\Models\IngestRun;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\IngestRunStatus;
use App\Enums\MetricAggregation;
use App\Models\RawIngestPayload;
use Database\Factories\SourceFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema-level guarantees for the health side, cheap on purpose: constraints
 * exist and fire, relations resolve, casts round-trip. The real work — upsert
 * direction, source priority, DST buckets, gap detection — belongs to the step-2
 * pipeline tests, where there is a parser to test it against.
 */
final class HealthSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_normalises_apples_hostile_device_strings(): void
    {
        // U+2019 apostrophe and U+00A0 between "Apple" and "Watch": what arrives,
        // and what naive string matching gets wrong.
        self::assertSame('demos-apple-watch', Source::slugFor(SourceFactory::WATCH));
        self::assertSame(DeviceKind::Watch, Source::kindFor(SourceFactory::WATCH));

        self::assertSame(DeviceKind::Phone, Source::kindFor(SourceFactory::PHONE));

        // Two apps, one bathroom scale: both must land on the kind that
        // config('health.source_priority') targets.
        self::assertSame(DeviceKind::Scale, Source::kindFor('FITAGE'));
        self::assertSame(DeviceKind::Scale, Source::kindFor('Fitdays'));

        // HAE pipe-joins contributing devices when it summarises.
        self::assertSame(DeviceKind::Composite, Source::kindFor(SourceFactory::COMPOSITE));

        // Every apple_stand_hour datapoint has an empty source string.
        self::assertSame(DeviceKind::Unknown, Source::kindFor(''));
        self::assertSame('unknown', Source::slugFor(''));
    }

    public function test_empty_source_string_is_storable_and_unique(): void
    {
        $source = Source::factory()->empty()->create();

        self::assertSame('', $source->raw_name);
        self::assertSame(DeviceKind::Unknown, $this->notNull($source->fresh())->device_kind);

        $this->expectException(QueryException::class);
        Source::factory()->empty()->create();
    }

    public function test_local_date_is_generated_in_the_app_timezone(): void
    {
        $source = Source::factory()->watch()->create();

        // 22:00 UTC on 6 Aug is 00:00 on 7 Aug in CEST, and the day boundary must
        // come from the database rather than from PHP.
        $metric = HealthMetric::factory()->fromSource($source)->create([
            'started_at' => CarbonImmutable::parse('2026-08-06 22:00:00', 'UTC'),
            'ended_at'   => CarbonImmutable::parse('2026-08-06 23:00:00', 'UTC'),
        ]);

        self::assertSame('2026-08-07', $this->notNull($metric->fresh())->local_date->toDateString());

        // And the winter side of the DST switch, where the offset is +01:00.
        $winter = HealthMetric::factory()->fromSource($source)->create([
            'started_at' => CarbonImmutable::parse('2025-12-31 23:30:00', 'UTC'),
            'ended_at'   => CarbonImmutable::parse('2026-01-01 00:30:00', 'UTC'),
        ]);

        self::assertSame('2026-01-01', $this->notNull($winter->fresh())->local_date->toDateString());
    }

    public function test_identical_identity_tuple_violates_the_unique_key(): void
    {
        $source = Source::factory()->watch()->create();
        $bucket = CarbonImmutable::parse('2026-08-07 10:00:00', 'UTC');

        HealthMetric::factory()->fromSource($source)->inHourBucket($bucket)->create();

        $this->expectException(QueryException::class);

        HealthMetric::factory()->fromSource($source)->inHourBucket($bucket)->create();
    }

    public function test_instants_collide_even_though_the_spec_called_the_bounds_nullable(): void
    {
        // The whole reason started_at/ended_at are NOT NULL: with a nullable
        // ended_at, Postgres treats NULL != NULL inside a unique index and this
        // second insert would duplicate the weigh-in instead of upserting it.
        $scale = Source::factory()->scale()->create();
        $at = CarbonImmutable::parse('2026-07-11 14:00:00', 'UTC');

        $first = HealthMetric::factory()
            ->fromSource($scale)
            ->metric('weight_body_mass')
            ->create(['started_at' => $at, 'ended_at' => $at]);

        self::assertTrue($first->isInstant());
        self::assertTrue($first->started_at->equalTo($first->ended_at));

        $this->expectException(QueryException::class);

        HealthMetric::factory()
            ->fromSource($scale)
            ->metric('weight_body_mass')
            ->create(['started_at' => $at, 'ended_at' => $at]);
    }

    public function test_heart_rate_min_avg_max_coexist_in_one_bucket(): void
    {
        $source = Source::factory()->watch()->create();
        $bucket = CarbonImmutable::parse('2026-08-07 10:00:00', 'UTC');

        foreach ([MetricAggregation::Min, MetricAggregation::Avg, MetricAggregation::Max] as $aggregation) {
            HealthMetric::factory()->fromSource($source)->inHourBucket($bucket)->create([
                'metric'      => 'heart_rate',
                'unit'        => 'count/min',
                'aggregation' => $aggregation,
                'value'       => 74.36,
            ]);
        }

        self::assertSame(3, HealthMetric::where('metric', 'heart_rate')->count());
    }

    public function test_watch_and_phone_report_the_same_bucket_as_separate_rows(): void
    {
        // Both are legitimate: the read side picks one by priority and must never
        // SUM them, which is why they may coexist.
        $bucket = CarbonImmutable::parse('2026-08-07 10:00:00', 'UTC');

        HealthMetric::factory()
            ->fromSource(Source::factory()->watch()->create())
            ->inHourBucket($bucket)->create();

        HealthMetric::factory()
            ->fromSource(Source::factory()->phone()->create())
            ->inHourBucket($bucket)->create();

        self::assertSame(2, HealthMetric::where('metric', 'step_count')->count());
    }

    public function test_health_metric_casts_and_relation_resolve(): void
    {
        $source = Source::factory()->watch()->create();

        $metric = HealthMetric::factory()->fromSource($source)->metric('active_energy')->create();
        $fresh = $this->notNull($metric->fresh());

        self::assertInstanceOf(MetricAggregation::class, $fresh->aggregation);
        self::assertSame(MetricAggregation::Sum, $fresh->aggregation);
        self::assertInstanceOf(CarbonImmutable::class, $fresh->started_at);
        self::assertInstanceOf(CarbonImmutable::class, $fresh->local_date);

        // `unit` holds the CANONICAL unit. Step 1 stored the device unit and
        // converted on read; step 2 reversed that, because raw_ingest_payloads
        // makes the conversion reproducible while a value column mixing kJ and
        // kcal rows cannot be summed. Energy arrives in kJ and lands here in kcal.
        self::assertSame('kcal', $fresh->unit);

        self::assertTrue($this->notNull($fresh->source)->is($source));
        self::assertTrue($this->notNull($source->healthMetrics->first())->is($metric));
    }

    public function test_two_sources_may_report_the_same_night_but_not_twice_each(): void
    {
        $watch = Source::factory()->watch()->create();
        // "AutoSleep" was observed reporting one night alongside the Watch.
        $app = Source::factory()->scale('AutoSleep')->create();

        SleepSession::factory()->fromSource($watch)->forNight('2026-08-07')->create();
        SleepSession::factory()->fromSource($app)->forNight('2026-08-07')->create();

        self::assertSame(2, SleepSession::where('night_date', '2026-08-07')->count());

        $this->expectException(QueryException::class);

        SleepSession::factory()->fromSource($watch)->forNight('2026-08-07')->create();
    }

    public function test_sleep_session_totals_and_relation(): void
    {
        $watch = Source::factory()->watch()->create();
        $session = SleepSession::factory()->fromSource($watch)->create([
            'rem_minutes'         => 115.271,
            'core_minutes'        => 352.323,
            'deep_minutes'        => 60.637,
            'awake_minutes'       => 40.103,
            'total_sleep_minutes' => 528.231,
        ]);

        $fresh = $this->notNull($session->fresh());

        // HAE's totalSleep excludes awake: 115.271 + 352.323 + 60.637 ≈ 528.231.
        self::assertEqualsWithDelta(8.804, $fresh->totalSleepHours(), 0.001);
        self::assertInstanceOf(CarbonImmutable::class, $fresh->night_date);
        self::assertNull($fresh->asleep_minutes);
        self::assertTrue($this->notNull($fresh->source)->is($watch));
    }

    public function test_ingest_run_is_keyed_on_session_and_body_hash(): void
    {
        $payload = RawIngestPayload::factory()->create();

        $run = IngestRun::factory()->create([
            'raw_ingest_payload_id' => $payload->id,
            'session_id'            => '0BC2933C-4D4E-4AB3-BEDC-9545AA170FA5',
            'body_sha256'           => hash('sha256', 'body-a'),
        ]);

        self::assertTrue($this->notNull($run->rawIngestPayload)->is($payload));
        self::assertSame(IngestRunStatus::Completed, $this->notNull($run->fresh())->status);

        // Same session, different body: a batched export legitimately reuses the id.
        IngestRun::factory()->create([
            'session_id'  => '0BC2933C-4D4E-4AB3-BEDC-9545AA170FA5',
            'body_sha256' => hash('sha256', 'body-b'),
        ]);

        self::assertSame(2, IngestRun::count());

        $this->expectException(QueryException::class);

        IngestRun::factory()->create([
            'session_id'  => '0BC2933C-4D4E-4AB3-BEDC-9545AA170FA5',
            'body_sha256' => hash('sha256', 'body-a'),
        ]);
    }

    public function test_runs_that_produced_nothing_are_findable(): void
    {
        IngestRun::factory()->count(2)->create();
        IngestRun::factory()->producedNothing()->create();

        self::assertSame(1, IngestRun::producedNothing()->count());
        self::assertSame(3, IngestRun::succeeded()->count());
    }
}
