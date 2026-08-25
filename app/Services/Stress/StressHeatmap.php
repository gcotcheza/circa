<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Enums\StressBand;

/**
 * The hour x weekday grid: 168 cells, one per hour of the week.
 *
 * The commercial version of this chart plots raw HRV (or raw "stress") by
 * hour, producing the same picture for everybody — green all night, amber
 * all evening — a chart of the circadian rhythm with a stress label on it.
 * These cells are DESEASONALISED first (each reading's hour-typical offset
 * removed, see CircadianProfile), so a cell instead answers "is my HRV
 * usually above or below what this hour is normally like FOR ME?" — making
 * Monday 09:00 vs. Saturday 09:00 a real comparison, and a flat grid a
 * genuine finding rather than a failed chart. On this user's data it isn't
 * flat: over the last year, mean daily score runs 71 on Wednesdays and 53
 * on Sundays.
 *
 * A cell's z uses the same denominator as a daily score — the user's own
 * day-to-day deseasonalised spread over the window shown — so amber means
 * the same distance-from-baseline on the grid as on the weekly line, and
 * the two charts read against each other. Cells are naturally more muted
 * than days, correctly: a cell is a MEDIAN over ~13 occurrences of that
 * hour, so one bad Tuesday can't colour Tuesday 14:00 red (production:
 * daily z has a standard deviation of 1.13, cell z of 0.78, same scale).
 *
 * A cell below `min_cell_samples` carries no score and draws as a hole —
 * not interpolated from neighbours, not shaded from the day's average: an
 * absence of evidence coloured in is a lie that looks like a measurement.
 */
final readonly class StressHeatmap
{
    /**
     * @param  list<array<string, mixed>>  $cells  168 of them, weekday-major
     */
    private function __construct(
        public array $cells,
        public string $from,
        public string $to,
        public int $days,
        public int $filledCells,
        public int $observations,
    ) {}

    /**
     * @param  array<string, list<float>>  $residuals  keyed "weekday:hour"
     */
    public static function build(
        array $residuals,
        float $centre,
        float $spread,
        StressScale $scale,
        string $from,
        string $to,
        int $days,
    ): self {
        $minSamples = (int) config('health.stress.min_cell_samples', 3);

        $cells = [];
        $filled = 0;
        $observations = 0;

        /*
         * Weekday-major, Monday first, a fixed 168 cells regardless of
         * whether the Watch was ever on. The order is a CONTRACT, not a
         * drawing instruction: the client reads `weekday` and `hour` off
         * each cell rather than relying on position (see StressHeatmap.vue
         * for why), so shipping a complete, sorted set means it never
         * invents an ordering, fills a gap, or has to know ISO weekdays
         * start Monday while JavaScript's getDay() starts Sunday.
         */
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $values = $residuals[$weekday.':'.$hour] ?? [];
                $samples = count($values);
                $observations += $samples;

                $cell = [
                    'weekday' => $weekday,
                    'hour'    => $hour,
                    'samples' => $samples,
                    'score'   => null,
                    'band'    => null,
                    'z'       => null,
                    'hrvMs'   => null,
                ];

                if ($samples >= $minSamples) {
                    $level = (float) Robust::median($values);
                    $z = ($level - $centre) / $spread;
                    $score = $scale->score($z);

                    $cell['score'] = $score;
                    $cell['band'] = StressBand::fromScore($score)->value;
                    $cell['z'] = round($z, 2);
                    $cell['hrvMs'] = round(exp($level), 1);

                    $filled++;
                }

                $cells[] = $cell;
            }
        }

        return new self($cells, $from, $to, $days, $filled, $observations);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from'           => $this->from,
            'to'             => $this->to,
            'days'           => $this->days,
            'cells'          => $this->cells,
            'filledCells'    => $this->filledCells,
            'totalCells'     => count($this->cells),
            'observations'   => $this->observations,
            'minCellSamples' => (int) config('health.stress.min_cell_samples', 3),
        ];
    }
}
