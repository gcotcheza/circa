<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use App\Enums\VisionRequestStatus;
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The job: photo -> model -> proposed items, and every way that can end.
 *
 * `analyzing` has exactly three exits — `proposed` with items, `proposed` with
 * none, or `failed` with a reason a human can read. A meal that reaches none of
 * them is stuck under a spinner forever.
 */
final class AnalyzeMealPhotoTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_a_successful_analysis_proposes_items_and_records_the_audit_row(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        [$meal, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        $meal->refresh();
        $request->refresh();

        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame(VisionRequestStatus::Succeeded, $request->status);
        self::assertSame(1_800, $request->input_tokens);
        self::assertSame(420, $request->output_tokens);
        self::assertSame(5_400, $request->latency_ms);
        self::assertSame(['stub' => true], $request->raw_response);
        self::assertNull($request->error);

        self::assertSame(2, $meal->items()->count());

        // Nothing is confirmed. That is the whole design: the model proposes.
        self::assertSame(0, $meal->items()->whereNotNull('confirmed_at')->count());

        // The model's note goes in its own column. It used to go into
        // `meals.notes` — harmless for a photo, destructive on the text path.
        // See ProposalWriter::propose().
        self::assertStringContainsString('scale reference', (string) $meal->model_notes);
        self::assertNull($meal->notes);
    }

    public function test_the_absolute_ranges_the_model_gave_survive_the_round_trip(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        [, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        $item = MealItem::query()->firstOrFail();

        // Stored as densities...
        self::assertSame(120.0, (float) $item->portion_g_min);
        self::assertSame(200.0, (float) $item->portion_g_max);
        self::assertSame(130.0, (float) $item->kcal_per_100g_min);
        self::assertSame(130.0, (float) $item->kcal_per_100g_max);

        // ...but the absolutes IntakeCalculator derives are the model's own to the
        // calorie: confirming an untouched proposal agrees to what was on screen.
        self::assertSame(156.0, round($item->kcalRange()['min'], 3));
        self::assertSame(260.0, round($item->kcalRange()['max'], 3));

        // high -> 0.9: the model calibrates words; the column is numeric, predating this prompt.
        self::assertSame(0.9, (float) $item->confidence);
    }

    public function test_a_photo_with_no_food_is_a_successful_analysis_with_nothing_in_it(): void
    {
        $this->analyzer->willFind('an empty table and a set of keys');

        [$meal, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        $meal->refresh();

        // `proposed`, NOT `failed`: the analysis worked and the answer was no.
        // A failure would invite retrying something that cannot succeed.
        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame(0, $meal->items()->count());
        self::assertStringContainsString('no food', (string) $meal->model_notes);

        self::assertSame(VisionRequestStatus::Succeeded, $request->refresh()->status);
    }

    public function test_a_refusal_fails_the_meal_with_something_a_human_can_read(): void
    {
        $this->analyzer->willRefuse();

        [$meal, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        $meal->refresh();
        $request->refresh();

        self::assertSame(MealStatus::Failed, $meal->status);
        self::assertSame(VisionRequestStatus::Failed, $request->status);
        self::assertStringContainsString('declined', (string) $request->error);

        // Usage is still recorded: a refusal happened, and the audit table is where it shows.
        self::assertSame(['stop_reason' => 'refusal'], $request->raw_response);
    }

    public function test_an_api_error_fails_the_meal_and_keeps_the_reason(): void
    {
        $this->analyzer->willFail('overloaded_error: the service is temporarily overloaded');

        [$meal, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        self::assertSame(MealStatus::Failed, $meal->refresh()->status);
        self::assertStringContainsString('overloaded', (string) $request->refresh()->error);
    }

    public function test_a_redelivered_job_does_not_pay_twice(): void
    {
        [, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);
        // At-least-once queues exist. The status guard is the lock.
        AnalyzeMealPhoto::dispatchSync($request->id);

        self::assertSame(1, $this->analyzer->callCount());
    }

    public function test_a_missing_photo_fails_rather_than_hangs(): void
    {
        [$meal, $request] = $this->pending();

        Storage::disk('meal-photos')->delete($meal->photos()->sole()->path);

        AnalyzeMealPhoto::dispatchSync($request->id);

        self::assertSame(MealStatus::Failed, $meal->refresh()->status);
        self::assertSame(0, $this->analyzer->callCount());
    }

    public function test_a_proposal_does_not_count_toward_the_day(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        [$meal, $request] = $this->pending();

        AnalyzeMealPhoto::dispatchSync($request->id);

        $date = $meal->refresh()->eaten_at
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();

        $this->get('/?date='.$date)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The meal is on screen...
                ->where('meals.0.status', 'proposed')
                // ...and contributes nothing: the rollup counts confirmed meals only.
                ->where('summary.kcalIn.mid', null)
            );
    }

    /**
     * A photo on the fake disk and a pending analysis, as MealPhotoController leaves it.
     *
     * @return array{Meal, VisionRequest, MealPhoto}
     */
    private function pending(?Meal $meal = null): array
    {
        $meal ??= Meal::factory()->create([
            'status' => MealStatus::Analyzing,
            'source' => MealSource::Photo,
        ]);

        $photo = MealPhoto::factory()->for($meal)->atPosition($meal->photos()->count())->create();

        Storage::disk('meal-photos')->put($photo->path, TestImage::jpeg(1024, 768));

        $request = VisionRequest::factory()->pending()->create([
            'meal_id'       => $meal->id,
            'meal_photo_id' => $photo->id,
        ]);

        return [$meal, $request, $photo];
    }
}
