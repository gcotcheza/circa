<?php

declare(strict_types=1);

namespace App\Services\Rollup;

use App\Models\Meal;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\DailySummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

/**
 * Build (or rebuild) one `daily_summaries` row from scratch.
 *
 * Fully derived and idempotent — reads `health_metrics`, sleep sessions
 * and `meals`, writes exactly one row, so running it twice is running it
 * once, which lets a dirty-date rebuild be fire-and-forget.
 *
 * Deliberately does NOT sum across sources (every metric goes through
 * BucketSelector, resolving both "Watch and iPhone reported the same
 * hour" and overlapping buckets) or present an incomplete day as
 * complete: today's row is written but flagged provisional, and a day
 * the Watch spent charging is flagged partial, both backed by a measured
 * coverage fraction.
 */
final class DailySummaryBuilder
{
    public function __construct(
        private readonly BucketSelector $selector = new BucketSelector,
        private readonly IntakeCalculator $intake = new IntakeCalculator,
    ) {}

    public function build(string|CarbonInterface $date): DailySummary
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $tz = (string) config('health.timezone');
        $config = (array) config('health.rollup');

        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay();
        // addDay(), not addHours(24): a DST boundary makes the local day
        // 23 or 25 hours, and coverage below divides by this.
        $dayEnd = $dayStart->addDay();

        $now = CarbonImmutable::now();
        $isProvisional = $dayEnd > $now;

        // The window a metric could possibly have covered by now — for a past
        // day that's the whole day, for today only what's elapsed, or the
        // flags would call every morning a coverage failure.
        $span = max(
            3600,
            min($dayEnd->getTimestamp(), $now->getTimestamp()) - $dayStart->getTimestamp()
        );

        $buckets = $this->bucketsFor($date, $this->metricsOf($config));

        $active = $this->selector->select($config['active_metric'], $buckets[$config['active_metric']] ?? []);
        $resting = $this->selector->select($config['resting_metric'], $buckets[$config['resting_metric']] ?? []);
        $steps = $this->selector->select($config['steps_metric'], $buckets[$config['steps_metric']] ?? []);
        $exercise = $this->selector->select($config['exercise_metric'], $buckets[$config['exercise_metric']] ?? []);
        $distance = $this->selector->select($config['distance_metric'], $buckets[$config['distance_metric']] ?? []);

        $weight = $this->selector->pickInstant(
            $config['weight_metric'],
            $buckets[$config['weight_metric']] ?? []
        );

        $selections = [
            $config['active_metric']   => $active,
            $config['resting_metric']  => $resting,
            $config['steps_metric']    => $steps,
            $config['exercise_metric'] => $exercise,
            $config['distance_metric'] => $distance,
        ];

        $activeCoverage = min(1.0, $active->coveredSecondsBy($config['coverage']['watch_kinds']) / $span);

        $items = $this->confirmedItems($date);
        $bands = $this->intake->bands($items);

        return DailySummary::query()->updateOrCreate(
            ['local_date' => $date],
            [
                'kcal_in_min' => $bands['kcal']->min,
                'kcal_in_mid' => $bands['kcal']->mid,
                'kcal_in_max' => $bands['kcal']->max,

                'protein_g_min' => $bands['protein']->min,
                'protein_g_mid' => $bands['protein']->mid,
                'protein_g_max' => $bands['protein']->max,

                'carbs_g_min' => $bands['carbs']->min,
                'carbs_g_mid' => $bands['carbs']->mid,
                'carbs_g_max' => $bands['carbs']->max,

                'fat_g_min' => $bands['fat']->min,
                'fat_g_mid' => $bands['fat']->mid,
                'fat_g_max' => $bands['fat']->max,

                'active_kcal'      => $active->total(),
                'resting_kcal'     => $resting->total(),
                'steps'            => $steps->total(),
                'exercise_minutes' => $exercise->total(),
                'distance_km'      => $distance->total(),

                'weight_kg' => $weight?->value,

                'is_complete_log' => $this->isCompleteLog($date, $items, $bands['kcal'], $config),

                // A day still in progress is never "fully covered" however
                // good the coverage so far — hours that haven't happened
                // can't have been measured.
                'has_full_metric_coverage' => ! $isProvisional
                    && $this->hasFullCoverage($selections, $span, $config),

                'active_kcal_is_partial' => $activeCoverage < (float) $config['coverage']['active_partial_fraction'],
                'active_kcal_coverage'   => round($activeCoverage, 3),

                'rebuilt_at' => $now,
            ]
        );
    }

    /**
     * Every metric this builder reads, deduplicated.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function metricsOf(array $config): array
    {
        return array_values(array_unique([
            $config['active_metric'],
            $config['resting_metric'],
            $config['steps_metric'],
            $config['exercise_metric'],
            $config['distance_metric'],
            $config['weight_metric'],
        ]));
    }

    /**
     * One query for the whole day, grouped by metric.
     *
     * Keyed on `local_date` — the Postgres generated column — so a bucket
     * belongs to the day its bucket STARTED in. A 23:30-00:30 bucket lands
     * wholly on the earlier day rather than being split, since splitting
     * would assume the value spreads evenly across the hour, the exact
     * assumption BucketSelector exists to avoid.
     *
     * @param  list<string>  $metrics
     * @return array<string, list<MetricBucket>>
     */
    private function bucketsFor(string $date, array $metrics): array
    {
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
            ])
            ->where('hm.local_date', $date)
            ->whereIn('hm.metric', $metrics)
            ->orderBy('hm.started_at')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->metric][] = MetricBucket::fromRow($row);
        }

        return $grouped;
    }

    /**
     * CONFIRMED items of the day's CONFIRMED meals.
     *
     * Nothing is logged without a tap — a draft or vision-proposed meal
     * isn't intake yet. BOTH halves are load-bearing: the meal filter alone
     * was enough while a confirmed meal could only hold confirmed items,
     * but a meal can now stay confirmed while carrying an unconfirmed
     * proposal (photographing dessert doesn't un-eat the main course) —
     * without the item filter the day's total would gain the dessert the
     * moment the model answered, before anyone agreed to it.
     *
     * @return Collection<int, MealItem>
     */
    private function confirmedItems(string $date): Collection
    {
        return MealItem::query()
            ->whereNotNull('confirmed_at')
            ->whereHas('meal', static function (Builder $meals) use ($date): void {
                // Larastan types a whereHas() closure as Builder<Model>, which
                // hides the related model's own scopes. Naming the relation's
                // model narrows THIS variable; an ignore on the line would
                // have excused every missing method on it, typos included.
                /** @var Builder<Meal> $meals */
                $meals->confirmed()->onLocalDate($date);
            })
            ->get();
    }

    /**
     * The `is_complete_log` heuristic (SPEC.md, "Honesty flags").
     *
     * All three conditions, since each alone has an obvious false
     * positive: a big breakfast clears the kcal floor, a late snack clears
     * the time test, three items of lettuce clear the count.
     *
     * @param  Collection<int, MealItem>  $items
     * @param  array<string, mixed>  $config
     */
    private function isCompleteLog(
        string $date,
        Collection $items,
        IntakeBand $kcal,
        array $config,
    ): bool {
        $rules = $config['complete_log'];

        if ($items->count() < (int) $rules['min_items']) {
            return false;
        }

        if (($kcal->mid ?? 0.0) < (float) $rules['kcal_floor']) {
            return false;
        }

        $lastMeal = Meal::query()->confirmed()->onLocalDate($date)->max('eaten_at');

        if ($lastMeal === null) {
            return false;
        }

        $tz = (string) config('health.timezone');

        $cutoff = CarbonImmutable::parse($date.' '.$rules['last_meal_after'], $tz);

        return CarbonImmutable::parse($lastMeal)->setTimezone($tz) >= $cutoff;
    }

    /**
     * @param  array<string, BucketSelection>  $selections
     * @param  array<string, mixed>  $config
     */
    private function hasFullCoverage(array $selections, int $span, array $config): bool
    {
        $required = $config['coverage']['required_metrics'];
        $threshold = (float) $config['coverage']['full_day_fraction'];

        foreach ($required as $metric) {
            $selection = $selections[$metric] ?? null;

            if ($selection === null || $selection->coveredSeconds() / $span < $threshold) {
                return false;
            }
        }

        return true;
    }
}
