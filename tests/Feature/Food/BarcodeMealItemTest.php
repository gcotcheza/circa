<?php

declare(strict_types=1);

namespace Tests\Feature\Food;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\FoodProduct;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Logging a scanned product.
 *
 * A barcode item is an ORDINARY per-100 g item carrying a `food_product_id`, so
 * everything that already existed can edit, re-weigh, delete and re-log it, and
 * a meal can mix a scanned yoghurt with a typed coffee. What is specific to it:
 * the absolutes derive from portion x density, so 50 g of a 539 kcal/100 g
 * spread is 269.5 kcal and NOT 539; the ranges are zero-width, a manufacturer's
 * declaration being point data; and the provenance link survives an edit, the
 * one place it can be silently lost, because the form replaces items wholesale.
 */
final class BarcodeMealItemTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    private const NUTELLA = '3017624010701';

    public function test_a_scanned_product_is_logged_with_derived_absolutes_and_a_link_to_the_product(): void
    {
        $this->product();

        $this->post('/meals', $this->payload(grams: 50))->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        self::assertSame(self::NUTELLA, $item->food_product_id);
        self::assertSame('Nutella', $item->name);

        // Density stored, absolute derived — never the other way round.
        self::assertSame(539.0, (float) $item->kcal_per_100g_min);
        self::assertSame(50.0, (float) $item->portion_g_min);
        self::assertSame(269.5, $item->kcalRange()['min']);
        self::assertSame(15.45, round($item->macroRange('fat')['min'], 2));
        self::assertSame(3.15, round($item->macroRange('protein')['min'], 2));
    }

    public function test_a_scanned_item_is_zero_width(): void
    {
        $this->product();

        $this->post('/meals', $this->payload(grams: 50))->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        // Point data: the uncertainty is in the grams typed, not the label on the jar.
        foreach (['portion_g', 'kcal_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g'] as $field) {
            self::assertSame(
                (float) $item->{$field.'_min'},
                (float) $item->{$field.'_max'},
                $field.' should be zero-width'
            );
        }
    }

    public function test_editing_the_portion_recomputes_the_meal_without_re_fetching_anything(): void
    {
        $this->product();

        $uuid = (string) Str::uuid();

        $this->post('/meals', $this->payload(grams: 50, uuid: $uuid))->assertRedirect();
        $this->put('/meals/'.$uuid, $this->payload(grams: 200, uuid: $uuid))->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        self::assertSame(200.0, (float) $item->portion_g_min);
        self::assertSame(1078.0, $item->kcalRange()['min']);

        // The link survives the edit: the form replaces a meal's items wholesale,
        // so a field that is not round-tripped is dropped on the first correction.
        self::assertSame(self::NUTELLA, $item->food_product_id);
    }

    public function test_the_link_survives_a_one_tap_re_log(): void
    {
        $this->product();

        $this->post('/meals', $this->payload(grams: 50))->assertRedirect();

        $meal = Meal::query()->firstOrFail();

        $this->post('/meals/'.$meal->uuid.'/repeat', [
            'uuid' => (string) Str::uuid(),
            'date' => '2026-06-16',
            'time' => '08:30',
        ])->assertRedirect();

        self::assertSame(2, MealItem::query()->where('food_product_id', self::NUTELLA)->count());
    }

    public function test_a_food_product_id_that_does_not_exist_is_a_validation_error(): void
    {
        // It is a foreign key: a client that invents one is told so, not handed
        // a constraint violation raised as a 500.
        $this->post('/meals', $this->payload(grams: 50))
            ->assertSessionHasErrors('items.0.food_product_id');

        self::assertSame(0, Meal::query()->count());
    }

    public function test_a_manually_typed_item_still_needs_no_product(): void
    {
        $this->post('/meals', [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '13:20',
            'items' => [['name' => 'Flat white', 'basis' => 'absolute', 'kcal' => 120]],
        ])->assertRedirect();

        self::assertNull(MealItem::query()->firstOrFail()->food_product_id);
    }

    private function product(): FoodProduct
    {
        return FoodProduct::factory()->create([
            'barcode'          => self::NUTELLA,
            'name'             => 'Nutella',
            'brand'            => 'Ferrero',
            'kcal_per_100g'    => 539,
            'protein_per_100g' => 6.3,
            'carbs_per_100g'   => 57.5,
            'fat_per_100g'     => 30.9,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(float $grams, ?string $uuid = null): array
    {
        return [
            'uuid'  => $uuid ?? (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '13:20',
            'items' => [[
                'name'             => 'Nutella',
                'basis'            => 'per_100g',
                'grams'            => $grams,
                'kcal_per_100g'    => 539,
                'protein_per_100g' => 6.3,
                'carbs_per_100g'   => 57.5,
                'fat_per_100g'     => 30.9,
                'food_product_id'  => self::NUTELLA,
            ]],
        ];
    }
}
