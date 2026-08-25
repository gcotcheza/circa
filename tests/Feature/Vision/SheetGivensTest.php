<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use Illuminate\Http\UploadedFile;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "ESTIMATE THIS AGAIN" HONOURS WHAT IS ON THE SHEET.
 *
 * WHAT THE USER SAW. A proposal came back with a portion of 2 g. They corrected
 * it in the review sheet to the 250 g they had weighed and tapped "Estimate this
 * again". The request carried an idempotency key and nothing else, so the server
 * rebuilt the question from the STORED items — the previous answer — asked the
 * model the same thing with the same 2 g, and got the same garbage back. The
 * 250 g had never left the phone. From outside it read as the app reverting a
 * correction on purpose.
 *
 * THE RULE. The sheet sends what it is holding. A value corrected and settled on
 * (min === max after the edit) is a GIVEN in the next request, on the same terms
 * as a figure typed into the meal sheet: described to the model as "GIVEN — use
 * exactly this", recorded in `input_payload`, written back over model and meal
 * memory by TypedMeal::preserve(). Anything untouched stays estimable. A sheet
 * holding NO corrections sends nothing and the server reconstructs as it always
 * has — that path is pinned by ReestimateTest and must not move.
 */
final class SheetGivensTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    /** The reported failure end to end: the corrected portion is what the next answer is built on. */
    public function test_a_portion_corrected_on_the_sheet_becomes_a_given(): void
    {
        $meal = $this->proposedTextMeal();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
            // What the sheet holds after the user typed 250 over the 2.
            'items' => [
                ['name' => 'White rice', 'portion_g' => 250, 'kcal' => null],
                ['name' => 'Grilled chicken breast', 'portion_g' => null, 'kcal' => null],
            ],
        ])->assertStatus(202);

        $request = VisionRequest::query()->latest('id')->firstOrFail();

        // Recorded on the audit row: the answer is explicable afterwards.
        self::assertSame(250.0, (float) $this->notNull($request->input_payload)['items'][0]['grams']);

        EstimateMealNutrition::dispatchSync($request->id);

        // Said to the model in the words reserved for a number nobody may improve.
        self::assertStringContainsString('Portion: 250 g (GIVEN — use exactly this)', $this->analyzer->lastDescription());

        $rice = Meal::query()->where('uuid', $meal->uuid)->sole()
            ->items()->where('name', 'White rice')->sole();

        // The model came back with 300-400 g (wrongRice). The user weighed it.
        self::assertSame(250.0, (float) $rice->portion_g_min);
        self::assertSame(250.0, (float) $rice->portion_g_max);
    }

    /** A field the user did not touch is still a question. */
    public function test_an_untouched_field_stays_estimable(): void
    {
        $meal = $this->proposedTextMeal();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
            'items'           => [
                ['name' => 'White rice', 'portion_g' => 250],
                ['name' => 'Grilled chicken breast'],
            ],
        ])->assertStatus(202);

        $request = VisionRequest::query()->latest('id')->firstOrFail();

        EstimateMealNutrition::dispatchSync($request->id);

        $description = $this->analyzer->lastDescription();

        // The rice's ENERGY was never corrected, so it is still asked about.
        self::assertStringContainsString('Energy: NOT GIVEN', $description);

        // The chicken, untouched, is entirely a question.
        self::assertStringContainsString("2. Grilled chicken breast\n   - Portion: NOT GIVEN", $description);

        $chicken = Meal::query()->where('uuid', $meal->uuid)->sole()
            ->items()->where('name', 'Grilled chicken breast')->sole();

        // Straight from the model, unpinned.
        self::assertSame(100.0, (float) $chicken->portion_g_min);
        self::assertSame(150.0, (float) $chicken->portion_g_max);
    }

    /**
     * A portion AND a total are one claim. The sheet speaks in absolutes, rows
     * store densities, so "250 g and 400 kcal" is kept as 160 kcal/100 g of
     * 250 g and reads back as exactly 400 kcal at both ends.
     */
    public function test_a_stated_portion_and_total_are_stored_as_one_claim(): void
    {
        $meal = $this->proposedTextMeal();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
            'items'           => [
                ['name' => 'White rice', 'portion_g' => 250, 'kcal' => 400],
                ['name' => 'Grilled chicken breast'],
            ],
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->firstOrFail()->id);

        $rice = Meal::query()->where('uuid', $meal->uuid)->sole()
            ->items()->where('name', 'White rice')->sole();

        self::assertSame(250.0, (float) $rice->portion_g_min);
        self::assertSame(160.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(160.0, (float) $rice->kcal_per_100g_max);

        self::assertSame(400.0, round($rice->kcalRange()['min'], 3));
        self::assertSame(400.0, round($rice->kcalRange()['max'], 3));
    }

    /**
     * An item the user has put a number on cannot be dropped, even if the model
     * forgets it (TypedMeal::complete): ProposalWriter replaces a scope's items
     * wholesale, so an omitted line would otherwise be silently deleted.
     */
    public function test_a_corrected_item_survives_an_answer_that_omits_it(): void
    {
        $meal = $this->proposedTextMeal();

        // The second ask comes back about the chicken alone.
        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
            'items'           => [
                ['name' => 'White rice', 'portion_g' => 250],
                ['name' => 'Grilled chicken breast'],
            ],
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->firstOrFail()->id);

        $items = Meal::query()->where('uuid', $meal->uuid)->sole()->items()->get();

        self::assertSame(['Grilled chicken breast', 'White rice'], $items->pluck('name')->sort()->values()->all());
        self::assertSame(250.0, (float) $this->notNull($items->firstWhere('name', 'White rice'))->portion_g_min);
    }

    /** A sheet that states nothing is the old request, byte for byte. */
    public function test_a_request_without_items_is_unchanged(): void
    {
        $meal = $this->proposedTextMeal();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        $request = VisionRequest::query()->latest('id')->firstOrFail();

        // Reconstructed from the ORIGINAL typed input: both names, no figures.
        $inputPayload = $this->notNull($request->input_payload);
        self::assertSame('White rice', $inputPayload['items'][0]['name']);
        self::assertNull($inputPayload['items'][0]['grams']);
    }

    /** The ceilings apply here as they do on every other number. */
    public function test_an_implausible_given_is_refused(): void
    {
        $meal = $this->proposedTextMeal();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
            'items'           => [['name' => 'White rice', 'portion_g' => 99999]],
        ])->assertStatus(422);

        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
    }

    /**
     * THE PHOTO HALF: a correction survives "Analyse this photo again". The model
     * is not told — it is being asked what is in a photograph — but the figure is
     * restored over the fresh answer, and because rows store densities the rest
     * of the item rescales to that weight coherently.
     */
    public function test_a_correction_survives_a_re_analysis_of_the_plate(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '13:20',
            'photo'           => UploadedFile::fake()->createWithContent('meal.jpg', TestImage::jpeg(1200, 900)),
        ])->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->sole();
        $photo = $meal->photos()->sole();

        $this->postJson("/api/meals/{$uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            'items'           => [['name' => 'White rice', 'portion_g' => 250]],
        ])->assertStatus(202);

        $request = VisionRequest::query()->latest('id')->firstOrFail();

        self::assertSame(250.0, (float) $this->notNull($request->input_payload)['given']['items'][0]['grams']);

        AnalyzeMealPhoto::dispatchSync($request->id);

        $rice = $meal->refresh()->items()->sole();

        // Fresh answer: 120-200 g. The user weighed 250 g, and the density it
        // was estimated at survives that.
        self::assertSame(250.0, (float) $rice->portion_g_min);
        self::assertSame(250.0, (float) $rice->portion_g_max);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_min);
    }

    /** A `proposed` meal of two name-only items — the state the review sheet opens on. */
    private function proposedTextMeal(): Meal
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice(), FakeVisionAnalyzer::chicken()]);

        $uuid = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '12:30',
            'items'           => [
                ['name' => 'White rice', 'basis' => 'absolute'],
                ['name' => 'Grilled chicken breast', 'basis' => 'absolute'],
            ],
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->firstOrFail()->id);

        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice(), FakeVisionAnalyzer::chicken()]);

        return Meal::query()->where('uuid', $uuid)->sole();
    }
}
