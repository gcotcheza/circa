<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use Illuminate\Http\UploadedFile;
use App\Enums\VisionRequestStatus;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The upload half: what happens between the shutter and the queue. The two
 * properties defended here cost real money or real privacy if they break:
 *
 *   - ONE ANALYSIS PER IDEMPOTENCY KEY. A double-tap, a flaky connection, an
 *     offline queue replaying yesterday's upload — each must produce one
 *     `vision_requests` row and one API call.
 *   - NO EXIF LEAVES THE SERVER. An iPhone photo of a plate carries the GPS
 *     coordinates of the kitchen. The browser strips it; this asserts the server
 *     does too, because trusting the browser is not a guarantee.
 */
final class PhotoUploadTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_every_photo_route_requires_a_session(): void
    {
        auth()->logout();

        $meal = Meal::factory()->proposed()->create();

        // The /api/* routes answer a guest with 401 JSON: fetch() would follow a
        // 302 and hand back the login page's HTML as though it were the answer.
        $photo = MealPhoto::factory()->for($meal)->create();

        $this->postJson('/api/meals/photo', [])->assertUnauthorized();
        $this->getJson('/api/meals/'.$meal->uuid.'/vision')->assertUnauthorized();
        $this->postJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id.'/vision', [])->assertUnauthorized();
        $this->deleteJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id)->assertUnauthorized();
        $this->getJson('/api/meals/'.$meal->uuid.'/photo')->assertUnauthorized();
        $this->getJson('/api/meals/'.$meal->uuid.'/photos/'.$photo->client_id)->assertUnauthorized();

        /*
         * Confirming is an ordinary Inertia form, so a BROWSER-shaped guest gets
         * the login page like every other meal route — an Inertia visit sends
         * `Accept: text/html, application/xhtml+xml`, not JSON.
         */
        $this->put('/meals/'.$meal->uuid.'/proposal', [])->assertRedirect(route('login'));

        /*
         * A caller explicitly asking for JSON gets 401 instead (step 8): the
         * queue replays this with fetch, and a 302 followed to the login page
         * comes back as a 200, so a signed-out replay would read as sent and the
         * confirmation would be dropped from the queue. See bootstrap/app.php.
         */
        $this->putJson('/meals/'.$meal->uuid.'/proposal', [])->assertUnauthorized();
    }

    public function test_an_upload_creates_an_analyzing_meal_a_claimed_request_and_a_job(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))
            ->assertStatus(202)
            ->assertJsonPath('meal.status', 'analyzing');

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(MealStatus::Analyzing, $meal->status);
        self::assertSame('photo', $meal->source->value);

        // A plate is a row now: `meals.photo_path` is deprecated, never written.
        self::assertNull($meal->photo_path);

        $photo = $meal->photos()->sole();

        self::assertSame(0, $photo->position);
        self::assertStringStartsWith('originals/', $photo->path);
        self::assertStringStartsWith('thumbs/', $photo->thumb_path);
        self::assertSame(64, strlen((string) $photo->sha256));

        $request = VisionRequest::query()->firstOrFail();

        // Which plate this call is about: without it, a re-analysis cannot know
        // which proposal it replaces.
        self::assertSame($photo->id, $request->meal_photo_id);

        // `pending` is the claim: the job refuses any row that is not pending,
        // which is what makes an at-least-once queue safe.
        self::assertSame(VisionRequestStatus::Pending, $request->status);
        self::assertSame('claude-opus-5', $request->model);
        self::assertSame('v3-photo', $request->prompt_version);
        self::assertSame(64, strlen((string) $request->image_sha256));

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    public function test_the_same_idempotency_key_buys_exactly_one_analysis(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, $key))->assertStatus(202);

        // The double-tap, the queue flushing twice, or Send pressed again
        // because the spinner looked stuck.
        $this->post('/api/meals/photo', $this->payload($uuid, $key))->assertStatus(200);

        self::assertSame(1, VisionRequest::query()->count());
        self::assertSame(1, Meal::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    public function test_a_double_upload_never_reaches_the_model_twice(): void
    {
        // No Queue::fake: the sync connection runs the job inline, so this counts
        // what the ANALYZER saw rather than what was queued.
        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, $key))->assertStatus(202);
        $this->post('/api/meals/photo', $this->payload($uuid, $key))->assertStatus(200);

        self::assertSame(1, $this->analyzer->callCount());
    }

    public function test_the_stored_photo_has_no_exif_and_is_downscaled(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        $original = TestImage::jpegWithExif(2400, 1800);

        // The fixture really carries what is about to be asserted gone —
        // otherwise this passes against a broken pipeline.
        self::assertStringContainsString('GPS-COORDINATES-OF-A-KITCHEN', $original);
        self::assertStringContainsString("Exif\0\0", $original);

        $this->post('/api/meals/photo', $this->payload($uuid, image: $original))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $photo = $meal->photos()->sole();

        $stored = Storage::disk('meal-photos')->get($photo->path);
        self::assertIsString($stored);

        self::assertStringNotContainsString('GPS-COORDINATES-OF-A-KITCHEN', $stored);
        self::assertStringNotContainsString("Exif\0\0", $stored);

        // Re-encoded to the ceiling, not merely accepted at 2400 px.
        $storedSize = getimagesizefromstring($stored);
        self::assertIsArray($storedSize);
        [$width, $height] = $storedSize;
        self::assertSame(1024, max($width, $height));
        self::assertSame(768, min($width, $height));

        // And the thumbnail that outlives it.
        $thumb = Storage::disk('meal-photos')->get($photo->thumb_path);
        self::assertIsString($thumb);

        $thumbSize = getimagesizefromstring($thumb);
        self::assertIsArray($thumbSize);
        [$thumbWidth] = $thumbSize;
        self::assertSame(256, $thumbWidth);
    }

    public function test_a_png_is_accepted_and_becomes_a_jpeg(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            ...$this->payload($uuid),
            'photo' => UploadedFile::fake()->createWithContent('shot.png', TestImage::png()),
        ])->assertStatus(202);

        $photo = Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->sole();

        self::assertStringEndsWith('.jpg', $photo->path);

        $pngBytes = Storage::disk('meal-photos')->get($photo->path);
        self::assertIsString($pngBytes);
        $pngSize = getimagesizefromstring($pngBytes);
        self::assertIsArray($pngSize);

        self::assertSame('image/jpeg', $pngSize['mime']);
    }

    public function test_a_file_that_is_not_an_image_is_rejected_before_anything_is_written(): void
    {
        Queue::fake();

        $this->post('/api/meals/photo', [
            ...$this->payload((string) Str::uuid()),
            'photo' => UploadedFile::fake()->createWithContent('notes.txt', 'this is not a plate of food'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');

        self::assertSame(0, Meal::query()->count());
        self::assertSame(0, VisionRequest::query()->count());

        Queue::assertNothingPushed();
    }

    /**
     * "And then I had pudding."
     *
     * A second plate on an ALREADY CONFIRMED meal used to be a 409, and had to
     * be: with one photograph per meal it could only mean "replace what you
     * already agreed to". The meal stays `confirmed` throughout — a day's total
     * dropping out on its own because a dessert is being analysed is the failure
     * this state rule prevents.
     */
    public function test_a_second_plate_can_be_added_to_an_already_confirmed_meal(): void
    {
        Queue::fake();

        $meal = Meal::factory()->create(['status' => MealStatus::Confirmed]);

        $this->post('/api/meals/photo', $this->payload($meal->uuid))
            ->assertStatus(202)
            ->assertJsonPath('meal.status', 'confirmed')
            ->assertJsonPath('meal.pending', 1);

        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);
        self::assertSame(1, $meal->photos()->count());
        self::assertSame(1, VisionRequest::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    /**
     * Three plates of one dinner are three rows, not one uploaded thrice. The
     * meal uuid is deliberately the same across all three, so without
     * `client_id` the server could not tell a second plate from a first resent.
     */
    public function test_plates_accumulate_on_one_meal_in_upload_order(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        foreach (['first', 'second', 'third'] as $plate) {
            $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);
        }

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame([0, 1, 2], $meal->photos()->pluck('position')->all());
        self::assertSame(3, VisionRequest::query()->count());
        self::assertSame(1, Meal::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 3);
    }

    /**
     * The same plate arriving twice writes one row. Two flushes can carry two
     * analysis keys, so the key alone cannot catch this — the photo's id does.
     */
    public function test_the_same_client_id_is_one_plate_however_many_times_it_arrives(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();
        $clientId = (string) Str::uuid();

        $this->post('/api/meals/photo', [...$this->payload($uuid), 'client_id' => $clientId])->assertStatus(202);

        // New analysis key, same photograph.
        $this->post('/api/meals/photo', [...$this->payload($uuid), 'client_id' => $clientId])->assertStatus(200);

        self::assertSame(1, Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->count());
        self::assertSame(1, VisionRequest::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    /**
     * A photo queued by the PREVIOUS version carries no `client_id`. Rejecting it
     * would block that action forever and lose a meal somebody watched go into
     * the app. The analysis key is unique per capture, so it stands in.
     */
    public function test_an_upload_without_a_client_id_still_lands(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();

        $payload = $this->payload($uuid, $key);
        unset($payload['client_id']);

        $this->post('/api/meals/photo', $payload)->assertStatus(202);

        self::assertSame(1, Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->count());
    }

    public function test_the_photo_is_served_only_to_the_signed_in_user(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);

        $photo = Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->sole();

        // The meal-level route keeps its URL and serves the FIRST plate, so a
        // thumbnail already in the PWA's cache still resolves.
        $this->get('/api/meals/'.$uuid.'/photo')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->get('/api/meals/'.$uuid.'/photos/'.$photo->client_id)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        auth()->logout();

        $this->getJson('/api/meals/'.$uuid.'/photo')->assertUnauthorized();
        $this->getJson('/api/meals/'.$uuid.'/photos/'.$photo->client_id)->assertUnauthorized();
    }

    /**
     * The offline queue replays plates in the order they were taken:
     * `lib/idb.all()` sorts by `createdAt`, `flush()` sends oldest first, the
     * server appends, and a re-flush is caught by `client_id`. A replay that
     * renumbered would move "photo 2" onto a different photograph between two
     * page loads, and the review sheet would post one plate's edits onto
     * another's items.
     */
    public function test_a_replayed_flush_does_not_renumber_the_plates(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        $ids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        foreach ($ids as $clientId) {
            $this->post('/api/meals/photo', [...$this->payload($uuid), 'client_id' => $clientId])->assertStatus(202);
        }

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        $before = $meal->photos()->pluck('position', 'client_id')->all();

        // The whole queue flushes again: a second tab, or `online` firing while
        // a visibility flush was mid-request.
        foreach ($ids as $clientId) {
            $this->post('/api/meals/photo', [...$this->payload($uuid), 'client_id' => $clientId])->assertStatus(200);
        }

        self::assertSame($before, $meal->photos()->pluck('position', 'client_id')->all());
        self::assertSame(3, $meal->photos()->count());
        self::assertSame(3, VisionRequest::query()->count());
    }

    /** @return array<string, mixed> */
    private function payload(string $uuid, ?string $key = null, ?string $image = null): array
    {
        return [
            'uuid'            => $uuid,
            'client_id'       => (string) Str::uuid(),
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'date'            => '2026-08-07',
            'time'            => '13:20',
            'photo'           => UploadedFile::fake()->createWithContent(
                'meal.jpg',
                $image ?? TestImage::jpeg(1200, 900)
            ),
        ];
    }
}
