<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use PHPUnit\Framework\TestCase;
use App\Enums\MetricAggregation;
use App\Services\Ingest\MetricCatalog;
use App\Services\Ingest\Exceptions\UnknownUnitConversion;

/**
 * The only place that knows what a metric IS, and so the only place a wrong
 * answer becomes 27 000 wrong rows.
 */
final class MetricCatalogTest extends TestCase
{
    /**
     * The metrics that arrive from a .xlsx exported out of the scale's own app
     * rather than from Health Auto Export. Listed separately from the observed
     * HAE inventory and asserted just as tightly: the catalog contains what a
     * real device really reported and NOTHING ELSE.
     *
     * @var list<string>
     */
    private const FROM_FITAGE_EXPORT = [
        'basal_metabolic_rate', 'body_water_percentage', 'bone_mass',
        'muscle_mass', 'visceral_fat',
    ];

    /**
     * Every metric observed in the captured payloads, and nothing invented: 33,
     * of which sleep_analysis is in the map (conversion target minutes) but is
     * not a `health_metrics` metric, so the scalar inventory is 32 plus sleep.
     */
    public function test_the_canonical_unit_map_covers_every_observed_metric(): void
    {
        $observed = [
            'active_energy', 'apple_exercise_time', 'apple_sleeping_wrist_temperature',
            'apple_stand_hour', 'apple_stand_time', 'basal_energy_burned',
            'blood_oxygen_saturation', 'body_fat_percentage', 'body_mass_index',
            'cardio_recovery', 'cycling_distance', 'environmental_audio_exposure',
            'flights_climbed', 'handwashing', 'heart_rate', 'heart_rate_variability',
            'lean_body_mass', 'mindful_minutes', 'physical_effort', 'respiratory_rate',
            'resting_heart_rate', 'running_power', 'running_speed',
            'six_minute_walking_test_distance', 'sleep_analysis', 'step_count',
            'vo2_max', 'walking_asymmetry_percentage', 'walking_heart_rate_average',
            'walking_running_distance', 'walking_speed', 'walking_step_length',
            'weight_body_mass',
        ];

        self::assertCount(33, $observed);

        foreach ($observed as $metric) {
            self::assertTrue(
                MetricCatalog::isKnown($metric),
                "Metric [{$metric}] is missing from the canonical unit map."
            );
        }

        // The observed HAE inventory plus the Fitage five, and nothing else:
        // both halves are enumerated, so anything from neither source fails here.
        self::assertSame(
            [],
            array_diff(
                array_keys(MetricCatalog::CANONICAL_UNITS),
                [...$observed, ...self::FROM_FITAGE_EXPORT],
            ),
            'The map lists a metric that no device has ever reported.'
        );
    }

    /**
     * The scale's five are all instants and none is cumulative.
     * basal_metabolic_rate is the one worth naming: it shares a unit with
     * Apple's basal_energy_burned and nothing else, and classified as cumulative
     * it would ratchet under GREATEST() into a permanent floor.
     */
    public function test_the_fitage_export_metrics_are_instants_and_never_cumulative(): void
    {
        foreach (self::FROM_FITAGE_EXPORT as $metric) {
            self::assertTrue(MetricCatalog::isKnown($metric), $metric);
            self::assertTrue(MetricCatalog::isInstant($metric), $metric);
            self::assertFalse(MetricCatalog::isCumulative($metric), $metric);
            self::assertSame(MetricAggregation::Instant, MetricCatalog::aggregationFor($metric), $metric);
        }

        // The metric they must never be confused with.
        self::assertFalse(MetricCatalog::isInstant('basal_energy_burned'));
        self::assertTrue(MetricCatalog::isCumulative('basal_energy_burned'));
    }

    /**
     * The only two conversions the export needs: 4.184 kJ per kcal, the
     * thermochemical calorie Apple Health stores.
     */
    public function test_energy_is_converted_from_kilojoules_to_kilocalories(): void
    {
        [$value, $unit] = MetricCatalog::toCanonical('active_energy', 4184.0, 'kJ');

        self::assertEqualsWithDelta(1000.0, $value, 1e-9);
        self::assertSame('kcal', $unit);

        [$value, $unit] = MetricCatalog::toCanonical('basal_energy_burned', 1337.383, 'kJ');

        self::assertEqualsWithDelta(319.6422084, $value, 1e-6);
        self::assertSame('kcal', $unit);
    }

    public function test_unit_spelling_does_not_change_the_answer(): void
    {
        foreach (['kJ', 'kj', 'KJ', ' kJ '] as $spelling) {
            [$value, $unit] = MetricCatalog::toCanonical('active_energy', 4184.0, $spelling);

            self::assertEqualsWithDelta(1000.0, $value, 1e-9, "Spelling [{$spelling}] was not folded.");
            self::assertSame('kcal', $unit);
        }
    }

    /** Energy already in kcal must pass straight through, not be scaled twice. */
    public function test_energy_already_canonical_is_not_converted(): void
    {
        [$value, $unit] = MetricCatalog::toCanonical('active_energy', 250.0, 'kcal');

        self::assertSame(250.0, $value);
        self::assertSame('kcal', $unit);
    }

    public function test_every_other_metric_keeps_the_unit_it_arrived_in(): void
    {
        $cases = [
            ['step_count', 'count'],
            ['walking_running_distance', 'km'],
            ['heart_rate', 'count/min'],
            ['weight_body_mass', 'kg'],
            ['environmental_audio_exposure', 'dBASPL'],
            ['physical_effort', 'kcal/hr·kg'],
            ['vo2_max', 'ml/(kg·min)'],
        ];

        foreach ($cases as [$metric, $unit]) {
            [$value, $canonical] = MetricCatalog::toCanonical($metric, 12.5, $unit);

            self::assertSame(12.5, $value, "[{$metric}] was converted when it should not have been.");
            self::assertSame($unit, $canonical);
        }
    }

    /** Banking a metric HAE has not sent before must never need a code change. */
    public function test_an_unknown_metric_keeps_its_delivered_unit(): void
    {
        [$value, $unit] = MetricCatalog::toCanonical('brand_new_apple_metric', 7.0, 'furlongs');

        self::assertSame(7.0, $value);
        self::assertSame('furlongs', $unit);
    }

    /**
     * A known metric in a unit with no conversion is worth failing loudly for:
     * guessing writes a wrong number into a column that reads right forever.
     */
    public function test_an_undefined_conversion_throws_rather_than_guessing(): void
    {
        $this->expectException(UnknownUnitConversion::class);

        MetricCatalog::toCanonical('weight_body_mass', 57.0, 'stone');
    }

    public function test_sleep_hours_convert_to_minutes(): void
    {
        self::assertEqualsWithDelta(
            352.322966,
            MetricCatalog::convert(5.8720494314697058, 'hr', 'min'),
            1e-6
        );
    }

    /**
     * The cumulative list decides which rows get GREATEST(): a non-cumulative
     * metric in here would ratchet one spurious reading into a permanent floor.
     */
    public function test_the_cumulative_list_is_exactly_the_accumulating_metrics(): void
    {
        self::assertSame([
            'active_energy',
            'apple_exercise_time',
            'apple_stand_hour',
            'apple_stand_time',
            'basal_energy_burned',
            'cycling_distance',
            'flights_climbed',
            'handwashing',
            'mindful_minutes',
            'step_count',
            'walking_running_distance',
        ], MetricCatalog::CUMULATIVE);

        foreach (MetricCatalog::CUMULATIVE as $metric) {
            self::assertSame(MetricAggregation::Sum, MetricCatalog::aggregationFor($metric));
            self::assertTrue(MetricCatalog::isCumulative($metric));
        }
    }

    public function test_rates_and_levels_are_never_cumulative(): void
    {
        foreach ([
            'heart_rate', 'heart_rate_variability', 'resting_heart_rate',
            'blood_oxygen_saturation', 'respiratory_rate', 'walking_speed',
            'apple_sleeping_wrist_temperature', 'weight_body_mass',
            'body_fat_percentage', 'body_mass_index', 'lean_body_mass',
        ] as $metric) {
            self::assertFalse(
                MetricCatalog::isCumulative($metric),
                "[{$metric}] must not upsert with GREATEST()."
            );
        }
    }

    public function test_point_in_time_metrics_are_instants(): void
    {
        foreach (MetricCatalog::INSTANT as $metric) {
            self::assertSame(MetricAggregation::Instant, MetricCatalog::aggregationFor($metric));
        }

        self::assertContains('weight_body_mass', MetricCatalog::INSTANT);
        self::assertContains('body_fat_percentage', MetricCatalog::INSTANT);
        self::assertContains('body_mass_index', MetricCatalog::INSTANT);
        self::assertContains('lean_body_mass', MetricCatalog::INSTANT);
    }

    /** Wrong-but-inert beats wrong-and-growing: unclassified metrics average. */
    public function test_an_unclassified_metric_defaults_to_last_writer_wins(): void
    {
        self::assertSame(MetricAggregation::Avg, MetricCatalog::aggregationFor('brand_new_apple_metric'));
        self::assertFalse(MetricCatalog::isCumulative('brand_new_apple_metric'));
    }
}
