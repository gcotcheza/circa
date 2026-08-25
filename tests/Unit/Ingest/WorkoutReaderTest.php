<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use Tests\TestCase;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\WorkoutRow;
use App\Services\Ingest\WorkoutReader;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\Ingest\Exceptions\MalformedDatapoint;
use App\Services\Ingest\Exceptions\UnknownUnitConversion;

/**
 * One `data.workouts[]` record -> a WorkoutRow. The fixtures are PayloadBuilder's
 * real 31-key shape with invented values — see its docblock for why the
 * coordinates are not real ones.
 */
final class WorkoutReaderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function read(array $overrides = []): WorkoutRow
    {
        $body = PayloadBuilder::make()->workout(overrides: $overrides)->body();

        return (new WorkoutReader)->read($body['data']['workouts'][0]);
    }

    /**
     * THE STEP-1 GOTCHA, IN A NEW PLACE. The export sends 1424 for an easy 6 km
     * run: impossible as kcal for a ~56 kg person, but 340 kcal as kJ, exactly
     * what a 50-minute easy run costs. The column is kcal, and the conversion is
     * the metrics pipeline's own thermochemical 4.184 — one statement of what a
     * kilojoule is worth, or eventually two different ones.
     */
    public function test_energy_is_converted_from_the_delivered_kilojoules_to_kcal(): void
    {
        $row = $this->read();

        self::assertSame('340.344', $row->activeKcal);
        self::assertEqualsWithDelta(1424 / 4.184, (float) $row->activeKcal, 0.001);

        // Active + basal, the Watch's own summary figure, converted the same way.
        self::assertEqualsWithDelta(1856.5 / 4.184, $row->extras['total_kcal'], 0.001);
    }

    /**
     * An unconvertible energy unit FAILS rather than being stored, deliberately:
     * a `Cal` or a `J` landing in the kcal column would be silent, permanent and
     * invisible until a report told the user a 6 km run cost 1 400 kcal. The
     * bytes are banked; the fix is to teach MetricCatalog and replay.
     */
    public function test_an_unconvertible_energy_unit_throws(): void
    {
        $this->expectException(UnknownUnitConversion::class);

        $this->read(['activeEnergyBurned' => ['qty' => 1424, 'units' => 'furlongs']]);
    }

    /**
     * A quantity delivered WITHOUT a unit, on both paths that have to guess
     * one — and they guess differently, which is the whole point.
     *
     * Energy assumes kJ, because that is what the export sends and reading a
     * bare 1424 as kcal would store four times the truth in a column no
     * later reader can question. Everything else assumes the unit the column
     * is already documented in, so a bare number is taken as delivered.
     *
     * Neither default belongs where the field is UNPACKED — qtyAndUnits()
     * returns a null unit precisely so each caller states its own — and no
     * fixture omits `units`, so without this the seam is unexercised and a
     * default quietly moved into the shared helper would multiply active
     * energy by 4.184 with the suite still green.
     */
    public function test_a_quantity_with_no_unit_takes_its_callers_default(): void
    {
        $energy = $this->read(['activeEnergyBurned' => ['qty' => 1424]]);

        self::assertSame('340.344', $energy->activeKcal);

        $distance = $this->read(['distance' => ['qty' => 8.4]]);

        self::assertSame('8.4000', $distance->distanceKm);
    }

    public function test_the_scalar_fields_land_on_their_columns(): void
    {
        $row = $this->read();

        self::assertSame('2D91369C-E0C9-43ED-9664-C7D7663F9258', $row->appleUuid);
        self::assertSame('Outdoor Run', $row->type);
        self::assertSame('2999.000', $row->durationS);
        self::assertSame('6.0301', $row->distanceKm);
        self::assertSame(157, $row->avgHr);
        self::assertSame(174, $row->maxHr);
        self::assertSame('41.00', $row->elevationUpM);
        self::assertSame('7.1409', $row->intensity);
        self::assertFalse($row->isIndoor);
        self::assertFalse($row->isImplausible);

        // The device's own offset, kept because the timestamps are absolute.
        self::assertSame(120, $row->deviceUtcOffsetMinutes);

        // Parsed with the offset and stored as the instant it was.
        self::assertSame('2026-08-02 06:53:47', $row->startedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $row->startedAt->getTimezone()->getName());
    }

    /**
     * `stepCount` is the ONE series this parser reduces, to the workout's own
     * total — checked against the export's arithmetic on real data: the
     * 2026-04-19 run's 15 054 steps over 189.6 minutes is 79.39/min, matching
     * that workout's `stepCadence` to five decimals.
     */
    public function test_the_step_series_is_summed_into_one_total(): void
    {
        self::assertSame(180, $this->read()->stepCount);
    }

    /**
     * THE POINT OF THE WHOLE SCHEMA DECISION. Route fixes and heart-rate windows
     * are megabytes and stay in the permanent raw payload — excluded by SHAPE and
     * not by name: every series HAE sends is a JSON list, so dropping lists keeps
     * out the series HAE adds next year too.
     */
    public function test_sample_series_never_reach_the_row(): void
    {
        $row = $this->read();

        foreach (['route', 'heart_rate_data', 'heartRateData', 'active_energy', 'basal_energy',
            'step_count', 'stepCount', 'heart_rate_recovery', 'walking_and_running_distance'] as $key) {
            self::assertArrayNotHasKey($key, $row->extras, "[{$key}] must not be in extras");
        }

        self::assertStringNotContainsString('latitude', json_encode($row->extras, JSON_THROW_ON_ERROR));
    }

    /** Passed through, not allow-listed: a scalar HAE adds next year needs no migration. */
    public function test_the_scalar_long_tail_is_kept_verbatim(): void
    {
        $row = $this->read(['someNewFieldApplePutIn' => ['qty' => 3, 'units' => 'widgets']]);

        self::assertSame(['qty' => 6.98, 'units' => 'km'], $row->extras['avg_speed']);
        self::assertSame(['qty' => 17.4, 'units' => 'degC'], $row->extras['temperature']);
        self::assertSame(['qty' => 73, 'units' => '%'], $row->extras['humidity']);
        self::assertSame(['qty' => 158.4, 'units' => 'count/min'], $row->extras['step_cadence']);
        self::assertSame('Outdoor', $row->extras['location']);
        self::assertSame(98, $row->extras['min_hr']);
        self::assertSame(['qty' => 3, 'units' => 'widgets'], $row->extras['some_new_field_apple_put_in']);
    }

    /**
     * The four-day hike is kept out of every total by a MARKER, not a filter: the
     * row is built faithfully with its real four-day duration, because dropping it
     * would make the app disagree with the device about a day that happened.
     */
    public function test_a_session_longer_than_the_ceiling_is_flagged_and_still_built(): void
    {
        $row = $this->read([
            'name'     => 'Hiking',
            'start'    => '2026-07-07 20:56:46 +0200',
            'end'      => '2026-07-11 20:56:46 +0200',
            'duration' => 345600.0,
        ]);

        self::assertTrue($row->isImplausible);
        self::assertSame('345600.000', $row->durationS);
        self::assertSame('Hiking', $row->type);
    }

    public function test_the_ceiling_is_configurable(): void
    {
        config(['health.workouts.max_plausible_hours' => 0.5]);

        // 2 999 s is 50 minutes: plausible at the 12 h default, not at half an hour.
        self::assertTrue($this->read()->isImplausible);
    }

    /** A zero-length "session" is not a session either. */
    public function test_a_non_positive_duration_is_implausible(): void
    {
        self::assertTrue($this->read(['duration' => 0])->isImplausible);
    }

    /** `duration` wins over the span: it knows about a paused timer, the timestamps do not. */
    public function test_a_missing_duration_falls_back_to_the_span(): void
    {
        self::assertSame('2999.000', $this->read(['duration' => null])->durationS);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unusableRecords')]
    public function test_a_record_without_an_identity_or_times_is_skipped(array $overrides): void
    {
        $this->expectException(MalformedDatapoint::class);

        $this->read($overrides);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableRecords(): array
    {
        return [
            // The uuid IS the idempotency key: without it a re-export duplicates everything.
            'no id'             => [['id' => null]],
            'blank id'          => [['id' => '  ']],
            'no start'          => [['start' => null]],
            'no end'            => [['end' => null]],
            'unparseable start' => [['start' => 'last Tuesday']],
        ];
    }

    /** A session with no label still happened; tidiness does not outrank the record. */
    public function test_an_unnamed_session_is_stored_rather_than_skipped(): void
    {
        self::assertSame('Workout', $this->read(['name' => null])->type);
    }

    /**
     * Climbing and martial arts report no distance, elevation or `isIndoor`, and
     * null must stay null: "the export did not say" is not "zero" nor "outdoors".
     */
    public function test_absent_optional_fields_stay_null(): void
    {
        $row = $this->read([
            'distance'    => null,
            'elevationUp' => null,
            'isIndoor'    => null,
            'stepCount'   => null,
        ]);

        self::assertNull($row->distanceKm);
        self::assertNull($row->elevationUpM);
        self::assertNull($row->isIndoor);
        self::assertNull($row->stepCount);
    }
}
