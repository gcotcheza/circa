<?php

declare(strict_types=1);

namespace Tests\Unit\Vision;

use Tests\TestCase;
use App\Services\Vision\TypedItem;
use App\Services\Vision\TypedMeal;
use App\Services\Vision\ProposedItem;

/**
 * The three jobs TypedMeal does, in isolation: the arithmetic under the feature
 * tests, including cases they would need a contrived model answer to reach — an
 * inverted range, a portion of zero, an item the user typed twice.
 */
final class TypedMealTest extends TestCase
{
    // --- describe() --------------------------------------------------------

    public function test_a_blank_item_is_described_as_not_given(): void
    {
        $described = $this->meal([$this->item('Chicken curry')])->describe();

        self::assertStringContainsString('Chicken curry', $described);
        self::assertStringContainsString('Portion: NOT GIVEN', $described);
        self::assertStringContainsString('Energy: NOT GIVEN', $described);
        self::assertStringNotContainsString('GIVEN — echo it back', $described);
    }

    public function test_a_typed_figure_is_described_as_given_and_to_be_echoed(): void
    {
        $described = $this->meal([
            $this->item('Cappuccino', values: ['kcal' => 120.0]),
        ])->describe();

        self::assertStringContainsString('Energy: 120 kcal for the whole item (GIVEN — echo it back exactly).', $described);

        // A macro the user did not give is still a question, on the same item.
        self::assertStringContainsString('Protein: NOT GIVEN', $described);
    }

    public function test_a_per_100g_item_is_described_in_its_own_unit(): void
    {
        $described = $this->meal([
            $this->item('Rice', basis: 'per_100g', grams: 150.0, values: ['kcal' => 130.0]),
        ])->describe();

        self::assertStringContainsString('Portion: 150 g (GIVEN', $described);
        self::assertStringContainsString('130 kcal per 100 g (GIVEN', $described);
    }

    public function test_the_meal_context_is_carried_into_the_description(): void
    {
        $described = $this->meal([$this->item('Toast')], mealType: 'breakfast', time: '08:15', notes: 'ate half')
            ->describe();

        self::assertStringContainsString('breakfast', $described);
        self::assertStringContainsString('08:15', $described);
        self::assertStringContainsString('ate half', $described);
    }

    // --- complete() --------------------------------------------------------

    public function test_an_item_the_model_returned_is_left_alone(): void
    {
        $meal = $this->meal([$this->item('White rice')]);

        $completed = $meal->complete([$this->proposed('White rice')]);

        self::assertCount(1, $completed);
        self::assertSame('White rice', $completed[0]->name);
    }

    public function test_an_item_the_model_dropped_is_appended(): void
    {
        $meal = $this->meal([$this->item('White rice'), $this->item('Cappuccino', values: ['kcal' => 120.0])]);

        $completed = $meal->complete([$this->proposed('White rice')]);

        self::assertCount(2, $completed);
        self::assertSame('Cappuccino', $completed[1]->name);

        // Zero-width at the 100 g basis: the row an ordinary save would write.
        self::assertSame(100.0, $completed[1]->portionGMin);
        self::assertSame(100.0, $completed[1]->portionGMax);
        self::assertSame(120.0, $completed[1]->kcalMin);
        self::assertSame(120.0, $completed[1]->kcalMax);
        self::assertNull($completed[1]->confidence);
    }

    public function test_the_same_food_listed_twice_is_matched_once_each(): void
    {
        // Two coffees is two items; a model returning two must not duplicate one.
        $meal = $this->meal([$this->item('Coffee'), $this->item('Coffee')]);

        self::assertCount(2, $meal->complete([$this->proposed('Coffee'), $this->proposed('Coffee')]));

        // One returned means one is still missing.
        self::assertCount(2, $meal->complete([$this->proposed('Coffee')]));
    }

    /*
     * THE DECOMPOSITION RULE, deliberately the opposite of what it used to be.
     * It read "an item the model invented is kept", on the reasoning that a
     * split is a proposal like any other. In production that gave a review
     * sheet reading "Confirm 6 items" for a five-part breakfast: the five
     * components the model found, PLUS the user's original one-line description
     * at 100 g and 0 kcal — figures nobody had typed, invented by a NOT NULL
     * column and then shown as though they had.
     *
     * A line with no numbers on it is a description, and the components ARE
     * that line, so it cannot survive next to them.
     */
    public function test_a_described_line_is_replaced_by_its_components(): void
    {
        $meal = $this->meal([$this->item('Chicken and rice')]);

        $completed = $meal->complete([$this->proposed('Chicken'), $this->proposed('Rice')]);

        self::assertCount(2, $completed);
        self::assertSame(['Chicken', 'Rice'], array_map(static fn ($item) => $item->name, $completed));
    }

    public function test_a_described_line_survives_when_nothing_replaced_it(): void
    {
        // The model answered about only one of two blank lines; nothing new
        // appeared, so dropping the other would delete a food the user listed.
        $meal = $this->meal([$this->item('Chicken curry'), $this->item('Sandwich')]);

        $completed = $meal->complete([$this->proposed('Chicken curry')]);

        self::assertCount(2, $completed);
        self::assertSame('Sandwich', $completed[1]->name);
    }

    public function test_a_typed_line_survives_its_own_decomposition(): void
    {
        // A number the user entered is never the model's to replace.
        $meal = $this->meal([
            $this->item('White rice', basis: 'per_100g', grams: 150.0, values: ['kcal' => 130.0]),
            $this->item('Sandwich'),
        ]);

        $completed = $meal->complete([$this->proposed('Two slices of bread'), $this->proposed('Butter')]);

        self::assertCount(3, $completed);
        self::assertSame('White rice', $completed[2]->name);
        self::assertSame(150.0, $completed[2]->portionGMin);
    }

    public function test_a_described_line_is_dropped_when_the_model_renames_it(): void
    {
        // "Basmati rice" is not "rice" by slug, so it is introduced — and a
        // blank line answered under another name is still answered.
        $meal = $this->meal([$this->item('Rice')]);

        $completed = $meal->complete([$this->proposed('Basmati rice')]);

        self::assertCount(1, $completed);
        self::assertSame('Basmati rice', $completed[0]->name);
    }

    // --- preserve() --------------------------------------------------------

    public function test_an_absolute_figure_is_restored_at_both_ends_of_the_portion(): void
    {
        $meal = $this->meal([$this->item('White rice', values: ['kcal' => 210.0])]);

        $rows = $meal->preserve([$this->row('white-rice', portion: [120.0, 200.0], kcal: [500.0, 600.0])]);

        // The stored densities make the DERIVED absolute equal 210 at each end:
        // the inverse of IntakeCalculator's rule.
        self::assertEqualsWithDelta(210.0, 120.0 * $rows[0]['kcal_per_100g_min'] / 100, 0.01);
        self::assertEqualsWithDelta(210.0, 200.0 * $rows[0]['kcal_per_100g_max'] / 100, 0.01);
    }

    public function test_a_density_is_restored_as_a_density(): void
    {
        $meal = $this->meal([$this->item('White rice', basis: 'per_100g', values: ['kcal' => 130.0])]);

        $rows = $meal->preserve([$this->row('white-rice', portion: [120.0, 200.0], kcal: [500.0, 600.0])]);

        self::assertSame(130.0, $rows[0]['kcal_per_100g_min']);
        self::assertSame(130.0, $rows[0]['kcal_per_100g_max']);

        // Portion untouched: the user made no claim about it.
        self::assertSame(120.0, $rows[0]['portion_g_min']);
        self::assertSame(200.0, $rows[0]['portion_g_max']);
    }

    public function test_a_typed_weight_pins_the_portion(): void
    {
        $meal = $this->meal([$this->item('White rice', basis: 'per_100g', grams: 150.0)]);

        $rows = $meal->preserve([$this->row('white-rice', portion: [300.0, 400.0], kcal: [500.0, 600.0])]);

        self::assertSame(150.0, $rows[0]['portion_g_min']);
        self::assertSame(150.0, $rows[0]['portion_g_max']);
    }

    public function test_nothing_typed_means_nothing_restored(): void
    {
        $meal = $this->meal([$this->item('White rice')]);

        $rows = $meal->preserve([$this->row('white-rice', portion: [120.0, 200.0], kcal: [130.0, 130.0])]);

        self::assertSame(130.0, $rows[0]['kcal_per_100g_min']);
        self::assertSame(120.0, $rows[0]['portion_g_min']);

        // The history badge is left as memory set it: this class did not touch it.
        self::assertTrue($rows[0]['memory_adjusted']);
    }

    public function test_restoring_clears_the_from_history_badge(): void
    {
        $meal = $this->meal([$this->item('White rice', values: ['kcal' => 210.0])]);

        $rows = $meal->preserve([$this->row('white-rice', portion: [120.0, 200.0], kcal: [130.0, 130.0])]);

        // These numbers are the user's now; "from history" would be a lie.
        self::assertFalse($rows[0]['memory_adjusted']);
    }

    public function test_a_row_with_no_typed_counterpart_is_untouched(): void
    {
        $meal = $this->meal([$this->item('White rice', values: ['kcal' => 210.0])]);

        $rows = $meal->preserve([$this->row('olive-oil', portion: [5.0, 15.0], kcal: [884.0, 884.0])]);

        self::assertSame(884.0, $rows[0]['kcal_per_100g_min']);
    }

    public function test_a_zero_portion_cannot_produce_a_division_by_zero(): void
    {
        $meal = $this->meal([$this->item('Mystery', values: ['kcal' => 100.0])]);

        $rows = $meal->preserve([$this->row('mystery', portion: [0.0, 0.0], kcal: [0.0, 0.0])]);

        self::assertTrue(is_finite($rows[0]['kcal_per_100g_min']));
        self::assertTrue(is_finite($rows[0]['kcal_per_100g_max']));
    }

    // --- round-trip through the audit column -------------------------------

    public function test_the_payload_survives_a_trip_through_json(): void
    {
        $meal = $this->meal([
            $this->item('Rice', basis: 'per_100g', grams: 150.0, values: ['kcal' => 130.0]),
            $this->item('Chicken curry'),
        ], mealType: 'dinner', time: '19:30', notes: 'leftovers');

        // The round trip `vision_requests.input_payload` makes: a blank that
        // came back as 0 would become a figure the model is told to echo.
        $encoded = json_encode($meal->toArray());
        self::assertIsString($encoded);
        $decoded = TypedMeal::fromArray(json_decode($encoded, true));

        self::assertSame($meal->describe(), $decoded->describe());
        self::assertNull($decoded->items[1]->values['kcal']);
        self::assertSame(130.0, $decoded->items[0]->values['kcal']);
        self::assertSame(150.0, $decoded->items[0]->grams);
        self::assertSame('dinner', $decoded->mealType);
    }

    public function test_has_blanks_is_about_the_calorie_figure(): void
    {
        self::assertTrue($this->meal([$this->item('Curry')])->hasBlanks());
        self::assertFalse($this->meal([$this->item('Curry', values: ['kcal' => 620.0])])->hasBlanks());

        // A macro on its own does not make an item estimated.
        self::assertTrue($this->meal([$this->item('Curry', values: ['protein' => 30.0])])->hasBlanks());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param  list<TypedItem>  $items
     */
    private function meal(array $items, ?string $mealType = null, ?string $time = null, ?string $notes = null): TypedMeal
    {
        return new TypedMeal($items, $mealType, $time, $notes);
    }

    /**
     * @param  array<string, float>  $values
     */
    private function item(string $name, string $basis = 'absolute', ?float $grams = null, array $values = []): TypedItem
    {
        return new TypedItem(
            name: $name,
            basis: $basis,
            grams: $grams,
            values: [
                'kcal'    => $values['kcal'] ?? null,
                'protein' => $values['protein'] ?? null,
                'carbs'   => $values['carbs'] ?? null,
                'fat'     => $values['fat'] ?? null,
            ],
        );
    }

    private function proposed(string $name): ProposedItem
    {
        return new ProposedItem($name, 100, 200, 150, 300, 1, 2, 1, 2, 1, 2, 'medium');
    }

    /**
     * @param  array{0: float, 1: float}  $portion
     * @param  array{0: float, 1: float}  $kcal
     * @return array<string, mixed>
     */
    private function row(string $slug, array $portion, array $kcal): array
    {
        $row = [
            'name'              => $slug,
            'slug'              => $slug,
            'portion_g_min'     => $portion[0],
            'portion_g_max'     => $portion[1],
            'kcal_per_100g_min' => $kcal[0],
            'kcal_per_100g_max' => $kcal[1],
            // Set, so the tests can assert on whether preserve() cleared it.
            'memory_adjusted' => true,
        ];

        foreach (['protein', 'carbs', 'fat'] as $nutrient) {
            $row[$nutrient.'_per_100g_min'] = 1.0;
            $row[$nutrient.'_per_100g_max'] = 2.0;
        }

        return $row;
    }
}
