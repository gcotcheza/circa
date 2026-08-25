<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\MealItem;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE TWO TABS ARE TWO VIEWS OF ONE ITEM.
 *
 * WHAT THE USER SAW. An item that arrived in per-100 g shape — a barcode scan,
 * a confirmed estimate — showed FOUR EMPTY BOXES the moment "Quick" was tapped:
 * the tabs swapped which inputs were rendered and converted nothing. Nothing
 * was lost, but an empty kcal box honestly reads as "this has no calories".
 *
 * The conversion is client-side, in `resources/js/lib/basis.js`, because the
 * two modes are two ways of filling in one form. This file pins the half
 * reachable from here — the payloads it produces and what the server does with
 * them; the wiring is pinned by source inspection in
 * tests/Feature/Ui/QuickTabConversionTest.php, as ConfirmRequestShapeTest pins
 * the review sheet's request.
 *
 * THE RULE WITH TEETH: `grams` IS ONLY READ IN per_100g MODE.
 * TypedItem::fromValidated ignores it on an `absolute` item and
 * TypedItem::toRow files it at the 100 g BASIS, so a 120 g weighing posted as
 * `absolute` loses the 120 g. The sheet therefore posts a Quick-mode item as
 * per-100 g whenever it has a real weight, back-computing
 * density = total / portion x 100.
 */
final class QuickBasisConversionTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    /**
     * The 100 g basis: an item with no weight says the same thing in either
     * mode, byte for byte, so tapping a tab and saving cannot change the day.
     * Also why the conversion at the basis is an identity in the client rather
     * than `x * 100 / 100`, which in doubles is not the same number.
     */
    public function test_the_two_modes_agree_exactly_on_an_item_with_no_weight(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => 240, 'protein' => 8.125],
        ]))->assertSessionHasNoErrors();

        $quick = MealItem::query()->sole();

        $row = static fn (MealItem $item): array => [
            (float) $item->portion_g_min,
            (float) $item->portion_g_max,
            (float) $item->kcal_per_100g_min,
            (float) $item->kcal_per_100g_max,
            (float) $item->protein_per_100g_min,
        ];

        $expected = $row($quick);

        $meal = $quick->meal;
        self::assertNotNull($meal);
        $meal->delete();

        // What `toPer100g` posts: no weight, totals read back as densities.
        $this->post('/meals', $this->payload([
            ['name' => 'Cappuccino', 'basis' => 'per_100g', 'grams' => null, 'kcal_per_100g' => 240, 'protein_per_100g' => 8.125],
        ]))->assertSessionHasNoErrors();

        self::assertSame($expected, $row(MealItem::query()->sole()));
    }

    /** The prefill is grams x density / 100 — what the day reads off the row. */
    public function test_the_quick_prefill_is_the_total_the_row_already_holds(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 120, 'kcal_per_100g' => 200],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        // 120 g x 200 kcal/100 g = 240 kcal, the number `toQuick` shows.
        self::assertSame(
            240.0,
            (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );
    }

    /**
     * The edit that would have eaten a portion: 120 g of rice corrected to 300
     * kcal in Quick posts per-100 g, density back-computed over that weight.
     */
    public function test_a_total_typed_in_quick_over_a_real_portion_keeps_the_weight(): void
    {
        $this->post('/meals', $this->payload([
            // 300 / 120 x 100 = 250, computed in lib/basis.js::densityFor.
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 120, 'kcal_per_100g' => 250],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(120.0, (float) $item->portion_full_g_min);
        self::assertSame(120.0, (float) $item->portion_full_g_max);
        self::assertSame(250.0, (float) $item->kcal_per_100g_min);

        // The number the user actually typed comes back out of the row.
        self::assertSame(
            300.0,
            (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );
    }

    /**
     * Why the sheet may not post `absolute` from the Quick tab: the server does
     * not read `grams` in that mode, by design. A test, not a comment, because
     * it is the reason `payloadFor` exists — simplify that away and this fails
     * instead of the user's 120 g quietly becoming 100 g.
     */
    public function test_posting_a_weighed_item_as_absolute_loses_the_weight(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'absolute', 'grams' => 120, 'kcal' => 300],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(100.0, (float) $item->portion_g_min);
        self::assertSame(300.0, (float) $item->kcal_per_100g_min);

        // Still 300 kcal, but of a 100 g basis: right for a cappuccino, wrong
        // for something that was weighed.
        self::assertSame(
            300.0,
            (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );
    }

    /**
     * A blank density stays blank: `toQuick` maps a null density to a null
     * total rather than to 0, which keeps the item estimable and the sheet
     * still offering to estimate it.
     */
    public function test_a_blank_density_converts_to_a_blank_total(): void
    {
        $this->post('/meals', $this->payload([
            // Blank in the per-100 g tab, so blank in the Quick tab.
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 120, 'kcal_per_100g' => 200, 'fat_per_100g' => null],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(0.0, (float) $item->fat_per_100g_min);
        self::assertSame(0.0, (float) $item->fat_per_100g_max);
    }

    /**
     * The share survives the tab: the Quick boxes show what was eaten, the
     * payload carries what was on the plate.
     */
    public function test_the_share_still_divides_a_quick_edited_portion(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 120, 'kcal_per_100g' => 250, 'share_fraction' => 0.5],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(120.0, (float) $item->portion_full_g_min);
        self::assertSame(60.0, (float) $item->portion_g_min);
        self::assertEqualsWithDelta(0.5, (float) $item->share_fraction, 0.0001);

        // Half of the 300 kcal plate.
        self::assertSame(
            150.0,
            (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );
    }

    /**
     * Three decimals, end to end. The values a confirmed estimate leaves behind
     * — 158.333 kcal/100 g, 28.35 g of carbs — used to be rejected by the
     * sheet's own inputs (see tests/Feature/Ui/DecimalInputTest.php), and must
     * survive the round trip through the form too.
     */
    public function test_an_estimated_density_survives_a_save_from_the_sheet(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Kwark', 'basis' => 'per_100g', 'grams' => 217.5, 'kcal_per_100g' => 158.333, 'carbs_per_100g' => 28.35],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(158.333, (float) $item->kcal_per_100g_min);
        self::assertSame(28.35, (float) $item->carbs_per_100g_min);
        self::assertSame(217.5, (float) $item->portion_g_min);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items, ?string $uuid = null): array
    {
        return [
            'uuid'  => $uuid ?? (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '12:30',
            'items' => $items,
        ];
    }
}
