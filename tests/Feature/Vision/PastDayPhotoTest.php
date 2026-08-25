<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Yesterday's lunch, photographed yesterday, logged today.
 *
 * The photo path is the ONLY entry path whose sheet has no visible date, and
 * picking an existing photo out of the library made logging onto a day that is
 * not today an everyday action rather than a correction. The day a meal belongs
 * to comes from three things agreeing: the date the day view shows, the time in
 * the capture sheet, and `config('health.timezone')`. `meals.local_date` is a
 * Postgres STORED GENERATED column computed from `eaten_at` with the zone in the
 * DDL, so a mistake anywhere in that chain does not fail — it silently files the
 * meal under the wrong day, and the only symptom is a calorie total wrong on two
 * days at once. Nothing here changes the upload code; it pins a property that
 * code already had, now that the UI made it load-bearing.
 */
final class PastDayPhotoTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_a_library_photo_lands_on_the_day_that_was_selected(): void
    {
        // Held at `analyzing`, so this asserts the UPLOAD, not the analysis.
        Queue::fake();

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            // The day being viewed, not today. This is the whole point.
            'date'  => '2026-06-14',
            'time'  => '13:05',
            'photo' => UploadedFile::fake()->createWithContent('lunch.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Analyzing, $meal->status);
        self::assertSame('2026-06-14', $meal->local_date->toDateString());

        // 13:05 Europe/Amsterdam in June is CEST (+02:00), so 11:05 UTC.
        self::assertSame(
            '2026-06-14 11:05:00',
            CarbonImmutable::parse($meal->eaten_at)->utc()->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '13:05',
            CarbonImmutable::parse($meal->eaten_at)
                ->setTimezone((string) config('health.timezone'))
                ->format('H:i')
        );
    }

    public function test_the_analysis_does_not_move_the_meal_back_to_today(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-14',
            'time'            => '13:05',
            'photo'           => UploadedFile::fake()->createWithContent('lunch.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        AnalyzeMealPhoto::dispatchSync(VisionRequest::query()->sole()->id);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame('2026-06-14', $meal->local_date->toDateString());
    }

    public function test_confirming_keeps_the_day_the_photo_was_logged_to(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-14',
            'time'            => '13:05',
            'photo'           => UploadedFile::fake()->createWithContent('lunch.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        AnalyzeMealPhoto::dispatchSync(VisionRequest::query()->sole()->id);

        // The review sheet posts the date it opened with — the card's day, not the tap's.
        $this->put("/meals/{$uuid}/proposal", [
            'date'      => '2026-06-14',
            'time'      => '13:05',
            'meal_type' => 'lunch',
            'items'     => [[
                'name'          => 'White rice',
                'portion_g_min' => 120, 'portion_g_max' => 200,
                'kcal_min'      => 156, 'kcal_max' => 260,
                'protein_g_min' => 3.24, 'protein_g_max' => 5.4,
                'carbs_g_min'   => 33.6, 'carbs_g_max' => 56,
                'fat_g_min'     => 0.36, 'fat_g_max' => 0.6,
            ]],
        ])->assertRedirect(route('day', ['date' => '2026-06-14']));

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame('2026-06-14', $meal->local_date->toDateString());
    }

    public function test_a_late_evening_photo_does_not_slide_into_the_next_day(): void
    {
        /*
         * Called out in SPEC.md: a Carbon handed straight to a `timestamptz`
         * column is serialised WITHOUT its offset, so 23:30 Amsterdam is stored as
         * 23:30 UTC and `local_date` reads it back as 01:30 next morning — the
         * wrong day, and only near midnight.
         */
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-14',
            'time'            => '23:30',
            'photo'           => UploadedFile::fake()->createWithContent('supper.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame('2026-06-14', $meal->local_date->toDateString());
        self::assertSame('2026-06-14 21:30:00', CarbonImmutable::parse($meal->eaten_at)->utc()->format('Y-m-d H:i:s'));
    }
}
