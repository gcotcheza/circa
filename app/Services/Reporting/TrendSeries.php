<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use App\Models\DailySummary;

/**
 * The 7- or 28-day series behind the trends page.
 *
 * The EMA is computed here, not in the chart: daily weight noise is ±1 kg,
 * bigger than a week of real change, so raw points are a cloud, not a
 * trend, and the smoothed series is what the TDEE estimator's OLS slope
 * fits too — two implementations (PHP for the estimator, JS for the
 * picture) would eventually disagree, showing a chart the estimate wasn't
 * derived from. Gaps are skipped, never interpolated: four days without a
 * weigh-in are four days of no information, and inventing points would
 * feed a fabricated slope into a real number later. The EMA is seeded from
 * the FULL weigh-in history, not the window, so a 7-day chart's left edge
 * isn't a cold start that looks like a jump. The smoothing itself lives in
 * WeightEma, which this class draws and TdeeEstimator fits a line through.
 */
final class TrendSeries
{
    public function __construct(private readonly WeightEma $ema = new WeightEma) {}

    /**
     * @return array<string, mixed>
     */
    public function props(int $days, ?string $endDate = null): array
    {
        $tz = (string) config('health.timezone');
        $alpha = (float) config('health.trends.ema_alpha', 0.25);

        $end = $endDate === null
            ? CarbonImmutable::now($tz)->startOfDay()
            : CarbonImmutable::parse($endDate, $tz)->startOfDay();

        $start = $end->subDays($days - 1);

        $summaries = DailySummary::query()
            ->whereBetween('local_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('local_date')
            ->get()
            ->keyBy(fn (DailySummary $s): string => $s->local_date->toDateString());

        $ema = $this->ema->series($alpha);

        $series = [];

        /** @var list<EnergyBalance> $comparable */
        $comparable = [];

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $summary = $summaries->get($date);

            /*
             * The same subtraction the day view makes, made here too: the
             * energy chart's two bars per day raise "which is taller," so
             * the answer travels with the day rather than being reworked in
             * the browser — the rule BalanceLineTest already holds
             * BalanceCard to, so the tooltip can't disagree with the card
             * the user tapped through to. `provisional` is the day's own
             * coverage flags: a still-accruing burn has a still-moving
             * balance.
             */
            $balance = null;

            if ($summary !== null) {
                $balance = EnergyBalance::from(
                    $summary->totalKcalOut(),
                    $this->number($summary->kcal_in_min),
                    $this->number($summary->kcal_in_mid),
                    $this->number($summary->kcal_in_max),
                    ! $summary->expenditureIsTrustworthy(),
                );

                /*
                 * Only honestly-countable days go into the tally: a
                 * complete food log and a burn not still accruing. See
                 * BalanceTally for why a partial log always leans the same
                 * way and would flatter every week if counted.
                 */
                if ($balance !== null && $summary->is_complete_log && ! $balance->provisional) {
                    $comparable[] = $balance;
                }
            }

            $series[] = [
                'date'   => $date,
                'label'  => $cursor->isoFormat('dd D'),
                'kcalIn' => [
                    'min' => $this->number($summary?->kcal_in_min),
                    'mid' => $this->number($summary?->kcal_in_mid),
                    'max' => $this->number($summary?->kcal_in_max),
                ],
                'kcalOut'               => $summary?->totalKcalOut(),
                'activeKcal'            => $this->number($summary?->active_kcal),
                'restingKcal'           => $this->number($summary?->resting_kcal),
                'steps'                 => $this->number($summary?->steps),
                'weightKg'              => $this->number($summary?->weight_kg),
                'weightEma'             => $ema[$date] ?? null,
                'isCompleteLog'         => (bool) $summary?->is_complete_log,
                'activeKcalIsPartial'   => (bool) $summary?->active_kcal_is_partial,
                'hasFullMetricCoverage' => (bool) $summary?->has_full_metric_coverage,
                'balance'               => $balance?->toArray(),
            ];
        }

        return [
            'range'    => $days,
            'ranges'   => array_values((array) config('health.trends.ranges', [7, 28])),
            'emaAlpha' => $alpha,
            'start'    => $start->toDateString(),
            'end'      => $end->toDateString(),
            'days'     => $series,

            // The energy chart's caption: which way the window came out,
            // over the days it's honest to count. See BalanceTally.
            'balanceTally' => BalanceTally::from($comparable)->toArray(),
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
