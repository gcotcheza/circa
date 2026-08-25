<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealStatus;
use Tests\Concerns\ReadsSource;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A RANGE IS TWO NUMBERS IN ORDER, AND EDITING ONE END MUST NOT BREAK THAT.
 *
 * WHAT THE USER SAW. A proposal carried a portion of 2 g. They typed 250 into
 * the MINIMUM box and the sheet read "portion 250 – 2": a band with negative
 * width, drawn as though they had chosen it. Everything downstream has to cope —
 * the day's quadrature, the "whole plate" line, the confirm — and only the
 * server did, refusing in field names ("The items.0.portion_g_max field must be
 * greater than or equal to 250") at the bottom of a sheet they were scrolled
 * into the middle of.
 *
 * TWO LAYERS, ONE RULE. THE SHEET drags the other end along as the number is
 * typed: raise the minimum past the maximum and the maximum follows, drop the
 * maximum below the minimum and the minimum comes down. The last number typed is
 * honoured exactly and the pair is always a range — usually the zero-width one,
 * which is what "it was 250 g" claims. THE SERVER still refuses an inverted
 * band, because a client is not a guarantee: an old build, a replayed offline
 * confirm and anything hand-rolled reach the same endpoint. Only the wording
 * changed — a sentence a person can act on rather than a field name and index.
 *
 * The client half is pinned by source inspection, as QuickTabConversionTest and
 * ConfirmRequestShapeTest pin theirs: no JS test runner, and the rule is one
 * function.
 */
final class RangeNormalizationTest extends TestCase
{
    use ActsAsFreshUser;
    use ReadsSource;
    use RefreshDatabase;

    private const SHEET = 'resources/js/Components/ProposalReview.vue';

    private const DATE = '2026-06-15';

    /** Every edited box goes through the drag, because every box is one end of a pair. */
    public function test_the_sheet_drags_the_other_end_of_the_pair(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertStringContainsString(
            'dragOtherEnd(item, key)',
            $code,
            self::SHEET.': an edit no longer normalises the pair, so one end can be moved past the other.'
        );

        // Either direction: the other end follows to the number just typed.
        self::assertStringContainsString(
            'if (isMin ? value > other : value < other) item[partner] = value',
            $code,
            self::SHEET.': the drag rule has changed. It must move the OTHER end to the value just typed — '
                .'clamping the typed number instead would silently change what the user entered.'
        );

        // A cleared box is "I do not know this end", not a bound to drag to.
        self::assertStringContainsString(
            'if (value === null || other === null || other === undefined) return',
            $code,
            self::SHEET.': a blank end would drag the other end to nothing.'
        );
    }

    /** An inverted portion is refused, and says why in words. */
    public function test_the_server_refuses_a_portion_that_ends_below_its_start(): void
    {
        $meal = $this->proposedMeal();

        $payload = $this->confirmation($meal);
        $payload['items'][0]['portion_g_min'] = 100;
        $payload['items'][0]['portion_g_max'] = 1;

        $response = $this->put('/meals/'.$meal->uuid.'/proposal', $payload);

        $response->assertSessionHasErrors('items.0.portion_g_max');

        self::assertSame(
            'One item has a portion that ends below where it starts. The smaller number goes in the first box.',
            session('errors')->first('items.0.portion_g_max')
        );

        // Nothing written: the meal is where it was.
        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
        self::assertSame(120.0, (float) $meal->items()->sole()->portion_g_min);
    }

    /** The same guard on a nutrient band, in the same words. */
    public function test_the_server_refuses_an_inverted_nutrient_band(): void
    {
        $meal = $this->proposedMeal();

        $payload = $this->confirmation($meal);
        $payload['items'][0]['kcal_min'] = 400;
        $payload['items'][0]['kcal_max'] = 40;

        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)
            ->assertSessionHasErrors('items.0.kcal_max');

        self::assertSame(
            'One item has a calorie range that ends below where it starts. The smaller number goes in the first box.',
            session('errors')->first('items.0.kcal_max')
        );
    }

    /** The drag rule's output — one number said twice — stores as the zero-width claim it is. */
    public function test_the_normalised_pair_is_a_perfectly_ordinary_confirm(): void
    {
        $meal = $this->proposedMeal();

        $payload = $this->confirmation($meal);
        // "It was 250 g": typed into the min box, the max dragged along.
        $payload['items'][0]['portion_g_min'] = 250;
        $payload['items'][0]['portion_g_max'] = 250;

        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertRedirect();

        $item = MealItem::query()->sole();

        self::assertSame(250.0, (float) $item->portion_g_min);
        self::assertSame(250.0, (float) $item->portion_g_max);
    }

    /** A proposed meal with one ranged item on it. */
    private function proposedMeal(): Meal
    {
        $meal = Meal::factory()->create([
            'status'   => MealStatus::Proposed,
            'eaten_at' => self::DATE.' 11:20:00',
        ]);

        MealItem::factory()->for($meal)->create([
            'name'               => 'White rice',
            'portion_g_min'      => 120,
            'portion_g_max'      => 200,
            'portion_full_g_min' => 120,
            'portion_full_g_max' => 200,
            'kcal_per_100g_min'  => 130,
            'kcal_per_100g_max'  => 130,
            'confirmed_at'       => null,
        ]);

        return $meal;
    }

    /**
     * The review sheet's payload for that meal.
     *
     * @return array<string, mixed>
     */
    private function confirmation(Meal $meal): array
    {
        $items = $meal->items()->orderBy('id')->get()->map(static function (MealItem $item): array {
            $absolute = static fn (string $nutrient, string $end): float => round(
                (float) $item->{'portion_g_'.$end} * (float) $item->{$nutrient.'_per_100g_'.$end} / 100,
                3
            );

            return [
                'name'          => $item->name,
                'portion_g_min' => (float) $item->portion_g_min,
                'portion_g_max' => (float) $item->portion_g_max,
                'kcal_min'      => $absolute('kcal', 'min'),
                'kcal_max'      => $absolute('kcal', 'max'),
                'protein_g_min' => $absolute('protein', 'min'),
                'protein_g_max' => $absolute('protein', 'max'),
                'carbs_g_min'   => $absolute('carbs', 'min'),
                'carbs_g_max'   => $absolute('carbs', 'max'),
                'fat_g_min'     => $absolute('fat', 'min'),
                'fat_g_max'     => $absolute('fat', 'max'),
            ];
        })->all();

        return [
            'date'      => self::DATE,
            'time'      => '11:20',
            'meal_type' => 'lunch',
            'notes'     => null,
            'items'     => $items,
        ];
    }
}
