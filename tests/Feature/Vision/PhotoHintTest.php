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
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "It was 3 eggs and 120 g of drained tuna."
 *
 * A photograph cannot say the tuna was drained or that three eggs sit under the
 * cheese; the person holding the phone can, and had nowhere to say so, so every
 * gram came back as a range. The upload gains ONE optional line: skipping it
 * changes nothing (no block sent, no column written, the same question asked),
 * and filling it in makes what the user says the food IS, plus any amount they
 * counted or weighed, authoritative.
 *
 * Four properties are defended here. It is STORED PER PLATE, because it is
 * editable an hour after dinner and so cannot live only in the request that
 * carried it, and because the eggs' note must not reach the pudding. It is
 * RECORDED ON THE AUDIT ROW, because `image_sha256` was once the entire input
 * and a row holding only the sha256 would say two analyses of one photograph
 * asked the same question, making a hinted answer look like the model changing
 * its mind. THE JOB SENDS WHAT THE ROW SAYS, NOT WHAT THE PLATE SAYS: they
 * agree when the row is claimed but can differ by the time the job runs, and
 * the row has to describe the call that was made. EDITING IT NEVER RE-RUNS
 * ANYTHING, because asking again costs money and replaces numbers the user may
 * have edited by hand.
 *
 * The PROMPT half — own delimited block, absent when there is no note, never
 * interpolated into the instruction — is asserted one layer lower, on the
 * actual request body, in VisionRequestShapeTest.
 */
final class PhotoHintTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const HINT = '3 eggs, 120 g drained tuna';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    // --- Capture -----------------------------------------------------------

    public function test_the_hint_is_stored_on_the_plate_recorded_on_the_audit_row_and_sent_to_the_model(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, hint: self::HINT))->assertStatus(202);

        $photo = Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->sole();

        self::assertSame(self::HINT, $photo->hint);

        // `prompt_version` + `input_payload` + `raw_response` reproduce the
        // call; without the middle one a hinted answer is unexplainable later.
        $request = VisionRequest::query()->sole();

        self::assertSame('photo', $request->request_kind->value);
        self::assertSame(['hint' => self::HINT], $request->input_payload);

        // And it reached the model, not just the column — the user paid for it.
        self::assertSame(self::HINT, $this->analyzer->lastHint());
    }

    public function test_an_upload_with_no_hint_is_exactly_the_upload_it_always_was(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);

        $photo = Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->sole();

        self::assertNull($photo->hint);

        // NULL, not `{"hint": null}`: an unhinted row reads exactly as the rows
        // written before this column reached the photo path.
        self::assertNull(VisionRequest::query()->sole()->input_payload);

        self::assertNull($this->analyzer->lastHint());
    }

    public function test_a_box_holding_only_whitespace_is_the_same_as_an_empty_one(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, hint: "   \n  "))->assertStatus(202);

        // `''` would render a block with no words in it and stop
        // `hint IS NOT NULL` meaning "the user told us something".
        self::assertNull(Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->sole()->hint);
        self::assertNull(VisionRequest::query()->sole()->input_payload);
    }

    public function test_a_hint_longer_than_the_column_allows_is_a_422_rather_than_a_truncation(): void
    {
        $this->postJson('/api/meals/photo', $this->payload((string) Str::uuid(), hint: str_repeat('a', 501)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hint');

        self::assertSame(0, Meal::query()->count());
    }

    /** A note belongs to its own plate, not to the meal. */
    public function test_each_course_carries_its_own_note(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, hint: self::HINT))->assertStatus(202);
        $this->post('/api/meals/photo', $this->payload($uuid, hint: 'one scoop, no cone'))->assertStatus(202);

        $photos = Meal::query()->where('uuid', $uuid)->firstOrFail()->photos()->orderBy('position')->get();

        self::assertSame(self::HINT, $this->notNull($photos->get(0))->hint);
        self::assertSame('one scoop, no cone', $this->notNull($photos->get(1))->hint);

        self::assertSame('one scoop, no cone', $this->analyzer->lastHint());
    }

    // --- Edit + re-analyse ---------------------------------------------------

    public function test_re_analysing_sends_the_edited_note_and_records_it_on_the_new_row(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $photo = $meal->photos()->sole();

        // The first answer was given without a note.
        self::assertNull($this->analyzer->lastHint());

        $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            'hint'            => self::HINT,
        ])->assertStatus(202);

        // Saved on the plate, so the sheet still shows it after the reload…
        self::assertSame(self::HINT, $photo->refresh()->hint);

        // …recorded on the NEW row, so two answers to one photograph stay
        // distinguishable afterwards…
        $rows = VisionRequest::query()->orderBy('id')->get();

        self::assertCount(2, $rows);
        self::assertNull($this->notNull($rows->get(0))->input_payload);
        self::assertSame(['hint' => self::HINT], $this->notNull($rows->get(1))->input_payload);

        // …and it is what the second call was actually told.
        self::assertSame(self::HINT, $this->analyzer->lastHint());
        self::assertSame(2, $this->analyzer->callCount());
    }

    public function test_a_re_analysis_that_says_nothing_about_the_note_leaves_it_alone(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        // No `hint` key at all (an older client, or a re-analysis from
        // somewhere that does not edit it) means "the sheet did not say" —
        // wiping the note on that basis would lose the user's words.
        $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        self::assertSame(self::HINT, $photo->refresh()->hint);
        self::assertSame(self::HINT, $this->analyzer->lastHint());
        self::assertSame(['hint' => self::HINT], VisionRequest::query()->latest('id')->firstOrFail()->input_payload);
    }

    public function test_clearing_the_box_deletes_the_note_and_the_next_call_is_told_nothing(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        // An explicit empty string IS a mention: the user rubbed it out.
        $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            'hint'            => '',
        ])->assertStatus(202);

        self::assertNull($photo->refresh()->hint);
        self::assertNull($this->analyzer->lastHint());
        self::assertNull(VisionRequest::query()->latest('id')->firstOrFail()->input_payload);
    }

    /**
     * The row is the record of the question, so the row is the source of it.
     * The note stays editable while an analysis is in flight; a job reading the
     * PLATE would let a typo fixed thirty seconds after tapping change what a
     * completed `vision_requests` row claims to have asked, leaving the answer
     * stored beside it an answer to nothing.
     */
    public function test_the_job_sends_what_the_audit_row_says_even_if_the_plate_has_moved_on(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        /*
         * A REAL claim, with the job held back so there is a window to type in
         * — this test is about what happens BETWEEN the claim and the run, and
         * the `sync` queue would otherwise run the job inside the request.
         * Faking the queue records the dispatch instead; restoring the real
         * manager is what lets `dispatchSync` below execute, since a faked
         * queue swallows that too (it routes to the `sync` CONNECTION rather
         * than bypassing the queue).
         */
        $queue = Queue::getFacadeRoot();

        Queue::fake();

        $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            'hint'            => self::HINT,
        ])->assertStatus(202);

        Queue::swap($queue);

        $claimed = VisionRequest::query()->latest('id')->firstOrFail();

        // The user keeps typing while the job sits in the queue.
        $photo->forceFill(['hint' => 'actually it was salmon'])->saveQuietly();

        AnalyzeMealPhoto::dispatchSync($claimed->id);

        self::assertSame(self::HINT, $this->analyzer->lastHint());

        // And the row still says what it asked; nothing rewrote it.
        self::assertSame(['hint' => self::HINT], $claimed->refresh()->input_payload);
    }

    // --- Confirm -----------------------------------------------------------

    public function test_confirming_saves_the_note_without_asking_the_model_anything(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $photo = $meal->photos()->sole();

        $this->put("/meals/{$meal->uuid}/proposal", $this->confirmPayload($photo, hint: self::HINT))
            ->assertRedirect();

        // Kept: a note from somebody who then decided the numbers were close
        // enough is still true about the plate, and retyping it to ask for a
        // fresh answer would be a tax.
        self::assertSame(self::HINT, $photo->refresh()->hint);

        // Not a penny spent: confirming agrees to what is on screen.
        self::assertSame(1, $this->analyzer->callCount());
        self::assertSame(1, VisionRequest::query()->count());
        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);
    }

    public function test_a_confirm_that_never_mentions_the_note_leaves_it_where_it_was(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        // Exactly what a confirm queued by the previous app version flushes as.
        $payload = $this->confirmPayload($photo);

        unset($payload['hint']);

        $this->put("/meals/{$meal->uuid}/proposal", $payload)->assertRedirect();

        self::assertSame(self::HINT, $photo->refresh()->hint);
    }

    // --- The way in and out of the app ---------------------------------------

    public function test_the_note_reaches_the_review_sheet_as_a_prop_on_its_own_plate(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        $date = $meal->eaten_at->setTimezone((string) config('health.timezone'))->toDateString();

        $this->get('/?date='.$date)
            ->assertInertia(fn ($page) => $page
                ->where('meals.0.photos.0.clientId', $photo->client_id)
                ->where('meals.0.photos.0.hint', self::HINT)
            );
    }

    /**
     * A CONFIRMED meal still hands over its plates, which is what makes the
     * note reachable at all.
     *
     * Everything plate-level — the note, "Analyse this photo again", "Remove
     * this photo" — lives on the review sheet, and `Day.vue::openMeal` sends a
     * confirmed meal to the EDIT sheet, which rendered items and nothing else.
     * So the note shipped one release earlier was unreachable on precisely the
     * meals the user knows most about: "It was three eggs" had no answer. The
     * edit sheet now draws a strip of the meal's plates and hands the tap back,
     * anchored to the photograph tapped — a front-end change standing on this
     * payload: a thumbnail, a client id, the note (so a plate that carries one
     * says so without being opened) and the state. Without `photos` the strip
     * renders empty and the hole is still there.
     */
    public function test_a_confirmed_meal_still_carries_every_plate_for_the_edit_sheets_strip(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        $this->put("/meals/{$meal->uuid}/proposal", $this->confirmPayload($photo, hint: self::HINT))
            ->assertRedirect();

        $meal->refresh();

        self::assertSame(MealStatus::Confirmed, $meal->status);

        $date = $meal->eaten_at->setTimezone((string) config('health.timezone'))->toDateString();

        $this->get('/?date='.$date)
            ->assertInertia(fn ($page) => $page
                ->where('meals.0.status', 'confirmed')
                ->where('meals.0.photos.0.clientId', $photo->client_id)
                ->where('meals.0.photos.0.hint', self::HINT)
                ->where('meals.0.photos.0.state', 'settled')
                ->where('meals.0.photos.0.position', 0)
                ->where('meals.0.photos.0.thumbUrl', route('meals.photo.thumb', [
                    'meal'  => $meal->uuid,
                    'photo' => $photo->client_id,
                ]))
                // Gates the strip's re-analyse affordance; a plate whose
                // original retention has taken must not offer it.
                ->where('meals.0.photos.0.canReanalyze', true)
            );
    }

    /**
     * The offline queue replays the upload as multipart with the note among the
     * fields, and twice must still cost one plate and one call. `lib/queue.js`
     * appends every non-null entry of `payload` to a FormData, so this is
     * byte-for-byte the request the phone would have sent — the property the
     * whole queue rests on.
     */
    public function test_a_replayed_offline_upload_carries_its_note_and_stays_idempotent(): void
    {
        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();
        $clientId = (string) Str::uuid();

        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        $replay = fn (): TestResponse => $this->withHeaders($headers)->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'client_id'       => $clientId,
            'idempotency_key' => $key,
            'date'            => '2026-08-07',
            'time'            => '13:20',
            'hint'            => self::HINT,
            'photo'           => UploadedFile::fake()->createWithContent('meal.jpg', TestImage::jpeg(1200, 900)),
        ]);

        $replay()->assertStatus(202);

        // The double flush: two tabs, or `online` firing mid-request.
        $replay()->assertStatus(200);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(1, $meal->photos()->count());
        self::assertSame(self::HINT, $meal->photos()->sole()->hint);

        // One row, one call, one invoice.
        self::assertSame(1, VisionRequest::query()->count());
        self::assertSame(1, $this->analyzer->callCount());
    }

    public function test_a_guest_can_neither_write_a_note_nor_read_one(): void
    {
        [$meal, $photo] = $this->hintedPlate();

        auth()->logout();

        $this->postJson('/api/meals/photo', ['hint' => self::HINT])->assertUnauthorized();

        $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            'hint'            => 'let me in',
        ])->assertUnauthorized();

        $this->putJson("/meals/{$meal->uuid}/proposal", $this->confirmPayload($photo, hint: 'let me in'))
            ->assertUnauthorized();

        // Nothing was written by any of them.
        self::assertSame(self::HINT, $photo->refresh()->hint);
    }

    // -----------------------------------------------------------------------

    /**
     * An uploaded, analysed, hinted plate — where most of these start.
     *
     * @return array{0: Meal, 1: MealPhoto}
     */
    private function hintedPlate(): array
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, hint: self::HINT))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        return [$meal, $meal->photos()->sole()];
    }

    /** @return array<string, mixed> */
    private function payload(string $uuid, ?string $hint = null): array
    {
        return [
            'uuid'            => $uuid,
            'client_id'       => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-08-07',
            'time'            => '13:20',
            ...($hint === null ? [] : ['hint' => $hint]),
            'photo' => UploadedFile::fake()->createWithContent('meal.jpg', TestImage::jpeg(1200, 900)),
        ];
    }

    /** @return array<string, mixed> */
    private function confirmPayload(MealPhoto $photo, ?string $hint = null): array
    {
        return [
            'date'           => '2026-08-07',
            'time'           => '13:20',
            'meal_type'      => 'lunch',
            'client_id'      => $photo->client_id,
            'share_fraction' => 1,
            'hint'           => $hint,
            'items'          => [[
                'name'          => 'White rice',
                'portion_g_min' => 120, 'portion_g_max' => 200,
                'kcal_min'      => 156, 'kcal_max' => 260,
                'protein_g_min' => 3.24, 'protein_g_max' => 5.4,
                'carbs_g_min'   => 33.6, 'carbs_g_max' => 56,
                'fat_g_min'     => 0.36, 'fat_g_max' => 0.6,
            ]],
        ];
    }
}
