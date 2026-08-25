<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Enums\MetricAggregation;
use App\Services\Ingest\Exceptions\UnknownUnitConversion;

/**
 * Everything the parser knows about a metric, in one place.
 *
 * Three questions, one class, because the answers are entangled: what
 * statistic is this value (upsert direction), what unit do we store it in
 * (whether a conversion runs), and is the metric cumulative (GREATEST vs
 * last-writer-wins). Splitting them across three files guarantees they drift.
 *
 * Canonical units convert on the way IN rather than deferring to
 * `daily_summaries`, because `raw_ingest_payloads` is permanent and is the
 * replay source — normalising downstream loses nothing — and a column
 * mixing kJ and kcal rows depending on firmware can't be summed without
 * every reader re-implementing the conversion. In practice this is two
 * metrics: active_energy and basal_energy_burned arrive in kJ (all 5052
 * captured datapoints) and are stored in kcal; sleep stage hours become
 * minutes on the way into `sleep_sessions`. Everything else is stored
 * exactly as delivered. A metric absent from the map keeps whatever unit it
 * arrives with — new HAE metrics must never need a migration or a code
 * change to be banked.
 */
final class MetricCatalog
{
    /**
     * metric => unit `health_metrics.value` is stored in.
     *
     * All 33 metrics are listed even where canonical == delivered, so "is
     * this known?" and "was its unit deliberate?" are the same question;
     * delivered units are noted where they differ.
     *
     * @var array<string, string>
     */
    public const CANONICAL_UNITS = [
        // --- converted -------------------------------------------------------
        'active_energy'       => 'kcal',              // delivered kJ
        'basal_energy_burned' => 'kcal',        // delivered kJ

        // --- stored as delivered --------------------------------------------
        'apple_exercise_time'              => 'min',
        'apple_sleeping_wrist_temperature' => 'degC',
        'apple_stand_hour'                 => 'count',
        'apple_stand_time'                 => 'min',
        'blood_oxygen_saturation'          => '%',
        'body_fat_percentage'              => '%',
        'body_mass_index'                  => 'count',
        'cardio_recovery'                  => 'count/min',
        'cycling_distance'                 => 'km',
        'environmental_audio_exposure'     => 'dBASPL',
        'flights_climbed'                  => 'count',
        'handwashing'                      => 's',
        'heart_rate'                       => 'count/min',
        'heart_rate_variability'           => 'ms',
        'lean_body_mass'                   => 'kg',
        'mindful_minutes'                  => 'min',
        'physical_effort'                  => 'kcal/hr·kg',
        'respiratory_rate'                 => 'count/min',
        'resting_heart_rate'               => 'count/min',
        'running_power'                    => 'W',
        'running_speed'                    => 'km/hr',
        'six_minute_walking_test_distance' => 'm',
        'step_count'                       => 'count',
        'vo2_max'                          => 'ml/(kg·min)',
        'walking_asymmetry_percentage'     => '%',
        'walking_heart_rate_average'       => 'count/min',
        'walking_running_distance'         => 'km',
        'walking_speed'                    => 'km/hr',
        'walking_step_length'              => 'cm',
        'weight_body_mass'                 => 'kg',

        // --- from the Fitage .xlsx importer, NOT from HAE --------------------
        //
        // The scale measures these by bioimpedance and its app never wrote
        // them to HealthKit, so no payload will ever carry them — they're
        // here because this class is the app's one answer to "what is this
        // metric and what unit is it in" (see App\Services\Fitage\FitageColumns).
        // Each is an instant, like every other body-composition metric: one
        // reading, taken the moment the user stood on the scale.
        //
        // basal_metabolic_rate is NOT basal_energy_burned: Apple's is
        // measured, hourly, cumulative, and feeds
        // `daily_summaries.resting_kcal`; this is the scale's one-shot
        // bioimpedance estimate of resting metabolism, kcal/day. Averaging
        // one into the other would corrupt TDEE with an unmeasured number —
        // config/health.php and DailySummaryBuilder both select by exact
        // name, never a prefix, as the defence.
        'basal_metabolic_rate'  => 'kcal',
        'body_water_percentage' => '%',
        'bone_mass'             => 'kg',
        'muscle_mass'           => 'kg',
        // A dimensionless index (observed range 3-5), stored 'count' like
        // body_mass_index: this schema's spelling of "no unit".
        'visceral_fat' => 'count',

        // --- not a scalar; see SleepAnalysisHandler --------------------------
        // sleep_analysis arrives in 'hr', lands in sleep_sessions (minutes)
        // — listed for inventory completeness, used as the sleep handler's
        // conversion target.
        'sleep_analysis' => 'min',              // delivered hr
    ];

    /**
     * Cumulative metrics: the value accumulates across the bucket, so a
     * re-exported partial trailing hour is legitimately LARGER than what
     * was stored, and the upsert must keep the maximum.
     *
     * Membership test: "would summing two halves of the bucket give the
     * bucket's value?" Steps, metres, joules, elapsed minutes: yes. Heart
     * rate, temperature, a percentage, a weight: no — GREATEST would
     * ratchet those upward forever, turning one spurious high reading into
     * a permanent floor. Borderline: apple_stand_hour (1 per stood-in hour,
     * halves add up), handwashing and mindful_minutes (seconds/minutes
     * accumulated in the bucket).
     *
     * @var list<string>
     */
    public const CUMULATIVE = [
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
    ];

    /**
     * Point-in-time samples: `started_at == ended_at`, last-writer-wins.
     *
     * Body composition is the obvious set (the spec names it); the three
     * one-off measurements (VO2 max, six-minute-walk, etc.) are readings
     * taken at an instant, not accumulated over an hour, so re-sending must
     * replace rather than ratchet. The Fitage-only metrics are instants
     * too: basal_metabolic_rate is a kcal/day RATE estimated at one moment,
     * not accumulated — classified as Sum it would ratchet upward forever
     * under GREATEST(), as an instant it simply restates.
     *
     * @var list<string>
     */
    public const INSTANT = [
        'basal_metabolic_rate',
        'body_fat_percentage',
        'body_mass_index',
        'body_water_percentage',
        'bone_mass',
        'cardio_recovery',
        'lean_body_mass',
        'muscle_mass',
        'six_minute_walking_test_distance',
        'visceral_fat',
        'vo2_max',
        'weight_body_mass',
    ];

    /** The night-record metric. Own table, own handler. */
    public const SLEEP = 'sleep_analysis';

    /**
     * Unit conversions, keyed "from>to". Deliberately tiny: an unlisted
     * conversion is an error, not a guess. kJ -> kcal is the thermochemical
     * calorie, 4.184 J exactly — Apple Health stores joules and HAE hands
     * them over unrounded, so this is the whole of the energy story.
     *
     * @var array<string, float>
     */
    private const FACTORS = [
        'kJ>kcal' => 1 / 4.184,
        'kcal>kJ' => 4.184,
        'hr>min'  => 60.0,
        'min>hr'  => 1 / 60.0,
    ];

    /**
     * Spellings seen or plausible for one unit, folded before lookup, so
     * "kJ"/"kj"/"KJ" aren't three different conversion problems.
     *
     * @var array<string, string>
     */
    private const UNIT_ALIASES = [
        'kj'      => 'kJ',
        'kcal'    => 'kcal',
        'cal'     => 'kcal',
        'hr'      => 'hr',
        'hrs'     => 'hr',
        'hour'    => 'hr',
        'hours'   => 'hr',
        'min'     => 'min',
        'mins'    => 'min',
        'minute'  => 'min',
        'minutes' => 'min',
    ];

    /**
     * The statistic a `qty` value represents for this metric.
     *
     * Defaults to Avg (last-writer-wins) on purpose: an unrecognised metric
     * classified as Avg is stored slightly imprecisely, but as Sum it would
     * ratchet upward on every partial-bucket re-send and quietly inflate an
     * unwatched total. Wrong-but-inert beats wrong-and-growing. heart_rate
     * never reaches this method — its Min/Avg/Max fields carry their own
     * statistic; see MinAvgMaxHandler.
     */
    public static function aggregationFor(string $metric): MetricAggregation
    {
        if (in_array($metric, self::CUMULATIVE, true)) {
            return MetricAggregation::Sum;
        }

        if (in_array($metric, self::INSTANT, true)) {
            return MetricAggregation::Instant;
        }

        return MetricAggregation::Avg;
    }

    public static function isCumulative(string $metric): bool
    {
        return in_array($metric, self::CUMULATIVE, true);
    }

    public static function isInstant(string $metric): bool
    {
        return in_array($metric, self::INSTANT, true);
    }

    /** Whether this metric appears in the inventory derived from real payloads. */
    public static function isKnown(string $metric): bool
    {
        return array_key_exists($metric, self::CANONICAL_UNITS);
    }

    /**
     * The unit this metric is stored in. Unknown metrics keep whatever the
     * device sent — banking a new metric must never require a code change.
     */
    public static function canonicalUnitFor(string $metric, string $deliveredUnit): string
    {
        return self::CANONICAL_UNITS[$metric] ?? self::normaliseUnit($deliveredUnit);
    }

    /**
     * Convert one delivered value into its canonical unit.
     *
     * @return array{0: float, 1: string} [value, canonical unit]
     *
     * @throws UnknownUnitConversion when the unit has no defined conversion
     *                               — the export format moved under us, and
     *                               storing it silently would corrupt the
     *                               column.
     */
    public static function toCanonical(string $metric, float $value, string $deliveredUnit): array
    {
        $delivered = self::normaliseUnit($deliveredUnit);
        $canonical = self::canonicalUnitFor($metric, $deliveredUnit);

        if ($delivered === $canonical) {
            return [$value, $canonical];
        }

        return [self::convert($value, $delivered, $canonical, $metric), $canonical];
    }

    /**
     * @throws UnknownUnitConversion
     */
    public static function convert(float $value, string $from, string $to, ?string $metric = null): float
    {
        $from = self::normaliseUnit($from);
        $to = self::normaliseUnit($to);

        if ($from === $to) {
            return $value;
        }

        $factor = self::FACTORS[$from.'>'.$to] ?? null;

        if ($factor === null) {
            throw UnknownUnitConversion::between($from, $to, $metric);
        }

        return $value * $factor;
    }

    /**
     * Fold a delivered unit string to its canonical spelling.
     *
     * Only units that participate in a conversion are aliased; everything
     * else round-trips untouched, since "%", "dBASPL" and "ml/(kg·min)" are
     * already exactly what we store, and normalising them would only risk
     * mangling a multi-byte middot.
     */
    private static function normaliseUnit(string $unit): string
    {
        $trimmed = trim($unit);

        return self::UNIT_ALIASES[mb_strtolower($trimmed)] ?? $trimmed;
    }
}
