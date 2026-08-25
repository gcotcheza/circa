<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The photographs that already exist survive becoming rows.
 *
 * A data migration runs once and is unfalsifiable afterwards, which is why the
 * one claim that matters is worth pinning: files on disk untouched, and every
 * `meals.photo_path` becoming the meal's plate 0 with its paths, hash and
 * meaning intact.
 *
 * "Meaning" is the easy part to lose. A path under `thumbs/` is not a photograph
 * waiting to be analysed but what retention left behind, and
 * `MealPhoto::hasOriginal()` must keep answering false for it — otherwise the
 * first run on a pruned meal sends a 256 px thumbnail to the model and presents
 * the answer as if it were the original's.
 *
 * The backfill is called directly rather than through `migrate`: the suite
 * migrates before every test, so seeding the pre-migration shape and re-running
 * the rule is the only way to watch it work.
 */
final class MealPhotoBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_photo_becomes_plate_zero_with_its_paths_and_hash(): void
    {
        $meal = $this->legacyMeal('originals/2026/08/185ab66f-289c-416f-a0ce-9a2b3a1186b0.jpg');

        $sha = str_repeat('a', 64);

        VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'request_kind'    => VisionRequestKind::Photo,
            'idempotency_key' => 'legacy-key',
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v1',
            'image_sha256'    => $sha,
            'status'          => VisionRequestStatus::Succeeded,
        ]);

        $this->backfill();

        $photo = $meal->photos()->sole();

        // Carried across verbatim: not moved, renamed or re-encoded, so the
        // bytes on disk are the same bytes.
        self::assertSame('originals/2026/08/185ab66f-289c-416f-a0ce-9a2b3a1186b0.jpg', $photo->path);
        self::assertSame('thumbs/2026/08/185ab66f-289c-416f-a0ce-9a2b3a1186b0.jpg', $photo->thumb_path);
        self::assertSame(0, $photo->position);
        self::assertTrue($photo->hasOriginal());

        // Off the audit row, so the hash still identifies the bytes the model saw.
        self::assertSame($sha, $photo->sha256);

        // The deprecated column is left as it was, which keeps this reversible.
        self::assertSame($photo->path, $meal->refresh()->photo_path);
    }

    public function test_a_meal_already_pruned_keeps_meaning_its_original_is_gone(): void
    {
        $meal = $this->legacyMeal('thumbs/2026/05/aaaaaaaa-0000-4000-8000-000000000001.jpg');

        $this->backfill();

        $photo = $meal->photos()->sole();

        // Both columns point at the thumbnail, because that is all there is.
        self::assertSame('thumbs/2026/05/aaaaaaaa-0000-4000-8000-000000000001.jpg', $photo->path);
        self::assertSame($photo->path, $photo->thumb_path);

        // The prefix still says so, which is what makes the re-analyse endpoint
        // refuse rather than send a postage stamp to the model.
        self::assertFalse($photo->hasOriginal());
    }

    public function test_the_model_note_moves_with_the_plate(): void
    {
        $meal = $this->legacyMeal('originals/2026/08/bbbbbbbb-0000-4000-8000-000000000002.jpg');

        $meal->forceFill(['model_notes' => 'Portion judged against a 27 cm plate.'])->save();

        $this->backfill();

        self::assertSame('Portion judged against a 27 cm plate.', $meal->photos()->sole()->model_notes);
    }

    public function test_a_meal_with_no_photograph_gets_no_row(): void
    {
        $meal = Meal::factory()->create(['photo_path' => null]);

        $this->backfill();

        self::assertSame(0, $meal->photos()->count());
    }

    /**
     * Running it twice writes nothing the second time. Not hypothetical: a
     * migration re-run after a partial failure, or a restore that replays it,
     * must not give every meal a second plate at a position that exists.
     */
    public function test_the_backfill_is_idempotent(): void
    {
        $meal = $this->legacyMeal('originals/2026/08/cccccccc-0000-4000-8000-000000000003.jpg');

        $this->backfill();
        $this->backfill();

        self::assertSame(1, $meal->photos()->count());
    }

    /**
     * A meal in the pre-migration shape: a `photo_path` and no plate.
     * `RefreshDatabase` has already run the real migration, so this row has to
     * be un-migrated by hand before the rule can be watched.
     */
    private function legacyMeal(string $path): Meal
    {
        $meal = Meal::factory()->create([
            'status'     => MealStatus::Proposed,
            'source'     => MealSource::Photo,
            'photo_path' => $path,
        ]);

        MealPhoto::query()->where('meal_id', $meal->id)->delete();

        return $meal;
    }

    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_08_08_300000_create_meal_photos_table.php');

        $migration->backfill();
    }
}
