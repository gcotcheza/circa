<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\Source;
use App\Enums\DeviceKind;
use App\Models\HealthMetric;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Apple's device strings are hostile to string matching, and `sources` exists so
 * that hostility never reaches the rest of the app. A source that forks is worse
 * than one that is wrong: priority config keys on device_kind, rollups pick ONE
 * source per bucket, and a watch existing twice under two spellings defeats both.
 */
final class SourceNormalisationTest extends TestCase
{
    use RefreshDatabase;

    private int $session = 0;

    private function send(PayloadBuilder $builder): void
    {
        app(RawPayloadProcessor::class)->process(
            $builder->sessionId('SESSION-'.++$this->session)->store()
        );
    }

    private function steps(string $source, string $hour = '10'): PayloadBuilder
    {
        return PayloadBuilder::make()->quantity(
            'step_count',
            'count',
            "2026-08-07 {$hour}:00:00 +0200",
            100,
            $source
        );
    }

    /**
     * "Demo’s Apple Watch" arrives with U+2019 and U+00A0; typed by a human it
     * is ASCII. One watch, one row.
     */
    public function test_curly_apostrophe_and_no_break_space_variants_collapse_to_one_source(): void
    {
        $this->send($this->steps("Demo\u{2019}s Apple\u{00A0}Watch", '10'));
        $this->send($this->steps("Demo's Apple Watch", '11'));
        $this->send($this->steps("Demo\u{2018}s Apple Watch", '12'));
        $this->send($this->steps("Demo\u{2019}s   Apple\u{00A0}Watch  ", '13'));

        self::assertSame(1, Source::query()->count());
        self::assertSame(DeviceKind::Watch, Source::query()->sole()->device_kind);
        self::assertSame(1, HealthMetric::query()->distinct()->count('source_id'));

        // raw_name is the first spelling seen, so the row still round-trips to
        // a real payload.
        self::assertSame("Demo\u{2019}s Apple\u{00A0}Watch", Source::query()->sole()->raw_name);
    }

    /**
     * HAE pipe-joins contributing devices inconsistently — both "A|B" and
     * "B | A" appear in the captured payloads. Same contributors, same source.
     */
    public function test_composite_spacing_and_ordering_do_not_fork_a_source(): void
    {
        $watch = "Demo\u{2019}s Apple\u{00A0}Watch";

        $this->send($this->steps($watch.'|Blood Oxygen', '10'));
        $this->send($this->steps('Blood Oxygen | '.$watch, '11'));
        $this->send($this->steps($watch.' | Blood Oxygen', '12'));

        self::assertSame(1, Source::query()->count());
        self::assertSame(DeviceKind::Composite, Source::query()->sole()->device_kind);
    }

    public function test_a_composite_stays_distinct_from_its_parts(): void
    {
        $this->send($this->steps(PayloadBuilder::WATCH, '10'));
        $this->send($this->steps(PayloadBuilder::PHONE, '11'));
        $this->send($this->steps(PayloadBuilder::COMPOSITE, '12'));

        self::assertSame(3, Source::query()->count());

        self::assertEqualsCanonicalizing(
            [DeviceKind::Watch, DeviceKind::Phone, DeviceKind::Composite],
            Source::query()->get()->pluck('device_kind')->all()
        );
    }

    /**
     * Every apple_stand_hour datapoint carries source "" — a real, frequent
     * value — and health_metrics.source_id is NOT NULL, so it needs a real row.
     */
    public function test_the_empty_source_string_becomes_one_unknown_source(): void
    {
        $this->send($this->steps('', '10'));
        $this->send($this->steps('   ', '11'));

        $source = Source::query()->sole();

        self::assertSame(DeviceKind::Unknown, $source->device_kind);
        self::assertSame('unknown', $source->slug);
        self::assertSame('', $source->raw_name);
        self::assertSame('Unknown source', $source->name);

        self::assertSame(2, HealthMetric::query()->count());
        // Not just "non-null" (source_id is NOT NULL in the schema anyway) —
        // linked to the specific unknown-source row asserted above.
        self::assertSame($source->id, HealthMetric::query()->firstOrFail()->source_id);
    }

    public function test_a_missing_source_key_is_treated_as_unknown(): void
    {
        $this->send(PayloadBuilder::make()->metric('apple_stand_hour', 'count', [[
            'date' => '2026-08-07 10:00:00 +0200',
            'qty'  => 1,
        ]]));

        self::assertSame(DeviceKind::Unknown, Source::query()->sole()->device_kind);
    }

    /**
     * FITAGE and Fitdays are two apps in front of one bathroom scale: different
     * names, so different rows, but both must classify as `scale`, because that
     * is what config('health.source_priority') targets.
     */
    public function test_both_scale_apps_classify_as_the_scale(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('weight_body_mass', 'kg', '2026-05-03 12:00:00 +0200', 57.0, 'FITAGE')
        );
        $this->send(
            PayloadBuilder::make()
                ->quantity('weight_body_mass', 'kg', '2026-07-11 16:00:00 +0200', 56.4, 'Fitdays')
        );

        $sources = Source::query()->orderBy('raw_name')->get();

        self::assertCount(2, $sources);
        self::assertSame([DeviceKind::Scale, DeviceKind::Scale], $sources->pluck('device_kind')->all());
        self::assertSame(['FITAGE', 'Fitdays'], $sources->pluck('raw_name')->all());
    }

    public function test_a_source_is_created_once_and_reused_across_payloads(): void
    {
        $this->send($this->steps(PayloadBuilder::WATCH, '10'));
        $id = Source::query()->sole()->id;

        $this->send($this->steps(PayloadBuilder::WATCH, '11'));

        self::assertSame(1, Source::query()->count());
        self::assertSame([$id, $id], HealthMetric::query()->orderBy('id')->pluck('source_id')->all());
    }

    public function test_third_party_apps_classify_as_apps(): void
    {
        $this->send(PayloadBuilder::make()->sleep(source: 'AutoSleep'));

        self::assertSame(DeviceKind::App, Source::query()->sole()->device_kind);
    }
}
