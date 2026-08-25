<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\MealItem;
use App\Enums\MealStatus;
use Illuminate\Support\Collection;
use App\Services\Rollup\IntakeBand;
use App\Services\Rollup\IntakeCalculator;

/**
 * What was actually eaten in the window, with the grams, and with the width
 * of every figure kept intact.
 *
 * RANGES SURVIVE ALL THE WAY INTO THE PROMPT. Every food figure here is an
 * interval, since it came from a model reading a photograph and saying
 * "120-200 g of rice" — the daily view keeps it as an interval,
 * `daily_summaries` stores min/mid/max, and this hands the model an interval
 * too. Collapsing to a midpoint would make the prompt shorter and the whole
 * report a lie of exactly the kind this codebase's comments guard against:
 * "you ate 2 100 kcal" and "somewhere between 1 750 and 2 450 kcal" are
 * different statements, and only one is true.
 *
 * HALF-WIDTHS COMBINE IN QUADRATURE, NOT LINEARLY — summing every item's min
 * and every item's max would assume every estimate in a fortnight is wrong
 * in the same direction at once, producing a band too wide to say anything.
 * The same `IntakeBand::fromRanges` the daily rollup uses does the RSS
 * combination here, so a window total and a day total are built the same
 * way and can't disagree about what a range means.
 *
 * CONFIRMED ITEMS ONLY: `confirmed_at IS NOT NULL` on the item and a
 * confirmed meal around it, the same pair of conditions the daily rollup
 * applies — an unconfirmed proposal is a guess about a photograph nobody
 * has agreed to, and summarising it as food would report a meal that may
 * never have happened.
 */
final class FoodLedger
{
    public function __construct(
        private readonly IntakeCalculator $intake = new IntakeCalculator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $dayRows  the aligned day table
     * @return array<string, mixed>
     */
    public function build(ReportRange $range, array $dayRows): array
    {
        /** @var Collection<int, MealItem> $items */
        $items = MealItem::query()
            ->whereNotNull('meal_items.confirmed_at')
            ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
            ->where('meals.status', MealStatus::Confirmed->value)
            ->whereBetween('meals.local_date', [$range->start, $range->end])
            ->orderBy('meals.eaten_at')
            ->orderBy('meal_items.id')
            ->select('meal_items.*', 'meals.local_date as meal_local_date')
            ->get();

        $daysWithFood = count(array_filter($dayRows, static fn (array $r): bool => ($r['meals_logged'] ?? 0) > 0));
        $daysComplete = count(array_filter($dayRows, static fn (array $r): bool => ($r['food_log_complete'] ?? false) === true));

        return [
            'coverage' => [
                'days_in_range'                   => $range->days,
                'days_with_any_logged_meal'       => $daysWithFood,
                'days_with_no_food_logged_at_all' => $range->days - $daysWithFood,
                'days_flagged_as_a_complete_log'  => $daysComplete,

                /*
                 * Spelled out rather than left as a percentage, since a
                 * percentage invites the model to treat totals as "nearly
                 * right" — they're the total of what happened to be logged,
                 * zero rather than unknown on a day nothing was logged.
                 */
                'what_this_means' => 'Food totals below cover ONLY the meals that were logged and confirmed. '
                    .'A day with nothing logged contributes nothing; it is not an estimate of a fasting day. '
                    .'Any statement about intake must be qualified by how many days were actually logged.',
            ],

            'window_totals' => $this->windowTotals($dayRows),
            'per_day_means' => $this->perDayMeans($dayRows, $daysWithFood),

            /*
             * Every distinct food, with how often and how much. Aggregated by
             * name rather than listed meal by meal: a fortnight is a hundred
             * and twenty item rows, and "what does this person actually eat"
             * is a question about the distinct foods.
             */
            'foods' => $this->foods($items),

            'item_count'          => $items->count(),
            'distinct_food_count' => $items->pluck('name')->unique()->count(),
        ];
    }

    /**
     * The window's intake band, built from the per-day bands.
     *
     * Built from `daily_summaries` rows rather than re-summed from the
     * items, so the report's fortnight total and the day view's fourteen
     * daily totals are the same arithmetic applied twice and can't drift.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function windowTotals(array $dayRows): array
    {
        $ranges = [];

        foreach ($dayRows as $row) {
            if ($row['kcal_in_min'] === null || $row['kcal_in_max'] === null) {
                continue;
            }

            $ranges[] = ['min' => (float) $row['kcal_in_min'], 'max' => (float) $row['kcal_in_max']];
        }

        return [
            'kcal_in'           => IntakeBand::fromRanges($ranges)->toArray(0),
            'days_contributing' => count($ranges),
        ];
    }

    /**
     * The same band divided by the number of days that had food in them.
     *
     * Divided by LOGGED days rather than days in the range, and the divisor
     * is stated: a fortnight with four logged days has a mean over four, and
     * calling it a mean over fourteen would report a third of a diet.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function perDayMeans(array $dayRows, int $daysWithFood): array
    {
        if ($daysWithFood === 0) {
            return ['kcal_in' => null, 'divisor_days' => 0, 'divisor' => 'days with at least one logged meal'];
        }

        $ranges = [];

        foreach ($dayRows as $row) {
            if (($row['meals_logged'] ?? 0) === 0 || $row['kcal_in_min'] === null) {
                continue;
            }

            $ranges[] = ['min' => (float) $row['kcal_in_min'], 'max' => (float) $row['kcal_in_max']];
        }

        $band = IntakeBand::fromRanges($ranges);

        return [
            'kcal_in' => [
                'min' => $band->min === null ? null : round($band->min / $daysWithFood),
                'mid' => $band->mid === null ? null : round($band->mid / $daysWithFood),
                'max' => $band->max === null ? null : round($band->max / $daysWithFood),
            ],
            'divisor_days' => $daysWithFood,
            'divisor'      => 'days with at least one logged meal',
        ];
    }

    /**
     * Distinct foods, heaviest first.
     *
     * "Heaviest" by the MIDPOINT of the total kcal band — the list needs
     * ordering by something and kcal is what matters to the rest of the
     * report. The ordering is a presentation choice; the band per row is
     * the fact.
     *
     * @param  Collection<int, MealItem>  $items
     * @return list<array<string, mixed>>
     */
    private function foods(Collection $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            // Keyed on the slug where there is one — the same normalised name
            // meal-memory's fingerprint uses, so "White rice" and "white
            // rice " are one food here exactly as they are there.
            $key = $item->slug ?: mb_strtolower(trim((string) $item->name));

            $grouped[$key] ??= [
                'name'           => (string) $item->name,
                'times_eaten'    => 0,
                'grams_min'      => 0.0,
                'grams_max'      => 0.0,
                'kcal_ranges'    => [],
                'protein_ranges' => [],
                'carbs_ranges'   => [],
                'fat_ranges'     => [],
                'dates'          => [],
                'from_barcode'   => false,
            ];

            $grouped[$key]['times_eaten']++;
            $grouped[$key]['grams_min'] += (float) $item->portion_g_min;
            $grouped[$key]['grams_max'] += (float) $item->portion_g_max;

            foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
                $grouped[$key]["{$nutrient}_ranges"][] = $this->intake->range($item, $nutrient);
            }

            $date = (string) ($item->getAttribute('meal_local_date') ?? '');

            if ($date !== '' && ! in_array($date, $grouped[$key]['dates'], strict: true)) {
                $grouped[$key]['dates'][] = $date;
            }

            // A barcode-linked item's density is a published figure rather
            // than an estimate, and food-quality is entitled to know which.
            $grouped[$key]['from_barcode'] = $grouped[$key]['from_barcode'] || $item->food_product_id !== null;
        }

        $out = [];

        foreach ($grouped as $food) {
            $kcal = IntakeBand::fromRanges($food['kcal_ranges']);

            $out[] = [
                'name'            => $food['name'],
                'times_eaten'     => $food['times_eaten'],
                'total_grams'     => ['min' => round($food['grams_min']), 'max' => round($food['grams_max'])],
                'total_kcal'      => $kcal->toArray(0),
                'total_protein_g' => IntakeBand::fromRanges($food['protein_ranges'])->toArray(1),
                'total_carbs_g'   => IntakeBand::fromRanges($food['carbs_ranges'])->toArray(1),
                'total_fat_g'     => IntakeBand::fromRanges($food['fat_ranges'])->toArray(1),
                'dates'           => $food['dates'],
                'from_barcode'    => $food['from_barcode'],
                '_sort'           => $kcal->mid ?? 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['_sort'] <=> $a['_sort']);

        return array_map(static function (array $row): array {
            unset($row['_sort']);

            return $row;
        }, $out);
    }
}
