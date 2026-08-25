<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A meal is a series of plates, and each one is its own answer.
 *
 * `meals.status` is the MEAL's commitment — `draft -> analyzing -> proposed ->
 * confirmed | failed` — with one new rule: it never goes backwards out of
 * `confirmed`. The daily rollup selects on `Meal::scopeConfirmed()`, so a
 * dessert arriving must not take the main course out of the day's totals.
 *
 * Each PLATE has its own state from its own `vision_requests` row —
 * `analyzing`, `failed`, `proposed`, `settled` — which is what the review sheet
 * reads and the poller watches, and it is the half that can be outstanding on
 * an otherwise finished meal.
 *
 * Together they defend one sentence: CONFIRMING A PLATE APPENDS. Nothing a
 * human agreed to, typed or scanned is ever replaced by an answer about a
 * different photograph.
 */
final class MultiPlateMealTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    /**
     * The whole feature, end to end. The second confirm is the one that matters:
     * before this change it replaced the meal's items wholesale, and the rice
     * vanished from a dinner the user had already logged.
     */
    public function test_confirming_a_second_plate_appends_to_a_meal_already_confirmed(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $first = $this->upload($uuid);

        $this->confirm($uuid, $first, [$this->line('White rice', 150, 195)]);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame(['White rice'], $meal->confirmedItems()->pluck('name')->all());

        // Later: pudding.
        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);
        $second = $this->upload($uuid);

        // The main course stays confirmed and counted while plate two is read.
        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);
        self::assertSame(['White rice'], $meal->confirmedItems()->pluck('name')->all());

        $this->confirm($uuid, $second, [$this->line('Broccoli', 34, 51)]);

        // Both, appended in plate order.
        self::assertSame(
            ['White rice', 'Broccoli'],
            $meal->refresh()->confirmedItems()->orderBy('id')->pluck('name')->all(),
        );

        // Each line still knows which photograph it came off.
        self::assertSame($first->id, $meal->items()->where('name', 'White rice')->firstOrFail()->meal_photo_id);
        self::assertSame($second->id, $meal->items()->where('name', 'Broccoli')->firstOrFail()->meal_photo_id);
    }

    /**
     * A barcode scan or a typed line has a null `meal_photo_id`, which holds it
     * outside every photo scope — otherwise "the meal's unconfirmed items" would
     * sweep it up when a plate is proposed, re-analysed or removed.
     */
    public function test_a_typed_item_survives_a_photo_being_added_and_confirmed(): void
    {
        $uuid = (string) Str::uuid();

        $meal = Meal::factory()->create(['uuid' => $uuid, 'status' => MealStatus::Confirmed]);

        $typed = $meal->items()->create([
            ...$this->row('Sourdough toast'),
            'confirmed_at' => now(),
        ]);

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $photo = $this->upload($uuid);

        $this->confirm($uuid, $photo, [$this->line('White rice', 150, 195)]);

        $names = $meal->refresh()->confirmedItems()->pluck('name')->all();

        // `Meal::items()` orders by the PLATE's position first, so a typed line
        // sorts last however early its row was written: the pictures are the
        // meal, the typed lines are the additions.
        self::assertSame(['White rice', 'Sourdough toast'], $names);
        self::assertNull($meal->items()->find($typed->id)?->meal_photo_id);
    }

    /**
     * The double-count guard: the model is told what is already logged. With the
     * salad still in shot on the second plate it would otherwise be listed
     * again, and Confirm appends — so the day gains a salad nobody ate.
     */
    public function test_the_second_plate_is_told_what_is_already_on_the_meal(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $first = $this->upload($uuid);

        // An empty meal gets no block at all, not "already logged: (nothing)".
        self::assertSame([], $this->analyzer->context[0]);

        $this->confirm($uuid, $first, [$this->line('White rice', 150, 195)]);

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);
        $this->upload($uuid);

        self::assertSame(['White rice'], $this->analyzer->lastContext());
    }

    /**
     * A plate is never told about its own outstanding proposal: handed its own
     * last guess as though a human had agreed to it, the model would avoid
     * re-listing the very food it is being asked to look at.
     */
    public function test_a_re_analysis_is_not_told_about_its_own_previous_answer(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $photo = $this->upload($uuid);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        // A typed line the user added by hand, which IS context.
        $meal->items()->create([...$this->row('Sourdough toast'), 'confirmed_at' => now()]);

        $this->postJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        self::assertSame(['Sourdough toast'], $this->analyzer->lastContext());
    }

    /** Removing a plate takes its proposal with it and leaves the rest alone. */
    public function test_removing_a_plate_removes_its_unconfirmed_items_only(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $first = $this->upload($uuid);

        $this->confirm($uuid, $first, [$this->line('White rice', 150, 195)]);

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);
        $second = $this->upload($uuid);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(2, $meal->items()->count());

        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$second->client_id)->assertOk();

        $meal->refresh();

        // The dessert's proposal went with its photograph; the main course did not.
        self::assertSame(['White rice'], $meal->items()->pluck('name')->all());
        self::assertSame(1, $meal->photos()->count());
        self::assertSame(MealStatus::Confirmed, $meal->status);

        Storage::disk('meal-photos')->assertMissing($second->path);
        Storage::disk('meal-photos')->assertMissing($second->thumb_path);
        Storage::disk('meal-photos')->assertExists($first->refresh()->path);
    }

    /**
     * A CONFIRMED item outlives its photograph unless asked: deleting the
     * picture is a statement about the picture, and whether the food goes with
     * it is the user's call. The foreign key nulls the provenance, leaving a
     * claim with no evidence — the typed line it now is.
     */
    public function test_removing_a_plate_keeps_its_confirmed_items_unless_asked(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $photo = $this->upload($uuid);

        $this->confirm($uuid, $photo, [$this->line('White rice', 150, 195)]);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id)->assertOk();

        $item = $meal->refresh()->items()->sole();

        self::assertSame('White rice', (string) $item->name);
        self::assertNull($item->meal_photo_id);
        self::assertSame(0, $meal->photos()->count());
    }

    public function test_removing_a_plate_can_take_its_confirmed_items_too(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $photo = $this->upload($uuid);

        $this->confirm($uuid, $photo, [$this->line('White rice', 150, 195)]);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id, ['remove_items' => true])->assertOk();

        self::assertSame(0, $meal->refresh()->items()->count());
    }

    /**
     * Removing the last plate of an uncommitted meal discards the meal: an empty
     * draft leaves a card that cannot be opened, cannot be confirmed, and does
     * not count.
     */
    public function test_removing_the_only_plate_of_an_uncommitted_meal_discards_the_meal(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willFind('an empty table');
        $photo = $this->upload($uuid);

        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id)->assertOk();

        self::assertSame(0, Meal::query()->where('uuid', $uuid)->count());
    }

    /** Positions close up, so "photo 2" keeps meaning the second one. */
    public function test_removing_a_middle_plate_renumbers_the_rest(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $first = $this->upload($uuid);
        $second = $this->upload($uuid);
        $third = $this->upload($uuid);

        self::assertSame([0, 1, 2], [$first->position, $second->position, $third->position]);

        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$second->client_id)->assertOk();

        self::assertSame([0, 1], Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->pluck('position')->all());
        self::assertSame(1, $third->refresh()->position);
    }

    /**
     * The rollup counts CONFIRMED items of CONFIRMED meals, and both halves
     * matter now: the meal filter alone would have counted the dessert the
     * moment the model answered, thirty seconds before anybody agreed to it.
     */
    public function test_an_outstanding_plate_does_not_move_the_days_total(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $first = $this->upload($uuid);

        $this->confirm($uuid, $first, [$this->line('White rice', 150, 195)]);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $date = $meal->eaten_at->setTimezone((string) config('health.timezone'))->toDateString();

        $before = $this->get('/?date='.$date)->viewData('page')['props']['summary']['kcalIn']['mid'];

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);
        $this->upload($uuid);

        $after = $this->get('/?date='.$date)->viewData('page')['props']['summary']['kcalIn']['mid'];

        self::assertNotNull($before);
        self::assertSame($before, $after);
    }

    /**
     * The day view carries each plate's own state and items, which is what lets
     * one review sheet post back exactly the lines off one photograph.
     */
    public function test_the_day_view_exposes_each_plate_with_its_own_items(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $first = $this->upload($uuid);

        $this->confirm($uuid, $first, [$this->line('White rice', 150, 195)]);

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);
        $this->upload($uuid);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $date = $meal->eaten_at->setTimezone((string) config('health.timezone'))->toDateString();

        $this->get('/?date='.$date)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meals.0.status', 'confirmed')
                ->has('meals.0.photos', 2)
                ->where('meals.0.photos.0.state', 'settled')
                ->where('meals.0.photos.0.items.0.name', 'White rice')
                ->where('meals.0.photos.1.state', 'proposed')
                ->where('meals.0.photos.1.items.0.name', 'Broccoli')
                ->etc()
            );
    }

    /**
     * A confirm naming a plate not on this meal is refused: falling back to some
     * other scope would write the user's edits onto items they never saw.
     */
    public function test_a_confirm_for_a_foreign_plate_is_refused(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        $this->upload($uuid);

        $stranger = MealPhoto::factory()->create();

        $this->put('/meals/'.$uuid.'/proposal', [
            'date'      => now()->toDateString(),
            'time'      => '12:30',
            'client_id' => $stranger->client_id,
            'items'     => [$this->line('White rice', 150, 195)],
        ])->assertSessionHas('error');
    }

    /**
     * A photo of a chair is a SUCCESS with nothing in it, and still the user's
     * move. Reading "settled" as "has no unconfirmed items" would settle an
     * empty answer on arrival, dropping it off the review sheet and out of the
     * poller's `pending` count, leaving a photograph nothing on screen ever asks
     * about again. This is the live row that found it.
     */
    public function test_an_empty_answer_stays_outstanding_until_the_user_acts(): void
    {
        $uuid = (string) Str::uuid();

        $this->analyzer->willFind('a bar chart');
        $photo = $this->upload($uuid);

        self::assertSame('proposed', $photo->refresh()->entryState());
        self::assertTrue($photo->isPending());

        // And it says so where the front end reads it.
        $this->getJson('/api/meals/'.$uuid.'/vision')
            ->assertOk()
            ->assertJsonPath('meal.pending', 1)
            ->assertJsonPath('meal.photos.0.state', 'proposed');

        // Removing the photograph is the way out, and takes the uncommitted meal.
        $this->deleteJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id)->assertOk();

        self::assertSame(0, Meal::query()->where('uuid', $uuid)->count());
    }

    /**
     * Upload one plate, analysed inline, and hand back its row. No Queue::fake —
     * the sync connection runs the job immediately, which is what makes these
     * tests about the whole path rather than about what was pushed.
     */
    private function upload(string $uuid): MealPhoto
    {
        $clientId = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'client_id'       => $clientId,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => now()->toDateString(),
            'time'            => '12:30',
            'photo'           => UploadedFile::fake()->createWithContent('plate.jpg', TestImage::jpeg(1024, 768)),
        ])->assertStatus(202);

        return MealPhoto::query()->where('client_id', $clientId)->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function confirm(string $uuid, MealPhoto $photo, array $items): void
    {
        $this->put('/meals/'.$uuid.'/proposal', [
            'date'      => now()->toDateString(),
            'time'      => '12:30',
            'client_id' => $photo->client_id,
            'items'     => $items,
        ])->assertSessionHasNoErrors();
    }

    /**
     * The review screen's shape: absolutes, in pairs.
     *
     * @return array<string, mixed>
     */
    private function line(string $name, float $kcalMin, float $kcalMax): array
    {
        return [
            'name'          => $name,
            'portion_g_min' => 120, 'portion_g_max' => 200,
            'kcal_min'      => $kcalMin, 'kcal_max' => $kcalMax,
            'protein_g_min' => 3, 'protein_g_max' => 5,
            'carbs_g_min'   => 30, 'carbs_g_max' => 50,
            'fat_g_min'     => 0.3, 'fat_g_max' => 0.6,
            'confidence'    => 'high',
        ];
    }

    /**
     * A stored item, in the density shape the table holds.
     *
     * @return array{
     *     name: string, slug: string, portion_g_min: int, portion_g_max: int,
     *     kcal_per_100g_min: int, kcal_per_100g_max: int, protein_per_100g_min: int,
     *     protein_per_100g_max: int, carbs_per_100g_min: int, carbs_per_100g_max: int,
     *     fat_per_100g_min: int, fat_per_100g_max: int,
     * }
     */
    private function row(string $name): array
    {
        return [
            'name'                 => $name,
            'slug'                 => Str::slug($name),
            'portion_g_min'        => 60, 'portion_g_max' => 60,
            'kcal_per_100g_min'    => 250, 'kcal_per_100g_max' => 250,
            'protein_per_100g_min' => 9, 'protein_per_100g_max' => 9,
            'carbs_per_100g_min'   => 48, 'carbs_per_100g_max' => 48,
            'fat_per_100g_min'     => 3, 'fat_per_100g_max' => 3,
        ];
    }
}
