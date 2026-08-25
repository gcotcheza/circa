<?php

declare(strict_types=1);

namespace Tests\Unit\Vision;

use Tests\TestCase;
use App\Http\Requests\MealRequest;
use App\Services\Vision\ProposedItem;
use App\Services\Vision\ProposedItemMapper;

/**
 * Absolutes -> densities: a mistake here is invisible, because the numbers still
 * look like numbers. The property bought is a ROUND TRIP — a stored proposal,
 * read back through IntakeCalculator's rule, gives the band the review screen
 * showed, or a user confirming it agrees to one thing and logs another.
 */
final class ProposedItemMapperTest extends TestCase
{
    private ProposedItemMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ProposedItemMapper;
    }

    public function test_a_consistent_item_round_trips_exactly(): void
    {
        // Prompt contract: kcal_min is the value AT portion_g_min.
        $row = $this->mapper->toRow($this->item(portionMin: 120, portionMax: 200, kcalMin: 156, kcalMax: 260));

        self::assertSame(130.0, $row['kcal_per_100g_min']);
        self::assertSame(130.0, $row['kcal_per_100g_max']);

        // IntakeCalculator's rule, applied by hand.
        self::assertSame(156.0, round($row['portion_g_min'] * $row['kcal_per_100g_min'] / 100, 3));
        self::assertSame(260.0, round($row['portion_g_max'] * $row['kcal_per_100g_max'] / 100, 3));
    }

    public function test_a_genuinely_uncertain_density_widens_rather_than_collapses(): void
    {
        // Plain rice or fried rice: one portion range, two energies, two densities.
        $row = $this->mapper->toRow($this->item(portionMin: 150, portionMax: 150, kcalMin: 195, kcalMax: 300));

        self::assertSame(130.0, $row['kcal_per_100g_min']);
        self::assertSame(200.0, $row['kcal_per_100g_max']);
    }

    public function test_a_self_contradictory_answer_widens_instead_of_inverting(): void
    {
        // Dividing the ends naively gives min 150 and max 80: an inverted band
        // makes the item run backwards the first time somebody edits a portion.
        $row = $this->mapper->toRow($this->item(portionMin: 100, portionMax: 200, kcalMin: 150, kcalMax: 160));

        self::assertLessThanOrEqual($row['kcal_per_100g_max'], $row['kcal_per_100g_min']);
        self::assertSame(80.0, $row['kcal_per_100g_min']);
        self::assertSame(150.0, $row['kcal_per_100g_max']);
    }

    public function test_an_impossible_density_is_clamped_to_the_same_ceiling_the_barcode_path_uses(): void
    {
        // 2400 kcal/100 g where pure fat is 900: impossible, and one tap from Confirm.
        $row = $this->mapper->toRow($this->item(portionMin: 50, portionMax: 50, kcalMin: 1200, kcalMax: 1200));

        self::assertSame((float) config('health.food.max_kcal_per_100g'), $row['kcal_per_100g_max']);
    }

    /*
     * The portion ceiling used to be a private const in the mapper, under a
     * comment promising it matched `items.*.grams` — a promise nothing
     * enforced, and the kind that outlives the fact. Both ends now read one
     * setting, so these assert that they MOVE TOGETHER rather than that
     * either equals 10000 today.
     */
    public function test_a_portion_is_clamped_to_the_configured_ceiling(): void
    {
        config(['health.food.max_portion_g' => 250]);

        $row = $this->mapper->toRow($this->item(portionMin: 900, portionMax: 4000));

        self::assertSame(250.0, $row['portion_g_min']);
        self::assertSame(250.0, $row['portion_g_max']);
    }

    public function test_the_mapper_and_the_meal_form_read_one_portion_setting(): void
    {
        config(['health.food.max_portion_g' => 1234]);

        $row = $this->mapper->toRow($this->item(portionMin: 9999, portionMax: 9999));
        $rule = $this->asArray(MealRequest::create('/', 'POST')->rules()['items.*.grams']);

        self::assertSame(1234.0, $row['portion_g_max']);
        self::assertContains('max:1234', $rule);
    }

    public function test_a_zero_portion_does_not_divide_by_zero(): void
    {
        $row = $this->mapper->toRow($this->item(portionMin: 0, portionMax: 0, kcalMin: 10, kcalMax: 10));

        self::assertSame(1.0, $row['portion_g_min']);
        self::assertTrue(is_finite($row['kcal_per_100g_min']));
    }

    public function test_confidence_words_become_the_numeric_column(): void
    {
        foreach (['low' => 0.3, 'medium' => 0.6, 'high' => 0.9] as $word => $expected) {
            $row = $this->mapper->toRow($this->item(confidence: $word));

            self::assertSame($expected, $row['confidence'], $word);
        }

        // Null rather than a guess: "no opinion" is a real answer.
        self::assertNull($this->mapper->toRow($this->item(confidence: null))['confidence']);
        self::assertNull($this->mapper->toRow($this->item(confidence: 'certain'))['confidence']);
    }

    public function test_the_name_is_slugged_for_the_meal_memory_fingerprint(): void
    {
        $row = $this->mapper->toRow(new ProposedItem(
            name: 'Grilled Chicken Breast',
            portionGMin: 100, portionGMax: 100,
            kcalMin: 165, kcalMax: 165,
            proteinGMin: 31, proteinGMax: 31,
            carbsGMin: 0, carbsGMax: 0,
            fatGMin: 3.6, fatGMax: 3.6,
        ));

        self::assertSame('grilled-chicken-breast', $row['slug']);
    }

    private function item(
        float $portionMin = 100,
        float $portionMax = 100,
        float $kcalMin = 130,
        float $kcalMax = 130,
        ?string $confidence = 'medium',
    ): ProposedItem {
        return new ProposedItem(
            name: 'White rice',
            portionGMin: $portionMin,
            portionGMax: $portionMax,
            kcalMin: $kcalMin,
            kcalMax: $kcalMax,
            proteinGMin: 2.7, proteinGMax: 2.7,
            carbsGMin: 28, carbsGMax: 28,
            fatGMin: 0.3, fatGMax: 0.3,
            confidence: $confidence,
        );
    }
}
