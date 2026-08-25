<?php

declare(strict_types=1);

namespace Tests\Feature\Fitage;

use Tests\TestCase;
use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\HealthMetric;
use Tests\Support\XlsxFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The importer end to end: files in a directory, rows in `health_metrics`,
 * summaries rebuilt. The weight of the feature sits on
 * `test_a_measurement_in_two_overlapping_windows_lands_once` (the user exports
 * in overlapping chunks, forever) and
 * `test_a_weigh_in_that_arrived_by_both_paths_gives_the_day_one_sane_weight`
 * (the same physical weigh-in already in the database, hour-bucketed, from
 * HealthKit).
 */
final class FitageImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/fitage-'.uniqid());

        @mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /** @param list<list<string|null>> $rows */
    private function export(string $name, array $rows): string
    {
        return XlsxFixture::make()
            ->rows([XlsxFixture::headerV1(), ...$rows])
            ->write($this->directory.'/'.$name);
    }

    /** @param array<string, mixed> $options */
    private function import(array $options = []): int
    {
        return $this->runArtisan('fitage:import', ['--dir' => $this->directory, ...$options])->run();
    }

    /** @return array<int, string> */
    private function weights(): array
    {
        return HealthMetric::query()
            ->where('metric', 'weight_body_mass')
            ->orderBy('started_at')
            ->get()
            ->map(fn (HealthMetric $m): string => $m->started_at->setTimezone('Europe/Amsterdam')
                ->toDateTimeString().' = '.(float) $m->value)
            ->all();
    }

    // ------------------------------------------------------- the basics ----

    public function test_a_measurement_becomes_one_instant_row_per_metric(): void
    {
        $this->export('window.xlsx', [
            XlsxFixture::rowV1(
                at: '13/05/2025 15:38:23', weight: '56.00', bodyFat: '24.0', bmi: '21.9',
                muscleMass: '39.98', bmr: '1289', fatFree: '42.62',
                visceralFat: '4', bodyWater: '52.2', boneMass: '2.58',
            ),
        ]);

        self::assertSame(0, $this->import());

        self::assertSame(9, HealthMetric::query()->count());

        $stored = HealthMetric::query()->get()->keyBy('metric');

        $metricRow = function (string $metric) use ($stored): HealthMetric {
            $found = $stored->get($metric);
            self::assertNotNull($found, "no imported row for metric [{$metric}]");

            return $found;
        };

        // The four the catalog already had, mapped to their existing names.
        self::assertSame('56.000000', $metricRow('weight_body_mass')->value);
        self::assertSame('24.000000', $metricRow('body_fat_percentage')->value);
        self::assertSame('21.900000', $metricRow('body_mass_index')->value);
        self::assertSame('42.620000', $metricRow('lean_body_mass')->value);

        // The five HealthKit never carried.
        self::assertSame('39.980000', $metricRow('muscle_mass')->value);
        self::assertSame('4.000000', $metricRow('visceral_fat')->value);
        self::assertSame('52.200000', $metricRow('body_water_percentage')->value);
        self::assertSame('2.580000', $metricRow('bone_mass')->value);
        self::assertSame('1289.000000', $metricRow('basal_metabolic_rate')->value);

        self::assertSame(
            ['%', 'count', 'kcal', 'kg', 'kg', 'kg', 'kg', '%', 'count'],
            [
                $metricRow('body_fat_percentage')->unit, $metricRow('body_mass_index')->unit,
                $metricRow('basal_metabolic_rate')->unit, $metricRow('weight_body_mass')->unit,
                $metricRow('lean_body_mass')->unit, $metricRow('muscle_mass')->unit,
                $metricRow('bone_mass')->unit, $metricRow('body_water_percentage')->unit,
                $metricRow('visceral_fat')->unit,
            ],
        );

        foreach ($stored as $metric => $row) {
            self::assertTrue($row->isInstant(), $metric);
            self::assertTrue($row->started_at->equalTo($row->ended_at), $metric);
            // 15:38 CEST is 13:38 UTC; the generated local_date says the 13th.
            self::assertSame('2025-05-13 13:38:23', $row->started_at->utc()->toDateTimeString(), $metric);
            self::assertSame('2025-05-13', $row->local_date->toDateString(), $metric);
            self::assertSame(120, $row->device_utc_offset_minutes, $metric);
        }
    }

    /** Classify as a scale, or it loses every body-composition priority list to the phone. */
    public function test_the_import_source_is_a_scale_and_is_its_own_row(): void
    {
        Source::factory()->scale('FITAGE')->create();

        $this->export('window.xlsx', [XlsxFixture::rowV1(at: '13/05/2025 15:38:23', weight: '56.00')]);

        $this->import();

        $source = Source::query()->where('raw_name', 'FITAGE export')->sole();

        self::assertSame('scale', $source->device_kind->value);
        self::assertSame('fitage-export', $source->slug);

        // Distinct from the HealthKit-delivered source: one row per path in.
        self::assertNotSame(
            $source->id,
            Source::query()->where('raw_name', 'FITAGE')->sole()->id,
        );
    }

    // ---------------------------------------------------- idempotence ------

    /**
     * THE ONE THAT MATTERS FOR OVERLAPPING WINDOWS. The user exports per period,
     * so one weigh-in appears in two files: one instant, one identity, one row,
     * and the second sighting must not even write a tuple — which is what
     * `already stored` reports.
     */
    public function test_a_measurement_in_two_overlapping_windows_lands_once(): void
    {
        $shared = XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15');

        $this->export('may-july.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            $shared,
        ]);

        $this->export('july-august.xlsx', [
            XlsxFixture::rowV1(at: '23/08/2025 09:20:43', weight: '56.85'),
            $shared,
        ]);

        $this->import();

        self::assertSame([
            '2025-07-07 07:24:02 = 54.15',
            '2025-07-13 11:30:35 = 55.2',
            '2025-08-23 09:20:43 = 56.85',
        ], $this->weights());

        $before = HealthMetric::query()->count();

        // Re-running the whole directory changes nothing at all.
        $this->import();

        self::assertSame($before, HealthMetric::query()->count());
        self::assertSame([
            '2025-07-07 07:24:02 = 54.15',
            '2025-07-13 11:30:35 = 55.2',
            '2025-08-23 09:20:43 = 56.85',
        ], $this->weights());
    }

    /** The directory is the contract: a new file adds its measurements, nothing else. */
    public function test_adding_a_file_and_re_running_only_adds_the_new_measurements(): void
    {
        $this->export('first.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20')]);

        $this->import();

        $ingestedAt = HealthMetric::query()->where('metric', 'weight_body_mass')->sole()->ingested_at;

        $this->export('second.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: '23/08/2025 09:20:43', weight: '56.85'),
        ]);

        // 27 values: the new measurement's 9, and 18 sightings of the old (one per file).
        $this->runArtisan('fitage:import', ['--dir' => $this->directory])
            ->expectsOutputToContain('27 value(s): 9 new, 0 changed, 18 already stored')
            ->assertSuccessful();

        self::assertSame([
            '2025-07-13 11:30:35 = 55.2',
            '2025-08-23 09:20:43 = 56.85',
        ], $this->weights());

        // The upsert's WHERE skipped it, so Postgres wrote no new tuple version.
        self::assertTrue(
            $ingestedAt->equalTo(
                HealthMetric::query()->where('metric', 'weight_body_mass')
                    ->where('local_date', '2025-07-13')->sole()->ingested_at
            ),
        );
    }

    /** A re-export the scale has since revised replaces the value. */
    public function test_a_revised_value_for_the_same_instant_replaces_it(): void
    {
        $this->export('v1.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20')]);
        $this->import();

        @unlink($this->directory.'/v1.xlsx');

        $this->export('v2.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.50')]);
        $this->import();

        self::assertSame(['2025-07-13 11:30:35 = 55.5'], $this->weights());
    }

    // ------------------------------------------ the double-count question ---

    /**
     * THE ONE THAT MATTERS FOR THE EXISTING HISTORY. Health Auto Export delivers
     * HOURLY BUCKETS, so weigh-ins the scale already pushed through HealthKit sit
     * at the top of their hour — and where the hour held two readings, HAE handed
     * over their MEAN. Production really contains 2026-04-29 14:00:00 = 55.60,
     * the average of a 14:46:42 and a 14:47:04 the user actually stood for.
     * Importing the export therefore puts three weight rows on that day: it must
     * still report ONE weight, not an average of the three, and not three
     * weigh-ins.
     */
    public function test_a_weigh_in_that_arrived_by_both_paths_gives_the_day_one_sane_weight(): void
    {
        $healthKit = Source::factory()->scale('FITAGE')->create();

        // What HAE delivered: one hour-anchored sample, the mean of the two.
        HealthMetric::factory()->for($healthKit)->metric('weight_body_mass')->create([
            'value'      => 55.60,
            'period'     => 'hour',
            'started_at' => CarbonImmutable::parse('2026-04-29 14:00:00', 'Europe/Amsterdam')->utc(),
            'ended_at'   => CarbonImmutable::parse('2026-04-29 14:00:00', 'Europe/Amsterdam')->utc(),
        ]);

        $this->export('window.xlsx', [
            XlsxFixture::rowV1(at: '29/04/2026 14:47:04', weight: '55.45'),
            XlsxFixture::rowV1(at: '29/04/2026 14:46:42', weight: '55.75'),
        ]);

        $this->import();

        // All three kept: genuine observations, two at the instant they happened.
        self::assertSame([
            '2026-04-29 14:00:00 = 55.6',
            '2026-04-29 14:46:42 = 55.75',
            '2026-04-29 14:47:04 = 55.45',
        ], $this->weights());

        $summary = DailySummary::query()->where('local_date', '2026-04-29')->sole();

        // ONE value: not the sum (166.8), not the mean (55.6), not HAE's
        // synthetic average — the last real reading, per BucketSelector.
        self::assertSame(55.45, (float) $summary->weight_kg);

        // And ONE day, the unit TdeeEstimator's weigh-in gate counts.
        self::assertSame(
            1,
            DailySummary::query()->where('local_date', '2026-04-29')->whereNotNull('weight_kg')->count(),
        );
    }

    /** The other half: on a day HealthKit missed, the export is the only weight. */
    public function test_a_day_healthkit_never_carried_gains_its_weigh_in(): void
    {
        $this->export('window.xlsx', [XlsxFixture::rowV1(at: '09/08/2026 09:32:20', weight: '56.45')]);

        $this->import();

        self::assertSame(
            56.45,
            (float) DailySummary::query()->where('local_date', '2026-08-09')->sole()->weight_kg,
        );
    }

    // ------------------------------------------------------- the rollup ----

    public function test_every_touched_day_is_rebuilt(): void
    {
        $this->export('window.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15'),
            // Two weigh-ins on one day are one day.
            XlsxFixture::rowV1(at: '04/07/2025 06:18:28', weight: '54.95'),
            XlsxFixture::rowV1(at: '04/07/2025 20:11:02', weight: '55.05'),
        ]);

        $this->runArtisan('fitage:import', ['--dir' => $this->directory])
            ->expectsOutputToContain('Rebuilt 3 daily summary row(s)')
            ->assertSuccessful();

        self::assertSame(3, DailySummary::query()->whereNotNull('weight_kg')->count());

        // Latest of the day wins, per BucketSelector.
        self::assertSame(
            55.05,
            (float) DailySummary::query()->where('local_date', '2025-07-04')->sole()->weight_kg,
        );
    }

    /**
     * The scale's BMR estimate is not Apple's basal energy: 1289 kcal/day landing
     * in `resting_kcal` is a plausible number that corrupts every TDEE after it.
     */
    public function test_the_scales_bmr_never_reaches_the_energy_rollup(): void
    {
        $this->export('window.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20', bmr: '1289'),
        ]);

        $this->import();

        self::assertSame(
            '1289.000000',
            HealthMetric::query()->where('metric', 'basal_metabolic_rate')->sole()->value,
        );

        self::assertSame(
            0,
            HealthMetric::query()->where('metric', 'basal_energy_burned')->count(),
        );

        $summary = DailySummary::query()->where('local_date', '2025-07-13')->sole();

        self::assertNull($summary->resting_kcal);
        self::assertNull($summary->active_kcal);
    }

    // ---------------------------------------------------------- dry run ----

    public function test_a_dry_run_writes_nothing_and_reports_what_it_would_do(): void
    {
        $this->export('window.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15'),
        ]);

        // NB: two expectsOutputToContain() whose substrings can appear on the
        // SAME written line never both clear — Mockery routes a call to the
        // first matching expectation only. Keep them on distinct lines.
        $this->runArtisan('fitage:import', ['--dir' => $this->directory, '--dry-run' => true])
            ->expectsOutputToContain('18 value(s): 18 new, 0 changed, 0 already stored')
            ->expectsOutputToContain('Dry run — nothing was written')
            ->assertSuccessful();

        self::assertSame(0, HealthMetric::query()->count());
        self::assertSame(0, DailySummary::query()->count());
        self::assertSame(0, Source::query()->where('raw_name', 'FITAGE export')->count());
    }

    /**
     * A dry run writes nothing, so the database cannot tell it file 1 already
     * covered what file 2 claims as new; counting it twice would promise nine
     * rows the real run never creates. Overlapping windows are what the user
     * produces.
     */
    public function test_a_dry_run_counts_a_measurement_shared_by_two_files_once(): void
    {
        $shared = XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15');

        $this->export('may-july.xlsx', [$shared]);
        $this->export('july-august.xlsx', [$shared]);

        // 18 values seen across the two files, 9 distinct measurements' worth.
        $this->runArtisan('fitage:import', ['--dir' => $this->directory, '--dry-run' => true])
            ->expectsOutputToContain('18 value(s): 9 new, 0 changed, 9 already stored')
            ->assertSuccessful();

        // And the real run agrees.
        $this->runArtisan('fitage:import', ['--dir' => $this->directory])
            ->expectsOutputToContain('18 value(s): 9 new, 0 changed, 9 already stored')
            ->assertSuccessful();

        self::assertSame(9, HealthMetric::query()->count());
    }

    /** The dry run's numbers must be the ones the real run then produces. */
    public function test_the_dry_run_predicts_the_real_run(): void
    {
        $this->export('first.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20')]);
        $this->import();

        $this->export('second.xlsx', [
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: '23/08/2025 09:20:43', weight: '56.85'),
        ]);

        // The shared row's 9 values, seen once per file (18), plus 9 new.
        $this->runArtisan('fitage:import', ['--dir' => $this->directory, '--dry-run' => true])
            ->expectsOutputToContain('9 new, 0 changed, 18 already stored')
            ->assertSuccessful();

        $this->runArtisan('fitage:import', ['--dir' => $this->directory])
            ->expectsOutputToContain('9 new, 0 changed, 18 already stored')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------- the UX -----

    public function test_it_reports_which_weigh_ins_healthkit_already_had(): void
    {
        $healthKit = Source::factory()->scale('FITAGE')->create();

        HealthMetric::factory()->for($healthKit)->metric('weight_body_mass')->create([
            'value'      => 55.60,
            'period'     => 'hour',
            'started_at' => CarbonImmutable::parse('2026-04-29 14:00:00', 'Europe/Amsterdam')->utc(),
            'ended_at'   => CarbonImmutable::parse('2026-04-29 14:00:00', 'Europe/Amsterdam')->utc(),
        ]);

        $this->export('window.xlsx', [
            XlsxFixture::rowV1(at: '29/04/2026 14:47:04', weight: '55.45'),
            XlsxFixture::rowV1(at: '03/05/2026 12:59:03', weight: '54.60'),
        ]);

        $this->runArtisan('fitage:import', ['--dir' => $this->directory, '--dry-run' => true])
            ->expectsOutputToContain('1 weigh-in(s) share an hour with a HealthKit scale sample')
            ->assertSuccessful();
    }

    public function test_an_empty_directory_is_not_an_error(): void
    {
        $this->runArtisan('fitage:import', ['--dir' => $this->directory])
            ->expectsOutputToContain('No .xlsx files found')
            ->assertSuccessful();
    }

    public function test_a_missing_directory_fails_loudly(): void
    {
        $this->runArtisan('fitage:import', ['--dir' => $this->directory.'/nope'])
            ->assertFailed();
    }

    public function test_a_single_file_can_be_imported_on_its_own(): void
    {
        $path = $this->export('window.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20')]);

        // A second file in the same directory that --file must NOT pick up.
        $this->export('other.xlsx', [XlsxFixture::rowV1(at: '23/08/2025 09:20:43', weight: '56.85')]);

        $this->runArtisan('fitage:import', ['--file' => [$path]])->assertSuccessful();

        self::assertSame(['2025-07-13 11:30:35 = 55.2'], $this->weights());
    }

    /** Both schemas in one directory, which is exactly the real situation. */
    public function test_a_directory_can_mix_both_export_schemas(): void
    {
        $this->export('old.xlsx', [XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20')]);

        XlsxFixture::make()->rows([
            XlsxFixture::headerV2(),
            XlsxFixture::rowV2(at: '09/08/2026 09:32:20', weight: '56.45'),
        ])->write($this->directory.'/new.xlsx');

        $this->import();

        self::assertSame([
            '2025-07-13 11:30:35 = 55.2',
            '2026-08-09 09:32:20 = 56.45',
        ], $this->weights());
    }
}
