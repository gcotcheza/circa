<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use Tests\Support\TestImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Photo retention.
 *
 * `meal_items` is the record of what was eaten; the photograph is evidence for a
 * decision already made, so a year of full-resolution pictures of somebody's
 * kitchen is a liability with no matching benefit. The 256 px thumbnail survives
 * because a picture beside each meal is how a human recognises "that Tuesday".
 * A scheduled command must also be idempotent: twice is the same as once. It
 * walks PLATES — a dinner in three courses is three rows, expiring on the MEAL's
 * `eaten_at` rather than their own `created_at`, so plates go together rather
 * than the dessert outliving the main course by twenty minutes.
 */
final class PruneMealPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_an_old_original_is_deleted_the_thumbnail_kept_and_the_path_repointed(): void
    {
        $old = $this->photoOn($this->mealAt(CarbonImmutable::now()->subDays(200)));
        $recent = $this->photoOn($this->mealAt(CarbonImmutable::now()->subDays(3)));

        $this->runArtisan('photos:prune')->assertSuccessful();

        Storage::disk('meal-photos')->assertMissing($old->path);
        Storage::disk('meal-photos')->assertExists($old->thumb_path);

        // Repointed, not nulled: a null orphans the thumbnail, and the `thumbs/`
        // prefix is how the re-analyse endpoint knows the original is gone.
        self::assertSame($old->thumb_path, $old->refresh()->path);

        // The recent one is untouched.
        Storage::disk('meal-photos')->assertExists($recent->path);
        self::assertStringStartsWith('originals/', $recent->refresh()->path);
    }

    /**
     * Every plate goes, not just the first. The old command walked meals and
     * repointed one column, so a three-course meal kept two full-size photographs
     * forever — the liability retention exists to remove.
     */
    public function test_every_plate_of_an_old_meal_is_pruned(): void
    {
        $meal = $this->mealAt(CarbonImmutable::now()->subDays(200));

        $plates = [
            $this->photoOn($meal, 0),
            $this->photoOn($meal, 1),
            $this->photoOn($meal, 2),
        ];

        $this->runArtisan('photos:prune')->expectsOutputToContain('3 original(s)')->assertSuccessful();

        foreach ($plates as $plate) {
            Storage::disk('meal-photos')->assertMissing($plate->path);
            Storage::disk('meal-photos')->assertExists($plate->thumb_path);
            self::assertStringStartsWith('thumbs/', $plate->refresh()->path);
        }
    }

    public function test_running_it_twice_does_nothing_the_second_time(): void
    {
        $photo = $this->photoOn($this->mealAt(CarbonImmutable::now()->subDays(200)));

        $this->runArtisan('photos:prune')->expectsOutputToContain('1 original(s)')->assertSuccessful();
        $this->runArtisan('photos:prune')->expectsOutputToContain('0 original(s)')->assertSuccessful();

        self::assertStringStartsWith('thumbs/', $photo->refresh()->path);
    }

    public function test_the_window_can_be_overridden(): void
    {
        $photo = $this->photoOn($this->mealAt(CarbonImmutable::now()->subDays(10)));

        $this->runArtisan('photos:prune')->assertSuccessful();
        self::assertStringStartsWith('originals/', $photo->refresh()->path);

        $this->runArtisan('photos:prune', ['--days' => 7])->assertSuccessful();
        self::assertStringStartsWith('thumbs/', $photo->refresh()->path);
    }

    public function test_a_dry_run_reports_and_deletes_nothing(): void
    {
        $photo = $this->photoOn($this->mealAt(CarbonImmutable::now()->subDays(200)));

        $this->runArtisan('photos:prune', ['--dry-run' => true])
            ->expectsOutputToContain('would be deleted')
            ->assertSuccessful();

        Storage::disk('meal-photos')->assertExists($photo->path);
        self::assertStringStartsWith('originals/', $photo->refresh()->path);
    }

    public function test_pruning_does_not_change_what_the_day_says_was_eaten(): void
    {
        $meal = $this->mealAt(CarbonImmutable::now()->subDays(200));
        $this->photoOn($meal);

        $meal->items()->create([
            'name'                 => 'White rice',
            'slug'                 => 'white-rice',
            'portion_g_min'        => 120, 'portion_g_max' => 200,
            'kcal_per_100g_min'    => 130, 'kcal_per_100g_max' => 130,
            'protein_per_100g_min' => 2.7, 'protein_per_100g_max' => 2.7,
            'carbs_per_100g_min'   => 28, 'carbs_per_100g_max' => 28,
            'fat_per_100g_min'     => 0.3, 'fat_per_100g_max' => 0.3,
            'confirmed_at'         => CarbonImmutable::now(),
        ]);

        $this->runArtisan('photos:prune')->assertSuccessful();

        // The record is the items: deleting a photograph is housekeeping, not a log change.
        self::assertSame(1, $meal->refresh()->items()->count());
        self::assertSame(156.0, round($meal->items()->firstOrFail()->kcalRange()['min'], 3));
    }

    private function mealAt(CarbonImmutable $eatenAt): Meal
    {
        return Meal::factory()->create([
            'status'   => MealStatus::Confirmed,
            'source'   => MealSource::Photo,
            'eaten_at' => $eatenAt->utc(),
        ]);
    }

    private function photoOn(Meal $meal, int $position = 0): MealPhoto
    {
        $photo = MealPhoto::factory()->for($meal)->atPosition($position)->create();

        Storage::disk('meal-photos')->put($photo->path, TestImage::jpeg(1024, 768));
        Storage::disk('meal-photos')->put($photo->thumb_path, TestImage::jpeg(256, 192));

        return $photo;
    }
}
