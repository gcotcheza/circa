<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\User;
use App\Models\MealPhoto;
use Tests\Support\TestImage;
use Tests\Concerns\ReadsSource;
use App\Services\Reporting\DailyView;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The thumbnail: which file it is, who may fetch it, and — since 2026-08-10 —
 * who draws it.
 *
 * THE DAY CARD NO LONGER SHOWS IT. The user's decision: two meals with
 * photographs filled a whole phone screen, and the day list is the screen you
 * scan, so the picture moved to the sheet the card opens. Everything below the
 * first section is unchanged on purpose — payload, route and both sheets still
 * want that thumbnail.
 *
 * THE BUG THESE TESTS CLOSE. A dinner card showed an empty 96 px box. The server
 * was healthy — the same file had gone to the same phone as a 200 of 191,825
 * bytes thirty seconds earlier — but the browser made NO request for it at all,
 * and the card stayed blank until a full page load. It was asking for the
 * FULL-SIZE original (768x1024, ~190 KB) to draw 96 px high, `loading="lazy"`,
 * which a browser may decline. The thumbnail beside every original is 192x256,
 * ~13 KB, and is the file retention KEEPS. Hence a payload naming the thumbnail,
 * a route of its own, and a year's cache — that URL names a plate whose
 * thumbnail is written once and never rewritten.
 */
final class DayCardPhotoTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const DATE = '2026-08-07';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    // --- What the card is told to fetch ------------------------------------

    public function test_the_card_is_pointed_at_the_thumbnail_of_the_first_plate(): void
    {
        $meal = $this->mealWithPlates(3);

        $first = $this->notNull($meal->photos()->first());

        $props = (new DailyView)->props(self::DATE);

        self::assertSame(
            route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $first->client_id]),
            $props['meals'][0]['photoUrl'],
        );

        // Explicitly NOT the full-size route: that regression is invisible on
        // screen, costing fourteen times the bytes and the file retention takes.
        self::assertNotSame(
            route('meals.photo.show', ['meal' => $meal->uuid]),
            $props['meals'][0]['photoUrl'],
        );
    }

    public function test_every_plate_carries_both_sizes(): void
    {
        $meal = $this->mealWithPlates(2);

        $props = (new DailyView)->props(self::DATE);

        foreach ($props['meals'][0]['photos'] as $plate) {
            self::assertSame(
                route('meals.photo.at', ['meal' => $meal->uuid, 'photo' => $plate['clientId']]),
                $plate['url'],
            );

            self::assertSame(
                route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $plate['clientId']]),
                $plate['thumbUrl'],
            );
        }
    }

    public function test_a_meal_with_no_photographs_has_no_photo_url(): void
    {
        Meal::factory()->create(['eaten_at' => self::DATE.' 12:00:00']);

        $props = (new DailyView)->props(self::DATE);

        self::assertNull($props['meals'][0]['photoUrl']);
        self::assertSame([], $props['meals'][0]['photos']);
    }

    /**
     * ...and the card does not draw any of it. Read off the source, as
     * ModelNoteVisibilityTest reads the same file: an Inertia SPA with no
     * component renderer never emits the card's markup server-side. Both halves
     * are asserted together — a card with no picture is only correct while the
     * sheets still have one.
     */
    public function test_the_day_card_draws_no_picture_and_the_sheets_still_do(): void
    {
        $card = $this->sourceWithoutComments('resources/js/Components/MealCard.vue');

        self::assertStringNotContainsString(
            '<img',
            $card,
            'MealCard.vue draws a picture again. The day list is the screen that gets scanned; '
                .'the photograph belongs in the sheet the card opens.'
        );

        // The machinery that kept that picture from leaving a hole goes with it.
        self::assertStringNotContainsString('photoSrc', $card);
        self::assertStringNotContainsString('@error', $card);

        // The state line stays: without it, a confirmed dinner with an unreviewed
        // dessert is invisible on the day.
        self::assertStringContainsString('more photo', $card);

        // The edit sheet's plate strip, still pointed at the thumbnail.
        self::assertStringContainsString(
            'plate.thumbUrl',
            $this->sourceWithoutComments('resources/js/Components/MealSheet.vue'),
            'Nothing shows the photograph any more, which is not what was asked for.'
        );
    }

    // --- The route ---------------------------------------------------------

    public function test_the_thumb_route_serves_the_thumbnail_and_not_the_original(): void
    {
        $meal = $this->mealWithPlates(1);
        $photo = $this->notNull($meal->photos()->first());

        $this->actingAs(User::factory()->create());

        $response = $this->get(route('meals.photo.thumb', [
            'meal'  => $meal->uuid,
            'photo' => $photo->client_id,
        ]));

        $response->assertOk();

        $bytes = $response->streamedContent();

        self::assertSame(Storage::disk('meal-photos')->get($photo->thumb_path), $bytes);
        self::assertNotSame(Storage::disk('meal-photos')->get($photo->path), $bytes);

        // The whole exercise in pixels: the slot is 96 px tall, this file fits it.
        $thumbSize = getimagesizefromstring($bytes);
        self::assertIsArray($thumbSize);
        [$width, $height] = $thumbSize;

        self::assertSame(192, $width);
        self::assertSame(256, $height);
    }

    /**
     * A year, and `immutable` with it. PhotoStore writes a plate's thumbnail
     * once; retention deletes the ORIGINAL and repoints `path`, leaving
     * `thumb_path` alone, and deleting the plate 404s rather than changing what
     * is behind the URL. The bytes genuinely cannot change — which the full-size
     * routes cannot claim.
     */
    public function test_the_thumbnail_may_be_cached_for_a_year(): void
    {
        $meal = $this->mealWithPlates(1);
        $photo = $this->notNull($meal->photos()->first());

        $this->actingAs(User::factory()->create());

        $this->get(route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $photo->client_id]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    }

    /**
     * The meal-level route names a POSITION, not a photograph — delete the
     * starter and it is a different picture — so it must revalidate every time.
     */
    public function test_the_first_plate_route_always_revalidates(): void
    {
        $meal = $this->mealWithPlates(2);

        $this->actingAs(User::factory()->create());

        $this->get(route('meals.photo.show', ['meal' => $meal->uuid]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function test_a_matching_validator_costs_no_bytes(): void
    {
        $meal = $this->mealWithPlates(1);
        $photo = $this->notNull($meal->photos()->first());

        $this->actingAs(User::factory()->create());

        $url = route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $photo->client_id]);

        $etag = $this->get($url)->assertOk()->headers->get('ETag');

        self::assertNotNull($etag);

        // Photographs used to come back in full on every reload. Now a 304.
        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_a_guest_gets_nothing(): void
    {
        $meal = $this->mealWithPlates(1);
        $photo = $this->notNull($meal->photos()->first());

        $this->getJson(route('meals.photo.thumb', [
            'meal'  => $meal->uuid,
            'photo' => $photo->client_id,
        ]))->assertUnauthorized();
    }

    public function test_a_plate_from_another_meal_is_not_reachable_through_this_one(): void
    {
        $mine = $this->mealWithPlates(1);
        $other = $this->mealWithPlates(1);

        $this->actingAs(User::factory()->create());

        $this->get(route('meals.photo.thumb', [
            'meal'  => $mine->uuid,
            'photo' => $this->notNull($other->photos()->first())->client_id,
        ]))->assertNotFound();
    }

    /**
     * A plate from before thumbnails existed, or one whose thumbnail went
     * missing, still draws a card: serving a sharper picture than was asked for
     * beats a 404 and a hole, the exact symptom this change exists to remove.
     */
    public function test_a_missing_thumbnail_falls_back_to_whatever_there_is(): void
    {
        $meal = $this->mealWithPlates(1);
        $photo = $this->notNull($meal->photos()->first());

        Storage::disk('meal-photos')->delete($photo->thumb_path);

        $this->actingAs(User::factory()->create());

        $response = $this->get(route('meals.photo.thumb', [
            'meal'  => $meal->uuid,
            'photo' => $photo->client_id,
        ]));

        $response->assertOk();

        self::assertSame(Storage::disk('meal-photos')->get($photo->path), $response->streamedContent());

        // And the long cache goes with the thumbnail: `path` is the mutable one.
        $response->assertHeader('Cache-Control', 'max-age=300, private');
    }

    // --- Which plate is "first" --------------------------------------------

    /**
     * `photos()->first()` is the first plate only because the relation is
     * ordered; unordered it is Postgres heap order, which moves under any UPDATE
     * — how the card's picture changed after a maintenance command touched a
     * column that has nothing to do with pictures.
     */
    public function test_the_first_plate_survives_an_update_that_moves_the_heap(): void
    {
        $meal = $this->mealWithPlates(3);

        $expected = $this->notNull($meal->photos()->first())->client_id;

        // Rewrite the earliest plate's row: in Postgres an UPDATE writes a NEW
        // tuple at the end of the heap, so a sequential scan returns it LAST.
        MealPhoto::query()->where('client_id', $expected)->update(['model_notes' => 'moved']);

        $refreshedFirst = $this->notNull($this->notNull($meal->fresh())->photos()->first());
        self::assertSame($expected, $refreshedFirst->client_id);

        $props = (new DailyView)->props(self::DATE);

        self::assertSame(
            route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $expected]),
            $props['meals'][0]['photoUrl'],
        );

        self::assertSame([0, 1, 2], array_column($props['meals'][0]['photos'], 'position'));
    }

    // ---

    private function mealWithPlates(int $count): Meal
    {
        $meal = Meal::factory()->create(['eaten_at' => self::DATE.' 19:00:00']);

        for ($position = 0; $position < $count; $position++) {
            $photo = MealPhoto::factory()->for($meal)->atPosition($position)->create();

            Storage::disk('meal-photos')->put($photo->path, TestImage::jpeg(768, 1024));
            Storage::disk('meal-photos')->put($photo->thumb_path, TestImage::jpeg(192, 256));
        }

        return $meal->refresh();
    }
}
