<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Services\Rollup\MetricBucket;
use App\Services\Rollup\BucketSelector;

/**
 * Everything the body card draws: weight, body fat and muscle mass, each as
 * its raw weigh-ins plus the smoothed trend through them.
 *
 * Unlike the energy/steps charts (unit: a day, window from the page's range
 * buttons), this card's unit is a WEIGH-IN — 47 of them across fifteen
 * months, smaller than one 28-day energy series — so it ships all of it
 * once and lets the range chips slice it client-side, rather than the raw
 * `health_metrics` table (a row per reading, since the scale reports each
 * one twice, from the Fitage app and its export).
 *
 * WEIGHT comes from `daily_summaries.weight_kg` via WeightEma and nowhere
 * else — the established source of truth, written by the rollup's full
 * source-priority selection and the series step 7's TDEE estimator fits its
 * slope to, so a second query would eventually disagree while both still
 * looked plausible. BODY FAT and MUSCLE MASS have no summary column yet, so
 * they're read from `health_metrics` through the same
 * BucketSelector::pickInstant() rule the rollup uses for weight
 * (highest-priority source, then latest reading of the day — two scale
 * readings are a correction, not two facts), keeping the fat series lined
 * up weigh-in for weigh-in with weight. Both are smoothed by the one
 * WeightEma::smooth() implementation.
 *
 * Muscle mass has no HealthKit type (App\Services\Fitage\FitageColumns) and
 * is only as recent as the last .xlsx hand-fed to `fitage:import`, so the
 * card states in words (via config's `export` key) when a line ended
 * because the file is stale rather than the body settling. "Behind" is
 * measured against the freshest series ON this card, never against today —
 * someone who's stopped weighing in is late on nothing.
 */
final class BodyTrend
{
    public function __construct(
        private readonly WeightEma $ema = new WeightEma,
        private readonly BucketSelector $selector = new BucketSelector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(?string $endDate = null): array
    {
        $tz = (string) config('health.timezone');
        $alpha = (float) config('health.trends.ema_alpha', 0.25);

        /** @var array<string, mixed> $config */
        $config = (array) config('health.trends.body', []);

        $end = $endDate === null
            ? CarbonImmutable::now($tz)->startOfDay()
            : CarbonImmutable::parse($endDate, $tz)->startOfDay();

        /** @var list<array<string, mixed>> $definitions */
        $definitions = array_values((array) ($config['series'] ?? []));

        /** @var list<array{key: string, days: int|null, label: string, title: string}> $ranges */
        $ranges = array_values((array) ($config['ranges'] ?? []));

        $observations = $this->observations($definitions);

        $freshest = $this->freshest($observations);

        $series = [];

        foreach ($definitions as $definition) {
            $key = (string) $definition['key'];
            $values = $observations[$key] ?? [];

            // A chip opening an empty chart is worse than no chip; one
            // reading is still worth a dot, two are worth a line.
            if ($values === []) {
                continue;
            }

            $trend = $this->ema->smooth($values, $alpha);

            $points = [];

            foreach ($values as $date => $value) {
                $points[] = [
                    'date'  => $date,
                    'value' => round($value, 2),
                    'trend' => round($trend[$date], 2),
                ];
            }

            $series[] = [
                'key'     => $key,
                'label'   => (string) $definition['label'],
                'unit'    => (string) $definition['unit'],
                'digits'  => (int) $definition['digits'],
                'minSpan' => (float) $definition['min_span'],
                'points'  => $points,
                'export'  => $this->exportNote($definition, (string) array_key_last($values), $freshest),
            ];
        }

        if ($series === []) {
            return ['body' => null];
        }

        return [
            'body' => [
                'today'    => $end->toDateString(),
                'ranges'   => $ranges,
                'range'    => $this->openingRange($ranges, $series[0]['points'], $end, (int) ($config['default_min_points'] ?? 10)),
                'gapDays'  => (int) ($config['gap_days'] ?? 21),
                'emaAlpha' => $alpha,
                'series'   => $series,
            ],
        ];
    }

    /**
     * The newest day any series here reaches, and what a hand-fed series is
     * late against — deliberately not `now()`, since a fortnight off the
     * scale ages every series equally and calling muscle stale for it would
     * report the calendar as an import failure.
     *
     * @param  array<string, array<string, float>>  $observations
     */
    private function freshest(array $observations): ?string
    {
        $out = null;

        foreach ($observations as $values) {
            if ($values === []) {
                continue;
            }

            $last = (string) array_key_last($values);

            if ($out === null || $last > $out) {
                $out = $last;
            }
        }

        return $out;
    }

    /**
     * "Scale export covers through 9 Aug", and whether that's now a problem.
     *
     * Only a series carrying config's `export` key gets one; a series with
     * no readings never reaches here, since `props()` already dropped it for
     * want of a chip, so a user who never ran the import hears nothing about
     * a workflow they don't have. The date always answers "from when should
     * I export again"; amber adds the claim that the line now misses more
     * than a routine cycle. The lag ships as a number too, so the component
     * never recomputes it in the browser's timezone.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function exportNote(array $definition, string $through, ?string $freshest): ?array
    {
        /** @var array<string, mixed> $export */
        $export = (array) ($definition['export'] ?? []);

        if ($export === [] || $through === '' || $freshest === null) {
            return null;
        }

        $threshold = (int) ($export['stale_days'] ?? 14);

        // Never negative — the hand-fed series can be the freshest here.
        $lag = max(0, (int) CarbonImmutable::parse($through)->diffInDays(CarbonImmutable::parse($freshest)));

        return [
            'label'         => (string) ($export['label'] ?? 'Export'),
            'through'       => $through,
            'lagDays'       => $lag,
            'thresholdDays' => $threshold,
            'stale'         => $lag > $threshold,
        ];
    }

    /**
     * date => value per series key, oldest first.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, array<string, float>>
     */
    private function observations(array $definitions): array
    {
        $metrics = [];

        foreach ($definitions as $definition) {
            if ($definition['metric'] !== null) {
                $metrics[(string) $definition['metric']] = (string) $definition['key'];
            }
        }

        $out = [];

        foreach ($definitions as $definition) {
            if ($definition['metric'] === null) {
                $out[(string) $definition['key']] = $this->ema->observations();
            }
        }

        foreach ($this->pickedDaily(array_keys($metrics)) as $metric => $byDate) {
            $out[$metrics[$metric]] = $byDate;
        }

        return $out;
    }

    /**
     * One reading per (metric, day), chosen by the rollup's own rule.
     *
     * The whole history in one query — bounded by weigh-ins rather than
     * hours, a couple hundred rows after fifteen months, so nothing needs
     * pagination, and doing it in PHP lets BucketSelector make the choice
     * rather than a hand-rolled ORDER BY that would drift from it.
     *
     * Public because ReportFacts reads it too, for muscle mass and body fat
     * over the report's window: "which reading is the day's reading" must
     * be one answer, since the scale reports every weigh-in twice (Fitage
     * app and export are both `scale`), and a second implementation would
     * eventually pick the other one, leaving the chart and report
     * disagreeing about the same morning while both looked right. Returns
     * the picked readings only — labels, ranges and chips are `props()`'s
     * business.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<string, float>>
     */
    public function pickedDaily(array $metrics): array
    {
        if ($metrics === []) {
            return [];
        }

        $rows = DB::table('health_metrics as hm')
            ->join('sources as s', 's.id', '=', 'hm.source_id')
            ->select([
                'hm.id',
                'hm.metric',
                'hm.source_id',
                's.device_kind',
                'hm.started_at',
                'hm.ended_at',
                'hm.value',
                'hm.local_date',
            ])
            ->whereIn('hm.metric', $metrics)
            ->orderBy('hm.local_date')
            ->orderBy('hm.started_at')
            ->get();

        /** @var array<string, array<string, list<MetricBucket>>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse((string) $row->local_date)->toDateString();

            $grouped[(string) $row->metric][$date][] = MetricBucket::fromRow($row);
        }

        $out = [];

        foreach ($grouped as $metric => $byDate) {
            // The query is ordered, but grouping in PHP doesn't promise it,
            // and the EMA recurrence depends on the order.
            ksort($byDate);

            foreach ($byDate as $date => $buckets) {
                $pick = $this->selector->pickInstant($metric, $buckets);

                if ($pick !== null) {
                    $out[$metric][$date] = $pick->value;
                }
            }
        }

        return $out;
    }

    /**
     * The range the card opens on: the shortest one holding enough weigh-ins
     * to be a trend rather than a couple of dots, All if none does. See
     * config('health.trends.body.default_min_points') for why this is
     * derived and not a constant.
     *
     * @param  list<array{key: string, days: int|null}>  $ranges
     * @param  list<array{date: string}>  $points
     */
    private function openingRange(array $ranges, array $points, CarbonImmutable $end, int $minPoints): string
    {
        $fallback = $ranges === [] ? 'all' : (string) $ranges[array_key_last($ranges)]['key'];

        foreach ($ranges as $range) {
            if ($range['days'] === null) {
                return (string) $range['key'];
            }

            $start = $end->subDays((int) $range['days'] - 1)->toDateString();

            $inWindow = 0;

            foreach ($points as $point) {
                if ($point['date'] >= $start && $point['date'] <= $end->toDateString()) {
                    $inWindow++;
                }
            }

            if ($inWindow >= $minPoints) {
                return (string) $range['key'];
            }
        }

        return $fallback;
    }
}
