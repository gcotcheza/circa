<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use App\Models\DailySummary;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use Illuminate\Http\Response;
use App\Jobs\AnalyzeMealPhoto;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "I ate half of this — it was a shared plate."
 *
 * The model estimates what is ON the plate — the only thing a photograph can
 * settle — and the app applies the consumption share afterwards. It is never
 * asked how much was eaten, because it cannot know: a guess would put an
 * invented number exactly where a tap belongs.
 *
 * Before this, the user typed "I only ate half of this plate, it was a shared
 * plate" into the meal's notes. Notes are inert; the day counted the whole
 * dinner.
 */
final class SharedPlateTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const DATE = '2026-08-07';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    // --- The chip on the review sheet ---

    /**
     * The plate's chip halves every line that follows it. Densities are
     * asserted UNCHANGED: scaling them too would quarter the calories, and
     * every number on screen would still look plausible.
     */
    public function test_the_plate_chip_scales_every_item_it_covers(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
            $this->item('Grilled chicken breast', 100, 150, 165, 247.5, share: 0.5),
        ])->assertStatus(303);

        $rice = $meal->items()->where('name', 'White rice')->sole();

        // Eaten.
        self::assertSame('60.000', $rice->portion_g_min);
        self::assertSame('100.000', $rice->portion_g_max);

        // On the plate, kept.
        self::assertSame('120.000', $rice->portion_full_g_min);
        self::assertSame('200.000', $rice->portion_full_g_max);
        self::assertSame('0.5000', $rice->share_fraction);

        // Per 100 g is a fact about rice, not about how much was eaten.
        self::assertSame('130.000', $rice->kcal_per_100g_min);
        self::assertSame('130.000', $rice->kcal_per_100g_max);

        // The absolutes halve through the existing derivation, not a copy of it.
        self::assertEqualsWithDelta(78.0, $rice->kcalRange()['min'], 0.01);
        self::assertEqualsWithDelta(130.0, $rice->kcalRange()['max'], 0.01);

        // The plate remembers, so re-analysis can re-apply.
        self::assertSame('0.5000', $meal->photos()->sole()->share_fraction);
    }

    /**
     * "We shared the rolls, but the pho was all mine." The per-item override is
     * why the chip does not live on the plate and get multiplied in at read
     * time: two lines off one photograph can honestly differ.
     */
    public function test_one_item_can_opt_out_of_the_plates_share(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
            $this->item('Grilled chicken breast', 100, 150, 165, 247.5, share: 1.0),
        ])->assertStatus(303);

        self::assertSame('60.000', $meal->items()->where('name', 'White rice')->sole()->portion_g_min);

        $chicken = $meal->items()->where('name', 'Grilled chicken breast')->sole();

        self::assertSame('100.000', $chicken->portion_g_min);
        self::assertSame('1.0000', $chicken->share_fraction);

        // The PLATE is still ½ — the chip's state, and what re-analysis re-applies.
        self::assertSame('0.5000', $meal->photos()->sole()->share_fraction);
    }

    /**
     * The chips come back where the user left them: otherwise a reopened shared
     * meal shows mysteriously small portions with no explanation, which is why
     * the fraction is stored, quite separately from the arithmetic.
     */
    public function test_a_confirmed_share_is_readable_again_from_the_day_props(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
            $this->item('Grilled chicken breast', 100, 150, 165, 247.5, share: 1.0),
        ])->assertStatus(303);

        $props = $this->dayProps();

        $photo = $props['meals'][0]['photos'][0];

        self::assertSame(0.5, $photo['shareFraction']);

        $items = $props['meals'][0]['items'];
        self::assertIsArray($items);

        $rice = collect($items)->firstWhere('name', 'White rice');

        self::assertSame(0.5, $rice['shareFraction']);

        // Eaten, for every screen that shows a number...
        self::assertSame(60.0, $rice['portionGMin']);
        self::assertEqualsWithDelta(78.0, $rice['kcal']['min'], 0.5);

        // ...and the plate, for the two sheets that can change it again.
        self::assertSame(120.0, $rice['portionFullGMin']);
        self::assertEqualsWithDelta(156.0, $rice['kcalFull']['min'], 0.5);

        // The override is derived, never a flag: item share differs from plate.
        $chicken = collect($items)->firstWhere('name', 'Grilled chicken breast');

        self::assertSame(1.0, $chicken['shareFraction']);
    }

    /**
     * ½, then ⅓, then All through HTTP: the client re-posts the PLATE each time
     * and only the fraction moves, so the estimate is never divided or rounded.
     */
    public function test_changing_the_share_repeatedly_returns_the_original_estimate(): void
    {
        $meal = $this->proposedPlate();

        foreach ([0.5, 1 / 3, 0.25, 1.0] as $share) {
            $this->confirm($meal, share: $share, items: [
                $this->item('White rice', 120, 200, 156, 260, share: $share),
                $this->item('Grilled chicken breast', 100, 150, 165, 247.5, share: $share),
            ])->assertStatus(303);
        }

        $rice = $meal->items()->where('name', 'White rice')->sole();

        self::assertSame('120.000', $rice->portion_g_min);
        self::assertSame('200.000', $rice->portion_g_max);
        self::assertSame('1.0000', $rice->share_fraction);
        self::assertEqualsWithDelta(156.0, $rice->kcalRange()['min'], 0.01);
    }

    // --- The day's totals ---

    /**
     * The rollup needs no new code: `portion_g_*` is the EATEN portion, already
     * read by IntakeCalculator, the daily summary, the meal card and the trends
     * page. Storing the plate there means five readers must multiply; one won't.
     */
    public function test_the_days_total_counts_the_share_and_not_the_plate(): void
    {
        $whole = $this->proposedPlate();

        $this->confirm($whole, share: 1.0, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 1.0),
        ])->assertStatus(303);

        $full = (float) DailySummary::query()->findOrFail(self::DATE)->kcal_in_mid;

        $shared = $this->proposedPlate();

        $this->confirm($shared, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
        ])->assertStatus(303);

        $both = (float) DailySummary::query()->findOrFail(self::DATE)->kcal_in_mid;

        // The second plate added half of what the first did, to the calorie.
        self::assertEqualsWithDelta($full * 1.5, $both, 0.5);
    }

    // --- Re-analysis and reprojection ---

    /**
     * A RE-ANALYSED SHARED PLATE STAYS SHARED. The model answers about the
     * whole bowl every time and is never told the share, so the app re-applies
     * it; otherwise "have another look at this" silently doubles a dinner.
     */
    public function test_re_analysing_a_shared_plate_re_applies_the_share(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
        ])->assertStatus(303);

        $photo = $meal->photos()->sole();

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);

        $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        $broccoli = $meal->items()->where('name', 'Broccoli')->sole();

        // The model said 100–150 g of broccoli; the user is still splitting it.
        self::assertSame('50.000', $broccoli->portion_g_min);
        self::assertSame('75.000', $broccoli->portion_g_max);
        self::assertSame('100.000', $broccoli->portion_full_g_min);
        self::assertSame('0.5000', $broccoli->share_fraction);
    }

    /**
     * The chips can change the plate WITHOUT a confirm in between: "I ate half
     * of this, and by the way the model got the noodles wrong" is two taps and
     * no Confirm, so the request carries the share itself.
     */
    public function test_the_re_analysis_request_can_set_the_share_itself(): void
    {
        [$meal, $photo] = $this->analysedPlate();

        self::assertSame('1.0000', $photo->share_fraction);

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);

        $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => (string) Str::uuid(),
            'share_fraction'  => 0.25,
        ])->assertStatus(202);

        self::assertSame('0.2500', $photo->refresh()->share_fraction);
        self::assertSame('25.000', $meal->items()->where('name', 'Broccoli')->sole()->portion_g_min);
    }

    /**
     * A re-analysis that says nothing about the share leaves it alone: an
     * absent field means "the sheet did not mention it", never "reset to All".
     */
    public function test_a_silent_re_analysis_does_not_reset_the_share(): void
    {
        [$meal, $photo] = $this->analysedPlate();

        $photo->forceFill(['share_fraction' => 0.5])->saveQuietly();

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);

        $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        self::assertSame('0.5000', $photo->refresh()->share_fraction);
    }

    /**
     * `vision:reproject` honours the shares it finds: it rebuilds from the
     * model's stored answer, which never knew about the share, so ignoring it
     * would restore the whole plate months later on a meal the user confirmed
     * and forgot — no more the command's to discard than `confirmed_at`. It is
     * also the ONE place a per-item override survives a rebuild, being the one
     * operation that can honestly match a rebuilt row to a stored one.
     */
    public function test_reproject_keeps_the_share_including_a_per_item_override(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
            $this->item('Grilled chicken breast', 100, 150, 165, 247.5, share: 1.0),
        ])->assertStatus(303);

        // The model's own answer on the audit row: the whole plate, unshared.
        $this->storeAnswer($meal, [
            ['name' => 'White rice', 'portion_g_min' => 120, 'portion_g_max' => 200, 'kcal_min' => 156, 'kcal_max' => 260],
            ['name' => 'Grilled chicken breast', 'portion_g_min' => 100, 'portion_g_max' => 150, 'kcal_min' => 165, 'kcal_max' => 247.5],
        ]);

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid, '--apply' => true])
            ->assertSuccessful();

        $rice = $meal->items()->where('name', 'White rice')->sole();
        $chicken = $meal->items()->where('name', 'Grilled chicken breast')->sole();

        self::assertSame('0.5000', $rice->share_fraction);
        self::assertSame('60.000', $rice->portion_g_min);
        self::assertSame('120.000', $rice->portion_full_g_min);

        // The override, carried: a repair does not get to reopen it.
        self::assertSame('1.0000', $chicken->share_fraction);
        self::assertSame('100.000', $chicken->portion_g_min);

        // A no-op repair is not refused, as it would be if the diff compared
        // scaled to unscaled.
        self::assertNotNull($rice->confirmed_at);
    }

    // --- The typed and scanned paths ---

    /**
     * "I ate half the chocolate bar." The share has to reach typed and scanned
     * items too: a barcode describes the whole bar, not how much was eaten. In
     * quick mode the 100 g BASIS scales — half of a 300 kcal cappuccino is 150.
     */
    public function test_a_typed_item_can_be_fractioned_after_the_fact(): void
    {
        $this->post('/meals', [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '15:30',
            'items' => [[
                'name'           => 'Chocolate bar',
                'basis'          => 'per_100g',
                'grams'          => 100,
                'kcal_per_100g'  => 545,
                'share_fraction' => 0.5,
            ], [
                'name'           => 'Cappuccino',
                'basis'          => 'absolute',
                'kcal'           => 300,
                'share_fraction' => 0.5,
            ]],
        ])->assertRedirect();

        $bar = Meal::query()->firstOrFail()->items()->where('name', 'Chocolate bar')->sole();

        self::assertSame('50.000', $bar->portion_g_min);
        self::assertSame('100.000', $bar->portion_full_g_min);
        self::assertSame('545.000', $bar->kcal_per_100g_min);
        self::assertEqualsWithDelta(272.5, $bar->kcalRange()['max'], 0.01);

        // Quick mode: the 100 g basis halves, so the energy does.
        $coffee = Meal::query()->firstOrFail()->items()->where('name', 'Cappuccino')->sole();

        self::assertSame('50.000', $coffee->portion_g_min);
        self::assertSame('100.000', $coffee->portion_full_g_min);
        self::assertEqualsWithDelta(150.0, $coffee->kcalRange()['max'], 0.01);
    }

    /**
     * Editing a saved meal without touching the chips changes nothing. The edit
     * sheet REPLACES a meal's items on every save, so anything not round-tripped
     * through the props is dropped the first time the user fixes a typo — how
     * `food_product_id` was nearly lost in step 4, except that losing the share
     * doubles a dinner rather than dropping a link.
     */
    public function test_editing_a_shared_meal_without_touching_it_keeps_the_share(): void
    {
        $uuid = (string) Str::uuid();

        $save = fn () => $this->put('/meals/'.$uuid, [
            'uuid'  => $uuid,
            'date'  => self::DATE,
            'time'  => '15:30',
            'items' => [[
                'name'  => 'Chocolate bar',
                'basis' => 'per_100g',
                // The sheet posts the WHOLE bar plus the fraction, reading it
                // back out of `portionFullGMin`.
                'grams'          => 100,
                'kcal_per_100g'  => 545,
                'share_fraction' => 0.5,
            ]],
        ]);

        $this->post('/meals', [
            'uuid'  => $uuid,
            'date'  => self::DATE,
            'time'  => '15:30',
            'items' => [['name' => 'Chocolate bar', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => 545, 'share_fraction' => 0.5]],
        ])->assertRedirect();

        $save()->assertRedirect();
        $save()->assertRedirect();

        $bar = Meal::query()->where('uuid', $uuid)->firstOrFail()->items()->sole();

        // Still half, not a quarter and not an eighth.
        self::assertSame('50.000', $bar->portion_g_min);
        self::assertSame('100.000', $bar->portion_full_g_min);
        self::assertSame('0.5000', $bar->share_fraction);
    }

    /**
     * Re-logging half a dinner re-logs half a dinner. `MealWriter::repeat()`
     * copies stored attributes verbatim, so the row is ALREADY scaled; applying
     * the share again would quarter it — the case `ConsumptionShare::apply()`
     * is idempotent for.
     */
    public function test_re_logging_a_shared_meal_does_not_scale_it_twice(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 0.5, items: [
            $this->item('White rice', 120, 200, 156, 260, share: 0.5),
        ])->assertStatus(303);

        $this->post('/meals/'.$meal->uuid.'/repeat', [
            'uuid' => (string) Str::uuid(),
            'date' => '2026-08-08',
            'time' => '12:00',
        ])->assertRedirect();

        $again = Meal::query()->where('local_date', '2026-08-08')->firstOrFail()->items()->sole();

        self::assertSame('60.000', $again->portion_g_min);
        self::assertSame('120.000', $again->portion_full_g_min);
        self::assertSame('0.5000', $again->share_fraction);
    }

    /**
     * A meal saved before any of this existed is untouched: the three plates of
     * the user's own Friday dinner must still render at share All, estimate and
     * eaten portion the same number.
     */
    public function test_a_meal_that_says_nothing_about_sharing_is_eaten_whole(): void
    {
        $meal = $this->proposedPlate();

        // No `share_fraction`: what an older phone replays off the queue.
        $this->confirm($meal, share: null, items: [
            $this->item('White rice', 120, 200, 156, 260, share: null),
        ])->assertStatus(303);

        $rice = $meal->items()->sole();

        self::assertSame('1.0000', $rice->share_fraction);
        self::assertSame('120.000', $rice->portion_g_min);
        self::assertSame('120.000', $rice->portion_full_g_min);
        self::assertEqualsWithDelta(156.0, $rice->kcalRange()['min'], 0.01);
    }

    // --- The offline queue, and the door ---

    /**
     * The share rides the queue because it rides the payload. Nothing in
     * `lib/queue.js` knows what a share is — it replays a URL, a method and a
     * body — so this pins that the review sheet's body still means the same
     * thing hours later, against an already-confirmed meal.
     */
    public function test_a_replayed_confirm_carries_the_share(): void
    {
        $meal = $this->proposedPlate();

        $payload = [
            'date'           => self::DATE,
            'time'           => '19:40',
            'meal_type'      => 'dinner',
            'notes'          => null,
            'client_id'      => $meal->photos()->sole()->client_id,
            'share_fraction' => 0.5,
            'items'          => [$this->item('White rice', 120, 200, 156, 260, share: 0.5)],
        ];

        // The queue's own fetch shape: JSON, XHR, no X-Inertia.
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        $this->withHeaders($headers)->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertStatus(303);
        $this->withHeaders($headers)->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertStatus(303);

        // Replayed twice, still one half portion — not a quarter, not two rice.
        $rice = $meal->items()->sole();

        self::assertSame('60.000', $rice->portion_g_min);
        self::assertSame('120.000', $rice->portion_full_g_min);
        self::assertSame('0.5000', $rice->share_fraction);
    }

    public function test_a_share_outside_the_range_is_refused_rather_than_silently_clamped(): void
    {
        $meal = $this->proposedPlate();

        $this->confirm($meal, share: 4, items: [$this->item('White rice', 120, 200, 156, 260)])
            ->assertSessionHasErrors('share_fraction');

        $this->confirm($meal, share: 0.5, items: [$this->item('White rice', 120, 200, 156, 260, share: 0)])
            ->assertSessionHasErrors('items.0.share_fraction');
    }

    public function test_a_guest_cannot_share_a_plate(): void
    {
        $meal = $this->proposedPlate();
        $photo = $meal->photos()->sole();

        auth()->logout();

        $this->put('/meals/'.$meal->uuid.'/proposal', [
            'date'           => self::DATE,
            'time'           => '19:40',
            'share_fraction' => 0.5,
            'items'          => [$this->item('White rice', 120, 200, 156, 260, share: 0.5)],
        ])->assertRedirect('/login');

        $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => (string) Str::uuid(),
            'share_fraction'  => 0.5,
        ])->assertStatus(401);

        self::assertSame('1.0000', $photo->refresh()->share_fraction);
    }

    // --- Helpers ---

    /** One photographed plate, analysed, waiting to be reviewed. */
    private function proposedPlate(): Meal
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        return $this->analysedPlate()[0];
    }

    /** @return array{Meal, MealPhoto} */
    private function analysedPlate(): array
    {
        $meal = Meal::factory()->create([
            'status'   => MealStatus::Analyzing,
            'source'   => MealSource::Photo,
            'eaten_at' => self::DATE.' 17:40:00',
        ]);

        $photo = MealPhoto::factory()->for($meal)->atPosition(0)->create();

        Storage::disk('meal-photos')->put($photo->path, TestImage::jpeg(1024, 768));
        Storage::disk('meal-photos')->put($photo->thumb_path, TestImage::jpeg(256, 192));

        $request = VisionRequest::factory()->pending()->create([
            'meal_id'       => $meal->id,
            'meal_photo_id' => $photo->id,
        ]);

        AnalyzeMealPhoto::dispatchSync($request->id);

        return [$meal->refresh(), $photo->refresh()];
    }

    /**
     * The review sheet's confirm, with the plate's chip and the items' own.
     *
     * @param  list<array<string, mixed>>  $items
     * @return TestResponse<Response>
     */
    private function confirm(Meal $meal, ?float $share, array $items): TestResponse
    {
        $payload = [
            'date'      => self::DATE,
            'time'      => '19:40',
            'meal_type' => 'dinner',
            'notes'     => null,
            'client_id' => $meal->photos()->sole()->client_id,
            'items'     => $items,
        ];

        if ($share !== null) {
            $payload['share_fraction'] = $share;
        }

        return $this->put('/meals/'.$meal->uuid.'/proposal', $payload);
    }

    /**
     * One line as the review sheet posts it: the WHOLE plate, plus a fraction.
     *
     * @return array<string, mixed>
     */
    private function item(string $name, float $portionMin, float $portionMax, float $kcalMin, float $kcalMax, ?float $share = null): array
    {
        $item = [
            'name'          => $name,
            'portion_g_min' => $portionMin,
            'portion_g_max' => $portionMax,
            'kcal_min'      => $kcalMin,
            'kcal_max'      => $kcalMax,
            'protein_g_min' => 0,
            'protein_g_max' => 0,
            'carbs_g_min'   => 0,
            'carbs_g_max'   => 0,
            'fat_g_min'     => 0,
            'fat_g_max'     => 0,
        ];

        if ($share !== null) {
            $item['share_fraction'] = $share;
        }

        return $item;
    }

    /**
     * Put a readable model answer on the plate's audit row: `vision:reproject`
     * rebuilds from `raw_response` and the fake analyzer stores only a stub.
     * The numbers mirror what was confirmed, so the rebuild is a no-op on
     * everything EXCEPT the shares — the only thing under test.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function storeAnswer(Meal $meal, array $items): void
    {
        $meal->visionRequests()->latest('id')->firstOrFail()->forceFill([
            'raw_response' => [
                'id'          => 'msg_shared_plate',
                'model'       => 'claude-opus-5',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => json_encode([
                    'items' => array_map(static fn (array $item): array => [
                        ...$item,
                        'protein_g_min' => 0, 'protein_g_max' => 0,
                        'carbs_g_min'   => 0, 'carbs_g_max' => 0,
                        'fat_g_min'     => 0, 'fat_g_max' => 0,
                        'confidence'    => 'high',
                    ], $items),
                    'notes' => 'Plate used as the scale reference.',
                ])]],
            ],
        ])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function dayProps(): array
    {
        return $this->get('/?date='.self::DATE)->viewData('page')['props'];
    }
}
