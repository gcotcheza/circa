<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Models\StressDaily;
use Carbon\CarbonImmutable;

/**
 * The one place that writes `stress_daily`.
 *
 * Only days that produced a SCORE. A day with four readings, or one early
 * enough that there's no baseline behind it, writes no row — the absence
 * is the honest statement; a table with placeholder rows would eventually
 * be averaged by somebody who assumed every row was a measurement.
 *
 * The mirror image matters too: a day that USED to score and no longer
 * does (readings withdrawn by a re-ingest, a threshold raised) has its row
 * DELETED rather than left behind. A rebuild should leave the table as
 * though it had always been computed this way; a stale row nothing
 * recomputes is the one failure mode a derived table has.
 *
 * `computed_at` IS ONLY TOUCHED WHEN THE ANSWER CHANGES. The score is
 * re-derived on every page load of /stress and every nightly run; stamping
 * the clock each time would turn "as of last night" into "as of four
 * seconds ago" on a row nothing had changed. An unchanged recompute is
 * therefore a no-op — what makes the scheduled command safe to fire twice
 * after a restart, the same contract TdeeRecorder makes.
 */
final class StressRecorder
{
    /**
     * Persist a run of days. Returns how many rows were written or removed.
     *
     * @param  iterable<DailyStress>  $days
     * @return array{written: int, unchanged: int, removed: int}
     */
    public function record(iterable $days): array
    {
        $written = 0;
        $unchanged = 0;
        $removed = 0;

        foreach ($days as $day) {
            if (! $day->hasScore()) {
                $removed += StressDaily::query()->whereKey($day->date)->delete();

                continue;
            }

            $this->store($day) ? $written++ : $unchanged++;
        }

        return ['written' => $written, 'unchanged' => $unchanged, 'removed' => $removed];
    }

    /**
     * Upsert one scored day. True when something actually changed.
     */
    public function store(DailyStress $day): bool
    {
        if (! $day->hasScore()) {
            return false;
        }

        $values = [
            'score' => $day->score,
            // Rounded to the columns' own precision, so the "unchanged?"
            // comparison below is against what would be stored, not a float
            // about to lose digits.
            'z'                   => round((float) $day->z, 3),
            'hrv_ms'              => round((float) $day->hrvMs, 3),
            'baseline_hrv_ms'     => round((float) $day->baselineHrvMs, 3),
            'baseline_spread_log' => round((float) $day->baselineSpreadLog, 4),
            'samples'             => $day->samples,
            'covered_hours'       => round($day->coveredHours, 1),
            'baseline_days'       => $day->baselineDays,
        ];

        $labels = [
            'band'           => $day->band,
            'confidence'     => $day->confidence(),
            'method_version' => (string) config('health.stress.method_version', 'v1'),
        ];

        $existing = StressDaily::query()->find($day->date);

        if ($existing !== null && $this->unchanged($existing, $values, $labels)) {
            return false;
        }

        StressDaily::query()->updateOrCreate(
            ['local_date' => $day->date],
            $values + $labels + ['computed_at' => CarbonImmutable::now()],
        );

        return true;
    }

    /**
     * @param  array<string, float|int>  $values
     * @param  array<string, mixed>  $labels
     */
    private function unchanged(StressDaily $existing, array $values, array $labels): bool
    {
        foreach ($values as $column => $value) {
            // Decimal columns come back as numeric strings ("-0.412"), so the
            // comparison has to be numeric or every row would look changed.
            if (abs((float) $existing->getAttribute($column) - (float) $value) > 1e-9) {
                return false;
            }
        }

        /*
         * A band, confidence tier or method version that moved while every
         * NUMBER stayed put means a threshold was edited, not data arriving
         * — still a change that must be written, or editing `scale.bands`
         * would leave the table labelled by the old rule with nothing to
         * show it happened.
         */
        foreach ($labels as $column => $value) {
            $current = $existing->getAttribute($column);

            if (($value instanceof \BackedEnum ? $value->value : $value)
                !== ($current instanceof \BackedEnum ? $current->value : $current)) {
                return false;
            }
        }

        return true;
    }
}
