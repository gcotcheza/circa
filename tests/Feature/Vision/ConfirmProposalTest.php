<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use App\Models\DailySummary;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A proposal becomes a logged meal, run end to end because only the whole
 * pipeline proves the ranges survive every hop to the day's intake band.
 */
final class ConfirmProposalTest extends TestCase
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

    public function test_photo_to_analyzing_to_proposed_to_confirmed_rebuilds_the_day(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        $uuid = (string) Str::uuid();

        // Upload: the sync queue runs the job in-request, so it comes out proposed.
        $this->post('/api/meals/photo', $this->upload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame(MealSource::Photo, $meal->source);
        self::assertSame(2, $meal->items()->count());
        self::assertSame(1, VisionRequest::query()->count());

        // Nothing reaches the day before the confirm.
        self::assertNull(DailySummary::query()->find(self::DATE)?->kcal_in_mid);

        $this->put('/meals/'.$uuid.'/proposal', $this->confirmation($meal))
            ->assertRedirect('/?date='.self::DATE);

        $meal->refresh();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame(2, $meal->items()->whereNotNull('confirmed_at')->count());

        // Rice 156-260 + chicken 165-247.5 = 321-507.5, midpoint 414.25. The
        // band is a quadrature about that midpoint, so assert the midpoint.
        $summary = DailySummary::query()->find(self::DATE);

        self::assertNotNull($summary);
        self::assertSame(414.25, round((float) $summary->kcal_in_mid, 2));
    }

    public function test_confirming_an_untouched_proposal_changes_none_of_the_numbers(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();
        $this->post('/api/meals/photo', $this->upload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $before = $meal->items()->firstOrFail()->only([
            'portion_g_min', 'portion_g_max', 'kcal_per_100g_min', 'kcal_per_100g_max',
            'protein_per_100g_min', 'carbs_per_100g_min', 'fat_per_100g_min',
        ]);

        $this->put('/meals/'.$uuid.'/proposal', $this->confirmation($meal))->assertRedirect();

        $after = MealItem::query()->firstOrFail()->only(array_keys($before));

        // The same mapper converts both ways, so an unedited proposal round-trips exact.
        self::assertSame($before, $after);
    }

    public function test_an_edited_portion_recomputes_and_the_range_stays_a_range(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();
        $this->post('/api/meals/photo', $this->upload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $payload = $this->confirmation($meal);
        // "That is more like 200-300 g, so 260-390 kcal."
        $payload['items'][0]['portion_g_min'] = 200;
        $payload['items'][0]['portion_g_max'] = 300;
        $payload['items'][0]['kcal_min'] = 260;
        $payload['items'][0]['kcal_max'] = 390;

        $this->put('/meals/'.$uuid.'/proposal', $payload)->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        self::assertSame(200.0, (float) $item->portion_g_min);
        self::assertSame(300.0, (float) $item->portion_g_max);
        self::assertSame(260.0, round($item->kcalRange()['min'], 3));
        self::assertSame(390.0, round($item->kcalRange()['max'], 3));
    }

    public function test_a_maximum_below_its_minimum_is_a_validation_error(): void
    {
        $meal = $this->proposedMeal();

        $payload = $this->confirmation($meal);
        $payload['items'][0]['kcal_max'] = 1;

        // A negative-width band is not a range, and would enter the day's quadrature.
        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)
            ->assertSessionHasErrors('items.0.kcal_max');

        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
    }

    public function test_a_confirmation_with_no_items_is_refused(): void
    {
        $meal = $this->proposedMeal();

        $payload = $this->confirmation($meal);
        $payload['items'] = [];

        // "No food in this photo" is a Discard, not a confirmed 0 kcal meal.
        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertSessionHasErrors('items');
    }

    public function test_a_meal_still_being_analysed_cannot_be_confirmed(): void
    {
        $meal = $this->proposedMeal();
        $payload = $this->confirmation($meal);

        // The ENTRY is analysed, not the meal — a confirmed dinner can hold an
        // analysing dessert — so `meals.status` cannot answer this. The pending
        // row is claimed exactly as MealPhotoController::reanalyze claims it.
        VisionRequest::factory()->pending()->create([
            'meal_id'       => $meal->id,
            'meal_photo_id' => $meal->photos()->sole()->id,
        ]);

        $meal->status = MealStatus::Analyzing;
        $meal->save();

        // Otherwise the job lands on top of the items the user just agreed to.
        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertSessionHas('error');

        self::assertSame(MealStatus::Analyzing, $meal->refresh()->status);
    }

    public function test_discarding_a_proposal_deletes_the_meal_and_both_images(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->upload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $photo = $meal->photos()->sole();
        $original = $photo->path;
        $thumb = $photo->thumb_path;

        Storage::disk('meal-photos')->assertExists($original);
        Storage::disk('meal-photos')->assertExists($thumb);

        $this->delete('/meals/'.$uuid)->assertRedirect();

        self::assertSame(0, Meal::query()->count());

        // No record left to support, so keeping somebody's dinner needs a reason.
        Storage::disk('meal-photos')->assertMissing($original);
        Storage::disk('meal-photos')->assertMissing($thumb);
    }

    private function proposedMeal(): Meal
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();
        $this->post('/api/meals/photo', $this->upload($uuid))->assertStatus(202);

        return Meal::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
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
                'confidence'    => 'medium',
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
            'time'      => '13:20',
            'meal_type' => 'lunch',
            'notes'     => $meal->notes,
            'items'     => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function upload(string $uuid): array
    {
        return [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '13:20',
            'photo'           => UploadedFile::fake()->createWithContent('meal.jpg', TestImage::jpeg(1200, 900)),
        ];
    }
}
