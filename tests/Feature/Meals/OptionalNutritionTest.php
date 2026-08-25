<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * An item with a name and no numbers is a legal meal item.
 *
 * A deliberate reversal of step 3, which required kcal on every item: that made
 * a meal whose calories the user does not know unwritable, and the workaround is
 * typing a plausible number — a fabricated 600 kcal is indistinguishable from a
 * measured one the moment it reaches `daily_summaries`.
 *
 * So: the NAME is required, since a row nobody can read back records nothing.
 * The rest is optional, contributes 0, and can be filled in later.
 */
final class OptionalNutritionTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    public function test_an_item_with_only_a_name_is_saved(): void
    {
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $item = MealItem::query()->sole();

        self::assertSame('Chicken curry', $item->name);
        self::assertSame('chicken-curry', $item->slug);

        // The quick mode's 100 g basis, zero across: the columns are NOT NULL,
        // and 0 is the honest contribution of a food nobody has numbered.
        self::assertSame(100.0, (float) $item->portion_g_min);
        self::assertSame(100.0, (float) $item->portion_g_max);
        self::assertSame(0.0, (float) $item->kcal_per_100g_min);
        self::assertSame(0.0, (float) $item->kcal_per_100g_max);
        self::assertSame(0.0, (float) $item->protein_per_100g_max);
    }

    public function test_the_day_counts_a_blank_item_as_zero_rather_than_refusing_it(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => 120],
        ]))->assertSessionHasNoErrors();

        $meal = Meal::query()->sole();

        self::assertCount(2, $meal->items);

        // 120 from the coffee, nothing from the curry. Not 120-and-a-guess.
        $total = $meal->items->sum(
            static fn (MealItem $item): float => (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );

        self::assertSame(120.0, $total);
    }

    public function test_a_partly_filled_item_keeps_exactly_what_was_typed(): void
    {
        $this->post('/meals', $this->payload([
            // Protein but no calories: ordinary to know about a piece of fish
            // and not about the oil it was cooked in.
            ['name' => 'Salmon', 'basis' => 'absolute', 'protein' => 34],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(34.0, (float) $item->protein_per_100g_min);
        self::assertSame(34.0, (float) $item->protein_per_100g_max);
        self::assertSame(0.0, (float) $item->kcal_per_100g_min);
    }

    public function test_a_per_100g_item_needs_neither_a_weight_nor_a_density(): void
    {
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'per_100g'],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        // No weight falls back to the same 100 g basis, so both entry modes
        // converge on an empty item rather than diverge.
        self::assertSame(100.0, (float) $item->portion_g_min);
        self::assertSame(0.0, (float) $item->kcal_per_100g_min);
    }

    public function test_a_weight_without_a_density_is_still_the_weight(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 180],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(180.0, (float) $item->portion_g_min);
        self::assertSame(180.0, (float) $item->portion_g_max);
        self::assertSame(0.0, (float) $item->kcal_per_100g_min);
    }

    public function test_the_name_is_still_required(): void
    {
        $this->from('/')->post('/meals', $this->payload([
            ['name' => '', 'basis' => 'absolute', 'kcal' => 300],
        ]))->assertSessionHasErrors('items.0.name');

        self::assertSame(0, Meal::query()->count());
    }

    public function test_the_plausibility_ceilings_still_hold(): void
    {
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Impossible', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => 5000],
        ]))->assertSessionHasErrors('items.0.kcal_per_100g');

        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Negative', 'basis' => 'absolute', 'kcal' => -5],
        ]))->assertSessionHasErrors('items.0.kcal');
    }

    public function test_editing_a_meal_can_blank_a_number_that_was_there(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => 'Chicken curry', 'basis' => 'absolute', 'kcal' => 620],
        ]))->assertSessionHasNoErrors();

        $meal = Meal::query()->sole();

        // The 620 was invented and comes back out; refusing would leave a
        // made-up number in the log permanently.
        $this->put("/meals/{$meal->uuid}", $this->payload([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ], $meal->uuid))->assertSessionHasNoErrors();

        self::assertSame(0.0, (float) MealItem::query()->sole()->kcal_per_100g_min);
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
