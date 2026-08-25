<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\MealItem;
use App\Enums\MealStatus;
use App\Models\Supplement;
use App\Models\FoodProduct;
use App\Models\SupplementIntake;
use App\Models\SupplementNutrient;
use Illuminate\Support\Collection;
use App\Services\Rollup\IntakeBand;
use App\Services\Rollup\IntakeCalculator;

/**
 * Micronutrients, from the only two places this app has any: the labels on
 * the bottles, and whatever Open Food Facts published about a scanned
 * barcode.
 *
 * THE TWO KINDS OF NUMBER HERE ARE NOT THE SAME KIND OF NUMBER. SUPPLEMENTS
 * ARE EXACT: a label says 500 µg of B12 per tablet, one tablet a day, so a
 * ticked day is 500 µg — transcribed from the panel by a prompt built to
 * forbid conversion, inference and rounding (see PromptV1Label), then
 * multiplied by an integer. The only uncertainty is whether the tick is
 * true, and that's adherence, counted and stated separately.
 *
 * FOOD IS NOT MERELY UNCERTAIN, IT IS MOSTLY ABSENT. This app estimates four
 * quantities per item — kcal, protein, carbs, fat — and nothing else; no
 * vitamin content has ever lived on a `meal_items` row. The one exception is
 * a barcode-scanned product, where Open Food Facts may publish fibre, salt,
 * sugars or saturates as real figures for the scanned fraction of intake.
 *
 * THE FAILURE THIS BLOCK EXISTS TO PREVENT is a report that adds the two
 * together. "Your B12 intake was 500 µg" reads as a statement about a diet
 * but is one about a tablet — the food contribution isn't zero, it's
 * UNKNOWN, and an unknown added to an exact figure produces an exact-looking
 * figure wrong by an unknown amount. So every nutrient entry carries its
 * food coverage explicitly (almost always 0%), and the prompt forbids a
 * combined total wherever coverage is incomplete.
 *
 * NUTRIENT NAMES ARE NOT NORMALISED, TRANSLATED OR MERGED. Printed on the
 * bottle in Dutch, transcribed verbatim on purpose, since the user checks
 * the app against the bottle in hand — "Vitamine B12 (als cyanocobalamine)"
 * stays exactly that. Two products listing the same nutrient under
 * different printed names stay two entries, since deciding they're the same
 * substance is a chemistry judgement this code isn't entitled to make; the
 * model is told so, so it may note the overlap in prose without anything
 * here having silently summed them. Amounts are only summed across products
 * when the printed NAME and UNIT both match exactly.
 */
final class MicronutrientLedger
{
    /**
     * Open Food Facts keys this app is willing to read off a scanned
     * product, with the unit OFF publishes them in per 100 g.
     *
     * Deliberately short: every entry is a nutrient OFF populates densely
     * enough to report AND that a reader recognises without a chemistry
     * degree. The long tail of `vitamin-b12_100g`-style fields exists in the
     * schema but is empty on most products, so including them would
     * manufacture a table of nulls that looks like coverage.
     */
    private const OFF_NUTRIENTS = [
        'fiber_100g'         => ['name' => 'Fibre', 'unit' => 'g'],
        'sugars_100g'        => ['name' => 'Sugars', 'unit' => 'g'],
        'saturated-fat_100g' => ['name' => 'Saturated fat', 'unit' => 'g'],
        'salt_100g'          => ['name' => 'Salt', 'unit' => 'g'],
    ];

    public function __construct(
        private readonly IntakeCalculator $intake = new IntakeCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportRange $range): array
    {
        $supplements = Supplement::query()->active()->with('nutrients')->get();

        $adherence = $this->adherence($range, $supplements);

        return [
            'how_to_read_this' => 'Supplement figures are EXACT: transcribed from the printed panel and '
                .'multiplied by whole units taken on days that were ticked. Food figures are NOT '
                .'available for micronutrients — this app estimates only calories, protein, carbohydrate '
                .'and fat per food item. Where a food-side figure exists at all it comes from a scanned '
                .'barcode and covers only the fraction of intake stated as food_coverage_pct. '
                .'Never add an exact supplement figure to an unknown food figure and present the result '
                .'as a total intake.',

            'supplements' => $this->supplements($supplements, $adherence),
            'nutrients'   => $this->nutrients($supplements, $adherence),
            'food_side'   => $this->foodSide($range),
        ];
    }

    /**
     * Days each supplement was ticked, and days it was expected.
     *
     * "Expected" starts at the supplement's own `created_at`, per
     * SupplementAdherence's definition — a bottle bought Thursday can't have
     * been missed Monday.
     *
     * @param  Collection<int, Supplement>  $supplements
     * @return array<int, array{taken: int, expected: int, dates: list<string>}>
     */
    private function adherence(ReportRange $range, Collection $supplements): array
    {
        if ($supplements->isEmpty()) {
            return [];
        }

        $intakes = SupplementIntake::query()
            ->forSupplementsInRange($supplements, $range->start, $range->end)
            ->get()
            ->groupBy('supplement_id');

        $dates = $range->dates();

        $out = [];

        foreach ($supplements as $supplement) {
            $startedOn = $supplement->startedOn();

            $expected = count(array_filter($dates, static fn (string $d): bool => $d >= $startedOn));

            $taken = ($intakes->get($supplement->id) ?? collect())
                ->map(fn (SupplementIntake $i): string => $i->local_date->toDateString())
                ->values()
                ->all();

            sort($taken);

            $out[$supplement->id] = [
                'taken'    => count($taken),
                'expected' => $expected,
                'dates'    => $taken,
            ];
        }

        return $out;
    }

    /**
     * One entry per bottle: what is in it, how much of it a day is, and how
     * many days of this window it was actually taken.
     *
     * @param  Collection<int, Supplement>  $supplements
     * @param  array<int, array{taken: int, expected: int, dates: list<string>}>  $adherence
     * @return list<array<string, mixed>>
     */
    private function supplements(Collection $supplements, array $adherence): array
    {
        return array_values($supplements->map(function (Supplement $supplement) use ($adherence): array {
            $days = $adherence[$supplement->id] ?? ['taken' => 0, 'expected' => 0, 'dates' => []];

            return [
                'name'  => $supplement->name,
                'brand' => $supplement->brand,
                // The serving the panel's figures are FOR, verbatim — the
                // label prompt refuses to restate figures against a
                // different serving, so this is the only thing saying what
                // "50 mg" is 50 mg of.
                'serving_text'           => $supplement->serving_text,
                'units_per_day'          => $supplement->units_per_day,
                'started_on'             => $supplement->startedOn(),
                'days_taken_in_range'    => $days['taken'],
                'days_expected_in_range' => $days['expected'],
                'dates_taken'            => $days['dates'],
                'nutrients_per_day'      => array_map(
                    static fn (array $line): array => [
                        'nutrient'        => $line['nutrient'],
                        'amount_per_unit' => $line['amount'],
                        'amount_per_day'  => $line['perDay'],
                        'unit'            => $line['unit'],
                    ],
                    $supplement->dailyNutrients(),
                ),
            ];
        })->all());
    }

    /**
     * The per-nutrient roll-up across every bottle.
     *
     * @param  Collection<int, Supplement>  $supplements
     * @param  array<int, array{taken: int, expected: int, dates: list<string>}>  $adherence
     * @return list<array<string, mixed>>
     */
    private function nutrients(Collection $supplements, array $adherence): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($supplements as $supplement) {
            $daysTaken = $adherence[$supplement->id]['taken'] ?? 0;
            $daysExpected = $adherence[$supplement->id]['expected'] ?? 0;

            /** @var SupplementNutrient $line */
            foreach ($supplement->nutrients as $line) {
                if ($line->amount === null || $line->unit === null) {
                    // A line the transcription read the NAME of but not the
                    // figure of. Kept with nulls, since the review screen
                    // shows it that way and dropping it silently would
                    // disagree with the screen the user checked.
                    $key = $line->nutrient.'|?';

                    $rows[$key] ??= [
                        'nutrient'             => $line->nutrient,
                        'unit'                 => null,
                        'per_day_if_all_taken' => null,
                        'window_total_exact'   => null,
                        'sources'              => [],
                        'unreadable_amount'    => true,
                    ];

                    $rows[$key]['sources'][] = ['supplement' => $supplement->name, 'amount_per_unit' => null];

                    continue;
                }

                // Name AND unit must both match to combine — "Vitamine D3"
                // in µg and in IE stay two rows, since converting between
                // them is exactly what the label prompt forbids.
                $key = $line->nutrient.'|'.$line->unit;

                $perDay = (float) $line->amount * $supplement->units_per_day;

                $rows[$key] ??= [
                    'nutrient'             => $line->nutrient,
                    'unit'                 => $line->unit,
                    'per_day_if_all_taken' => 0.0,
                    'window_total_exact'   => 0.0,
                    'sources'              => [],
                    'unreadable_amount'    => false,
                ];

                $rows[$key]['per_day_if_all_taken'] += $perDay;
                $rows[$key]['window_total_exact'] += $perDay * $daysTaken;
                $rows[$key]['sources'][] = [
                    'supplement'      => $supplement->name,
                    'amount_per_unit' => (float) $line->amount,
                    'units_per_day'   => $supplement->units_per_day,
                    'days_taken'      => $daysTaken,
                    'days_expected'   => $daysExpected,
                ];
            }
        }

        $out = array_map(static function (array $row): array {
            if ($row['per_day_if_all_taken'] !== null) {
                $row['per_day_if_all_taken'] = round($row['per_day_if_all_taken'], 4);
            }

            if ($row['window_total_exact'] !== null) {
                $row['window_total_exact'] = round($row['window_total_exact'], 4);
            }

            // Stated on every row, not just the block header — a row is
            // what gets quoted.
            $row['food_contribution'] = null;
            $row['food_coverage_pct'] = 0;
            $row['food_note'] = 'This app does not estimate this nutrient from food. '
                .'The figure above is the supplement contribution only.';

            return $row;
        }, array_values($rows));

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['nutrient'], (string) $b['nutrient']));

        return $out;
    }

    /**
     * What the FOOD side of the window can actually say.
     *
     * Two parts, different in kind:
     *
     *   MACROS      estimated for every item, as ranges — real coverage,
     *               real uncertainty, the honest thing the report has when
     *               it talks about diet composition.
     *
     *   BARCODE     fibre, sugars, saturates and salt, ONLY for scanned
     *               items whose OFF entry published the field. `coverage_pct`
     *               is the share of the window's estimated calories that
     *               came from such items — the honest denominator: 4%
     *               coverage means the total below describes 4% of the food.
     *
     * @return array<string, mixed>
     */
    private function foodSide(ReportRange $range): array
    {
        /** @var Collection<int, MealItem> $items */
        $items = MealItem::query()
            ->whereNotNull('meal_items.confirmed_at')
            ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
            ->where('meals.status', MealStatus::Confirmed->value)
            ->whereBetween('meals.local_date', [$range->start, $range->end])
            ->select('meal_items.*')
            ->get();

        $macros = [];

        foreach (['protein', 'carbs', 'fat'] as $macro) {
            $macros[$macro.'_g'] = IntakeBand::fromRanges(
                array_values($items->map(fn (MealItem $item): array => $this->intake->range($item, $macro))->all())
            )->toArray(1);
        }

        return [
            'macronutrients' => [
                'tracked' => true,
                'basis'   => 'Estimated per item from portion ranges and per-100 g density ranges. '
                    .'Every figure is an interval, not a point.',
                'window_totals' => $macros,
            ],

            'micronutrients' => [
                'tracked' => false,
                'why'     => 'Food items in this app carry four estimated quantities only — calories, protein, '
                    .'carbohydrate and fat. No vitamin or mineral content is estimated from food, so the '
                    .'food-side contribution to every supplement nutrient above is genuinely unknown '
                    .'rather than zero.',
                'from_scanned_barcodes' => $this->fromBarcodes($items),
            ],
        ];
    }

    /**
     * The handful of extra nutrients OFF published for scanned items in
     * this window, with the coverage that qualifies them.
     *
     * @param  Collection<int, MealItem>  $items
     * @return list<array<string, mixed>>
     */
    private function fromBarcodes(Collection $items): array
    {
        $totalKcalMid = 0.0;

        foreach ($items as $item) {
            $range = $this->intake->range($item, 'kcal');
            $totalKcalMid += ($range['min'] + $range['max']) / 2;
        }

        $barcoded = $items->filter(static fn (MealItem $i): bool => $i->food_product_id !== null);

        if ($barcoded->isEmpty() || $totalKcalMid <= 0.0) {
            return [];
        }

        /** @var Collection<string, FoodProduct> $products */
        $products = FoodProduct::query()
            ->whereIn('barcode', $barcoded->pluck('food_product_id')->unique()->all())
            ->get()
            ->keyBy('barcode');

        $out = [];

        foreach (self::OFF_NUTRIENTS as $key => $meta) {
            $ranges = [];
            $kcalCovered = 0.0;

            foreach ($barcoded as $item) {
                $product = $products->get((string) $item->food_product_id);

                $per100g = $this->rawNutriment($product, $key);

                if ($per100g === null) {
                    continue;
                }

                // The portion is a RANGE, so the nutrient is too — the same
                // grams x density arithmetic every other food figure uses.
                $ranges[] = [
                    'min' => (float) $item->portion_g_min * $per100g / 100,
                    'max' => (float) $item->portion_g_max * $per100g / 100,
                ];

                $kcal = $this->intake->range($item, 'kcal');
                $kcalCovered += ($kcal['min'] + $kcal['max']) / 2;
            }

            if ($ranges === []) {
                continue;
            }

            $out[] = [
                'nutrient'            => $meta['name'],
                'unit'                => $meta['unit'],
                'window_total'        => IntakeBand::fromRanges($ranges)->toArray(1),
                'items_with_a_figure' => count($ranges),
                // What makes the total readable: 6% coverage means the
                // figure describes 6% of what was eaten, nothing about the
                // other 94%.
                'food_coverage_pct' => round($kcalCovered / $totalKcalMid * 100, 1),
                'source'            => 'Open Food Facts, published per 100 g for the scanned product.',
            ];
        }

        return $out;
    }

    private function rawNutriment(?FoodProduct $product, string $key): ?float
    {
        if ($product === null) {
            return null;
        }

        $raw = $product->raw;

        if (! is_array($raw)) {
            return null;
        }

        $nutriments = $raw['nutriments'] ?? null;

        if (! is_array($nutriments)) {
            return null;
        }

        $value = $nutriments[$key] ?? null;

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        // A per-100 g figure above 100 g is a corrupt crowd-sourced row, not
        // a remarkable food — dropped, not clamped, as Nutriments does for
        // an impossible macro.
        return is_finite($number) && $number >= 0 && $number <= 100 ? $number : null;
    }
}
