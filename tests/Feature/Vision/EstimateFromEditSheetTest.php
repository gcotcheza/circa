<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Save & estimate", from the edit sheet, on a meal that already exists.
 *
 * The client half of the bug is in MealSheet.vue; what is pinned here is the
 * half the client is entitled to rely on, which decides whether closing the
 * sheet is even correct: the save leaves a CONFIRMED meal confirmed with the
 * new blank on it; the estimate is accepted with 202, not a 409 the sheet would
 * have to stay open to explain; the meal is `analyzing` the moment it answers,
 * so the day's card spins and Day.vue's poller picks it up; and the poll
 * endpoint says so too, because that is what the day view reads.
 *
 * MANUAL STEP, DELIBERATELY NOT ASSERTED HERE: that the sheet CLOSES and the
 * review sheet opens is Vue state in a component this suite does not mount. The
 * phone test is in the PR — add a blank line to yesterday's dinner, tap Save &
 * estimate, and you should be on the day view with the card spinning.
 *
 * See docs/rationale-frontend.md § "Save & estimate left the user in the sheet"
 */
final class EstimateFromEditSheetTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_adding_a_blank_line_to_a_confirmed_photo_meal_and_estimating_it_starts_an_analysis(): void
    {
        Queue::fake();

        $meal = $this->yesterdaysDinner();

        // 1. The save: the sheet posts every line it is showing, the two off
        //    the photographs plus the new blank one.
        $this->putJson('/meals/'.$meal->uuid, [
            'uuid'      => $meal->uuid,
            'date'      => '2026-08-07',
            'time'      => '19:30',
            'meal_type' => 'dinner',
            'notes'     => null,
            'items'     => [
                ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 160, 'kcal_per_100g' => 130],
                ['name' => 'Grilled chicken breast', 'basis' => 'per_100g', 'grams' => 120, 'kcal_per_100g' => 165],
                // The new one: a name and nothing else, which step 9 made legal.
                ['name' => '2 bottles of alcohol-free beer', 'basis' => 'absolute'],
            ],
        ])->assertRedirect();

        $meal->refresh();

        // A meal the user already agreed to stays agreed to: `analyzing` here
        // would drop the dinner from the day's totals while the beer is judged.
        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame(3, $meal->items()->count());

        // 2. The estimate, which used to do nothing useful: it has to be
        //    ACCEPTED, because the sheet closes on the strength of it.
        $response = $this->postJson('/api/meals/'.$meal->uuid.'/estimate', [
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(202)->assertJsonPath('meal.status', 'analyzing');

        // 3. Somewhere to go, and something to watch when you get there.
        self::assertSame(MealStatus::Analyzing, $meal->refresh()->status);

        Queue::assertPushed(EstimateMealNutrition::class, 1);

        $request = VisionRequest::query()->latest('id')->firstOrFail();

        self::assertSame('text', $request->request_kind->value);
        self::assertNotNull($request->input_payload);

        // 4. The poll the day view runs says the same, which is what makes the
        //    card spin rather than look finished.
        $this->getJson('/api/meals/'.$meal->uuid.'/vision')
            ->assertOk()
            ->assertJsonPath('meal.status', 'analyzing');
    }

    /**
     * The blank is what makes the offer legal, and it is refused without one:
     * the 409 the sheet has to explain, and therefore the one case where staying
     * open IS right. Closing unconditionally would hide it.
     */
    public function test_a_meal_with_no_blanks_is_refused_rather_than_estimated(): void
    {
        Queue::fake();

        $meal = Meal::factory()->create([
            'status' => MealStatus::Confirmed,
            'source' => MealSource::Manual,
        ]);

        MealItem::factory()->for($meal)->create([
            'name'              => 'Porridge',
            'portion_g_min'     => 100, 'portion_g_max' => 100,
            'kcal_per_100g_min' => 68, 'kcal_per_100g_max' => 68,
            'confirmed_at'      => now(),
        ]);

        $this->postJson('/api/meals/'.$meal->uuid.'/estimate', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('status', 'conflict');

        // Nothing was bought. (`assertNothingPushed` would be wrong: the item
        // write queues the day's rollup, which is correct.)
        Queue::assertNotPushed(EstimateMealNutrition::class);
    }

    /** Yesterday's dinner: confirmed, two plates, items off both of them. */
    private function yesterdaysDinner(): Meal
    {
        $meal = Meal::factory()->create([
            'status'   => MealStatus::Confirmed,
            'source'   => MealSource::Photo,
            'eaten_at' => '2026-08-07 17:30:00',
        ]);

        $first = MealPhoto::factory()->for($meal)->create(['position' => 0]);
        $second = MealPhoto::factory()->for($meal)->create(['position' => 1]);

        MealItem::factory()->for($meal)->create([
            'name'              => 'White rice',
            'meal_photo_id'     => $first->id,
            'portion_g_min'     => 120, 'portion_g_max' => 200,
            'kcal_per_100g_min' => 128, 'kcal_per_100g_max' => 132,
            'confirmed_at'      => now(),
        ]);

        MealItem::factory()->for($meal)->create([
            'name'              => 'Grilled chicken breast',
            'meal_photo_id'     => $second->id,
            'portion_g_min'     => 100, 'portion_g_max' => 150,
            'kcal_per_100g_min' => 163, 'kcal_per_100g_max' => 167,
            'confirmed_at'      => now(),
        ]);

        return $meal;
    }
}
