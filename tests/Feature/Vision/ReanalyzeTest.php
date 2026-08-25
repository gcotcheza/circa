<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use Illuminate\Http\JsonResponse;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Analyse this photo again."
 *
 * The retry that matters is the deliberate one, so the job is `tries = 1` on
 * purpose: a photo the model declined will be declined again in thirty seconds,
 * and the person holding the phone judges better than an exponential backoff
 * whether to retry, take a better picture, or type it in. The button writes a
 * genuinely NEW `vision_requests` row rather than reusing the old one — two rows
 * against one image, two prompt versions, is the comparison the audit table
 * exists for.
 *
 * It re-analyses ONE PLATE: that plate's proposal is replaced, and every other
 * plate's items, and anything typed or scanned onto the meal, stay where they
 * are.
 */
final class ReanalyzeTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_a_failed_analysis_can_be_retried_from_the_ui_and_succeed(): void
    {
        $this->analyzer->willFail('overloaded_error');

        [$meal, $photo, $request] = $this->analysed();

        self::assertSame(MealStatus::Failed, $meal->refresh()->status);

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $this->reanalyze($meal, $photo)->assertStatus(202);

        $meal->refresh();

        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame(1, $meal->items()->count());

        // Two rows, one image: the history is the point.
        self::assertSame(2, VisionRequest::query()->where('meal_id', $meal->id)->count());
        self::assertSame(2, $this->analyzer->callCount());
        self::assertNotSame($request->idempotency_key, VisionRequest::query()->latest('id')->firstOrFail()->idempotency_key);
    }

    public function test_a_re_analysis_replaces_the_previous_proposal_rather_than_adding_to_it(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        [$meal, $photo] = $this->analysed();

        self::assertSame(2, $meal->items()->count());

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);

        $this->reanalyze($meal, $photo)->assertStatus(202);

        // Not three: the first proposal was never confirmed, and keeping it
        // leaves the user reviewing two answers to one photograph.
        self::assertSame(1, $meal->refresh()->items()->count());
        self::assertSame('Grilled chicken breast', (string) $meal->items()->firstOrFail()->name);
    }

    public function test_the_same_key_still_buys_only_one_analysis(): void
    {
        $this->analyzer->willFail();

        [$meal, $photo] = $this->analysed();

        $key = (string) Str::uuid();

        $this->reanalyze($meal, $photo, $key)->assertStatus(202);
        $this->reanalyze($meal, $photo, $key)->assertStatus(200);

        self::assertSame(2, VisionRequest::query()->count());
        self::assertSame(2, $this->analyzer->callCount());
    }

    /**
     * A plate on a CONFIRMED meal can still be re-analysed. The old rule had to
     * refuse: with one photograph per meal it would have replaced everything the
     * user had agreed to. Scoped to one plate it means "this course was wrong",
     * and the MEAL stays confirmed so the rest of the dinner stays in the day's
     * total while the model has another look.
     *
     * That plate's own items are superseded, confirmed or not — a plate has one
     * answer at a time — and come back as a proposal, so it contributes nothing
     * until it is confirmed again.
     */
    public function test_re_analysing_one_plate_of_a_confirmed_meal_keeps_the_meal_confirmed(): void
    {
        [$meal, $photo] = $this->analysed();

        // The user confirmed the main course.
        $meal->items()->update(['confirmed_at' => now()]);
        $meal->status = MealStatus::Confirmed;
        $meal->save();

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);

        $this->reanalyze($meal, $photo)->assertStatus(202);

        $meal->refresh();

        // `Meal::scopeConfirmed()` is what the daily rollup selects on: the meal
        // must not drop out of the day because a photograph is being re-read.
        self::assertSame(MealStatus::Confirmed, $meal->status);

        // One answer per plate, awaiting its own tap.
        self::assertSame(1, $meal->items()->count());
        self::assertSame('Grilled chicken breast', (string) $meal->items()->sole()->name);
        self::assertSame(0, $meal->confirmedItems()->count());
    }

    /**
     * A re-analysis that FAILS costs nothing: the proposal is only written when
     * an answer actually arrives, so a confirmed course survives one that times
     * out or is declined — the difference between "have another look" and
     * "gamble the numbers I already agreed to".
     */
    public function test_a_failed_re_analysis_leaves_confirmed_items_standing(): void
    {
        [$meal, $photo] = $this->analysed();

        $meal->items()->update(['confirmed_at' => now()]);
        $meal->status = MealStatus::Confirmed;
        $meal->save();

        $confirmedIds = $meal->confirmedItems()->pluck('id')->all();

        $this->analyzer->willFail('overloaded_error');

        $this->reanalyze($meal, $photo)->assertStatus(202);

        self::assertSame($confirmedIds, $meal->refresh()->confirmedItems()->pluck('id')->all());
        self::assertSame(MealStatus::Confirmed, $meal->status);
    }

    /**
     * Re-analysing the dessert does not touch the main course — what
     * `meal_items.meal_photo_id` is for. Without it the scope is "the meal's
     * unconfirmed items", which after a second plate is a pile from two answers.
     */
    public function test_re_analysing_one_plate_leaves_the_other_plates_proposals_alone(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        [$meal, $first] = $this->analysed();

        $this->analyzer->willPropose([FakeVisionAnalyzer::broccoli()]);

        [, $second] = $this->analysed($meal);

        self::assertSame(2, $meal->refresh()->items()->count());

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);

        $this->reanalyze($meal, $second)->assertStatus(202);

        $names = $meal->refresh()->items()->orderBy('id')->pluck('name')->all();

        // The first plate's rice survives; only the broccoli was replaced.
        self::assertSame(['White rice', 'Grilled chicken breast'], $names);
        self::assertSame($first->id, $meal->items()->where('name', 'White rice')->firstOrFail()->meal_photo_id);
    }

    public function test_a_plate_whose_original_has_been_pruned_cannot_be_re_analysed(): void
    {
        [$meal, $photo] = $this->analysed();

        // What the retention prune leaves: the thumbnail, and a path saying so.
        $photo->forceFill(['path' => $photo->thumb_path])->save();

        $this->reanalyze($meal, $photo)
            ->assertStatus(409)
            ->assertJsonPath('status', 'photo_gone');

        // A dinner named from a 256 px thumbnail would look exactly as
        // authoritative as the first answer.
        self::assertSame(1, $this->analyzer->callCount());
    }

    public function test_a_plate_that_is_not_on_this_meal_is_a_404(): void
    {
        [$meal] = $this->analysed();
        [, $other] = $this->analysed();

        $this->reanalyze($meal, $other)->assertStatus(404);
    }

    /**
     * A meal with one plate that has been through one analysis already.
     *
     * @return array{Meal, MealPhoto, VisionRequest}
     */
    private function analysed(?Meal $meal = null): array
    {
        $meal ??= Meal::factory()->create([
            'status' => MealStatus::Analyzing,
            'source' => MealSource::Photo,
        ]);

        $photo = MealPhoto::factory()->for($meal)->atPosition($meal->photos()->count())->create();

        Storage::disk('meal-photos')->put($photo->path, TestImage::jpeg(1024, 768));
        Storage::disk('meal-photos')->put($photo->thumb_path, TestImage::jpeg(256, 192));

        $request = VisionRequest::factory()->pending()->create([
            'meal_id'       => $meal->id,
            'meal_photo_id' => $photo->id,
        ]);

        AnalyzeMealPhoto::dispatchSync($request->id);

        return [$meal, $photo->refresh(), $request->refresh()];
    }

    /** @return TestResponse<JsonResponse> */
    private function reanalyze(Meal $meal, MealPhoto $photo, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }
}
