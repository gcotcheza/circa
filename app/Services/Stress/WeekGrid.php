<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Enums\StressBand;

/**
 * One SPECIFIC week, hour by hour: 168 cells, each a real hour of a real day
 * rather than an average of several.
 *
 * Sits beside StressHeatmap, which answers "what are my Tuesdays at 09:00
 * usually like" (a median over ~13 occurrences across ninety days — a
 * habit); this answers "what happened last Tuesday at 09:00" (one reading,
 * one date — an event). Both matter, so the card offers both; this one is
 * the default since it's what changes as you browse a week.
 *
 * A cell is coloured from HourlyBaseline: how far it sits from this
 * person's usual readings at THIS HOUR, in their own standard deviations —
 * the same object behind the notable-moments list, so a cell and a moment
 * can't disagree. That's a DIFFERENT standard deviation from a daily
 * band's (0.33 log units hour-to-hour in production vs. 0.15 day-to-day),
 * stated on the card rather than smoothed over — scoring single readings
 * against the day-to-day spread would push almost every cell to one end
 * of the ramp, a meaningless and dishonest riot of colour.
 *
 * The commercial app being replaced interpolates across the ~1-in-6 hours
 * it has no reading for, filling all 168 cells; that looks better and
 * isn't true. Here a cell with no reading is a hole — three states:
 * MEASURED (reading, z, band); UNMEASURED (nothing arrived, drawn empty,
 * counted); and NOT YET (an hour that hasn't happened and has no reading —
 * drawn empty but NOT counted against coverage — "17 of 168 hours" on a
 * Monday morning would be a fact about the calendar. A reading always
 * outranks the clock here: an hour something arrived for is never "not
 * yet"). A fourth, rarer state is a reading at an hour the
 * circadian profile has no shape for: a value but no z, since "unusual for
 * you at that hour" needs a usual to compare to — drawn neutral, reporting
 * on tap.
 */
final readonly class WeekGrid
{
    /**
     * @param  list<array<string, mixed>>  $cells  168, weekday-major
     */
    private function __construct(
        public array $cells,
        public string $start,
        public string $end,
        /** Cells with at least one reading. */
        public int $filledCells,
        /** Cells with a reading but no comparable hour — value, no band. */
        public int $unrankedCells,
        /** Cells whose hour has already happened. The honest denominator. */
        public int $elapsedCells,
        public int $observations,
        /** The hour-to-hour spread the colours are measured in, in log units. */
        public float $spreadLog,
    ) {}

    /**
     * @param  list<HrvSample>  $samples  every accepted reading of the week
     * @param  list<string>  $dates  the week's seven local dates, Monday first
     * @param  string  $nowDate  local date of "now"
     * @param  int  $nowHour  local hour of "now", 0-23
     */
    public static function build(
        array $samples,
        array $dates,
        HourlyBaseline $baseline,
        StressScale $scale,
        string $nowDate,
        int $nowHour,
    ): self {
        /** @var array<string, list<HrvSample>> $byCell keyed "date:hour" */
        $byCell = [];

        foreach ($samples as $sample) {
            $byCell[$sample->localDate.':'.$sample->hour][] = $sample;
        }

        $cells = [];
        $filled = 0;
        $unranked = 0;
        $elapsed = 0;
        $observations = 0;

        // Weekday-major, Monday first, matching StressHeatmap's order so
        // either component can draw the other without a new ordering rule.
        foreach ($dates as $index => $date) {
            for ($hour = 0; $hour < 24; $hour++) {
                $readings = $byCell[$date.':'.$hour] ?? [];
                $observations += count($readings);

                /*
                 * A reading always wins over the clock: if something arrived
                 * for an hour, that hour happened, whatever this server
                 * thinks the time is — a device clock running ahead, or a
                 * bucket anchored at sync's end, can produce a reading "in
                 * the future," and deciding it the other way would silently
                 * hide real data.
                 */
                $future = $readings === []
                    && ($date > $nowDate || ($date === $nowDate && $hour > $nowHour));

                if (! $future) {
                    $elapsed++;
                }

                $cell = [
                    'weekday'       => $index + 1,
                    'hour'          => $hour,
                    'date'          => $date,
                    'samples'       => count($readings),
                    'future'        => $future,
                    'hrvMs'         => null,
                    'z'             => null,
                    'score'         => null,
                    'band'          => null,
                    'typicalMs'     => null,
                    'typicalLowMs'  => null,
                    'typicalHighMs' => null,
                ];

                if ($readings !== []) {
                    $filled++;

                    /*
                     * The median of the hour's readings, in log space —
                     * almost always exactly one, since BucketSelector has
                     * already resolved overlaps, but two half-hour buckets
                     * in one hour is a shape the schema allows, and
                     * averaging them in milliseconds would break the log
                     * units this feature depends on.
                     */
                    $level = (float) Robust::median(array_map(
                        static fn (HrvSample $s): float => $s->lnValue,
                        $readings
                    ));

                    $cell['hrvMs'] = round(exp($level), 1);

                    $z = $baseline->zForLevel($level, $hour);

                    if ($z === null) {
                        $unranked++;
                    } else {
                        [$low, $high] = $baseline->typicalRangeMs($hour);

                        $score = $scale->score($z);

                        $cell['z'] = round($z, 2);
                        $cell['score'] = $score;
                        $cell['band'] = StressBand::fromScore($score)->value;
                        $cell['typicalMs'] = round($baseline->typicalMs($hour), 1);
                        $cell['typicalLowMs'] = round($low);
                        $cell['typicalHighMs'] = round($high);
                    }
                }

                $cells[] = $cell;
            }
        }

        return new self(
            cells: $cells,
            start: $dates[0] ?? '',
            end: $dates[count($dates) - 1] ?? '',
            filledCells: $filled,
            unrankedCells: $unranked,
            elapsedCells: $elapsed,
            observations: $observations,
            spreadLog: $baseline->spread,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start'         => $this->start,
            'end'           => $this->end,
            'cells'         => $this->cells,
            'filledCells'   => $this->filledCells,
            'unrankedCells' => $this->unrankedCells,
            'elapsedCells'  => $this->elapsedCells,
            'totalCells'    => count($this->cells),
            'observations'  => $this->observations,
            /*
             * One standard deviation of this yardstick, as a percentage of
             * HRV — 0.33 log units is "about 40% up or 28% down," shipped so
             * the card can state a colour step's worth without restating the
             * arithmetic. Rounded to whole percent: a scale note, not a
             * measurement.
             */
            'spreadPercent' => (int) round((exp($this->spreadLog) - 1) * 100),
        ];
    }
}
