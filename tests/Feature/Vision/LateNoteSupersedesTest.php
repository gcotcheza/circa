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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use App\Enums\VisionRequestStatus;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The note typed while the first analysis is still running.
 *
 * THE ORDER THINGS ACTUALLY HAPPEN IN. The capture sheet assumed a note typed
 * BEFORE the shutter; the logs put every upload 13-23 seconds after a cold
 * launch, so the picture goes first and the sentence that would most improve
 * the answer — "3 eggs, 120 g drained tuna" — is typed while it is already
 * being analysed. Every initial `vision_requests` row for a photographed meal
 * has an empty `input_payload`. PhotoCapture now sends that leftover note at
 * Done, inside the ten to thirty seconds the first analysis takes — which the
 * re-analyse endpoint refused with a 409.
 *
 * THE RULE. A re-ask that CHANGES THE QUESTION supersedes; one that REPEATS it
 * waits. For a repeat the 409 is right double-spend protection: same image,
 * same silence, same answer, second invoice. But a note the running call was
 * never given is a question it cannot answer, and making the user wait to be
 * told what they already know is wrong is how the feature lost the note.
 *
 * THE CORRECTNESS HALF. Two calls in flight against one plate and NOTHING
 * ORDERS THEM: the first can easily be the slow one, and an older answer
 * applied on arrival would overwrite the hinted one in front of the user. So
 * the newest row owns the plate's items and an overtaken answer is RECORDED
 * AND NOT APPLIED — status, tokens, latency and verbatim response written
 * because the call was paid for, items not. Same rule the review sheet reads
 * state by (MealPhoto::latestVisionRequest, MAX(id)), so they cannot disagree.
 */
final class LateNoteSupersedesTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const HINT = '3 eggs, 120 g drained tuna';

    /** The real queue manager, kept so a test can put it back and run a job. */
    private mixed $queue = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    // Who gets past an analysis in flight

    public function test_a_note_the_running_call_was_not_given_is_let_past_and_asked_for_its_own_answer(): void
    {
        [$meal, $photo] = $this->analysing();

        // The state the old rule refused outright.
        self::assertSame('analyzing', $photo->entryState());

        $this->reanalyze($meal, $photo, hint: self::HINT)->assertStatus(202);

        $rows = VisionRequest::query()->orderBy('id')->get()->all();

        self::assertCount(2, $rows);

        // The record of a question that was asked without a note, and stays that.
        self::assertNull($rows[0]->input_payload);
        self::assertSame(VisionRequestStatus::Pending, $rows[0]->status);

        // The second is the one carrying what the user actually knows.
        self::assertSame(['hint' => self::HINT], $rows[1]->input_payload);
        self::assertSame(VisionRequestStatus::Pending, $rows[1]->status);

        // Two dispatches: the upload's and this one's.
        Queue::assertPushed(AnalyzeMealPhoto::class, 2);

        // Saved on the plate too, so the review sheet shows it after the reload.
        self::assertSame(self::HINT, $photo->refresh()->hint);
    }

    public function test_a_re_ask_with_nothing_to_add_still_waits_for_the_answer_on_its_way(): void
    {
        [$meal, $photo] = $this->analysing();

        // No `hint` key: the same silent question already being answered.
        $this->reanalyze($meal, $photo)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        self::assertSame(1, VisionRequest::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    public function test_the_same_note_twice_is_the_same_question_twice_and_buys_nothing(): void
    {
        // Uploaded WITH the note, so the call in flight was already told: a
        // double tap must not buy a second invoice for the same question.
        [$meal, $photo] = $this->analysing(hint: self::HINT);

        $this->reanalyze($meal, $photo, hint: self::HINT)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        // Trimmed before it is compared, exactly as before it is stored.
        $this->reanalyze($meal, $photo, hint: '  '.self::HINT.' ')
            ->assertStatus(409);

        self::assertSame(1, VisionRequest::query()->count());

        Queue::assertPushed(AnalyzeMealPhoto::class, 1);
    }

    public function test_a_second_thought_gets_through_even_while_the_first_note_is_being_answered(): void
    {
        [$meal, $photo] = $this->analysing(hint: self::HINT);

        // A different sentence is a different question, whatever came before it.
        $this->reanalyze($meal, $photo, hint: 'actually it was salmon, about 150 g')
            ->assertStatus(202);

        self::assertSame(
            ['hint' => 'actually it was salmon, about 150 g'],
            VisionRequest::query()->latest('id')->firstOrFail()->input_payload
        );
    }

    /**
     * Clearing the box is a deletion, not a question: it is stored as a fact
     * about the plate, but the question it leaves behind is the silent one
     * already being answered, so it buys no second call.
     */
    public function test_an_emptied_note_box_does_not_buy_a_second_call(): void
    {
        [$meal, $photo] = $this->analysing(hint: self::HINT);

        $this->reanalyze($meal, $photo, hint: '')->assertStatus(409);

        self::assertSame(1, VisionRequest::query()->count());
    }

    public function test_a_plate_that_has_finished_is_re_analysed_exactly_as_it_always_was(): void
    {
        // Nothing to supersede: the review sheet's "Analyse this photo again"
        // path, note or no note.
        [$meal, $photo] = $this->settled();

        $this->reanalyze($meal, $photo)->assertStatus(202);
        $this->reanalyze($meal, $photo, hint: self::HINT)->assertStatus(202);

        self::assertSame(3, VisionRequest::query()->count());
        self::assertSame(3, $this->analyzer->callCount());
        self::assertSame(self::HINT, $this->analyzer->lastHint());
    }

    // Two answers, in either order

    /**
     * THE ONE THAT MATTERS: the overtaken call finishes LAST — not exotic, just
     * what happens whenever the first call is the slow one. The old answer must
     * not land on top of the hinted one.
     */
    public function test_an_overtaken_answer_arriving_last_is_recorded_and_not_applied(): void
    {
        [$meal, $photo, $first] = $this->analysing();

        $this->reanalyze($meal, $photo, hint: self::HINT)->assertStatus(202);

        $second = VisionRequest::query()->latest('id')->firstOrFail();

        $this->runJobs();

        // The hinted call comes back first and writes the plate's answer.
        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);
        AnalyzeMealPhoto::dispatchSync($second->id);

        self::assertSame(self::HINT, $this->analyzer->lastHint());
        self::assertSame(['Grilled chicken breast'], $this->itemNames($meal));

        // …and the overtaken call answers a question nobody is asking any more.
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        AnalyzeMealPhoto::dispatchSync($first->id);

        // THE ITEMS ARE UNTOUCHED: still the answer given what the user told it.
        self::assertSame(['Grilled chicken breast'], $this->itemNames($meal));
        self::assertSame($second->id, $photo->refresh()->latestVisionRequest()->firstOrFail()->id);

        // AND THE ROW IS COMPLETE ANYWAY: the call was paid for, so ask, answer
        // and cost are on record — `vision:reproject` can rebuild from it.
        $first->refresh();

        self::assertSame(VisionRequestStatus::Succeeded, $first->status);
        self::assertNull($first->error);
        self::assertNotNull($first->raw_response);
        self::assertSame(1_800, $first->input_tokens);
        self::assertSame(420, $first->output_tokens);
        self::assertSame(5_400, $first->latency_ms);
    }

    /**
     * The other order, same answer: what makes a call overtaken is the newer
     * ROW existing, not the newer ANSWER arriving — it is written the moment
     * the re-ask is accepted, so the older job is superseded before either
     * finishes and is never applied. The plate goes from empty straight to the
     * hinted answer rather than flickering through a corrected one, and
     * `analyzing` on the day card stays true throughout. The cost: if the
     * hinted call then fails the plate has no items and the review sheet's "try
     * again" rather than an answer to a withdrawn question — the older answer
     * is still on its row, and `vision:reproject` is the way back.
     */
    public function test_the_overtaken_answer_is_not_applied_even_when_it_arrives_first(): void
    {
        [$meal, $photo, $first] = $this->analysing();

        $this->reanalyze($meal, $photo, hint: self::HINT)->assertStatus(202);

        $second = VisionRequest::query()->latest('id')->firstOrFail();

        $this->runJobs();

        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);
        AnalyzeMealPhoto::dispatchSync($first->id);

        // Nothing on the plate, and the meal still says it is working.
        self::assertSame([], $this->itemNames($meal));
        self::assertSame(MealStatus::Analyzing, $meal->refresh()->status);

        // The row is complete all the same: asked, answered, paid for.
        self::assertSame(VisionRequestStatus::Succeeded, $first->refresh()->status);
        self::assertNotNull($first->raw_response);

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);
        AnalyzeMealPhoto::dispatchSync($second->id);

        self::assertSame(['Grilled chicken breast'], $this->itemNames($meal));
        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
    }

    /**
     * An overtaken failure does not fail the dinner: the plate's state is the
     * newest row's, and that row is still working. Marking the meal `failed`
     * would put a red card on the day for as long as the real analysis takes
     * and then take it off again.
     */
    public function test_an_overtaken_failure_does_not_mark_the_meal_failed(): void
    {
        [$meal, $photo, $first] = $this->analysing();

        $this->reanalyze($meal, $photo, hint: self::HINT)->assertStatus(202);

        $second = VisionRequest::query()->latest('id')->firstOrFail();

        $this->runJobs();

        $this->analyzer->willFail('overloaded_error');
        AnalyzeMealPhoto::dispatchSync($first->id);

        // The failure is on the row, where per-plate failures live…
        self::assertSame(VisionRequestStatus::Failed, $first->refresh()->status);

        // …and not on the meal, which still has an analysis running.
        self::assertSame(MealStatus::Analyzing, $meal->refresh()->status);

        $this->analyzer->willPropose([FakeVisionAnalyzer::chicken()]);
        AnalyzeMealPhoto::dispatchSync($second->id);

        self::assertSame(['Grilled chicken breast'], $this->itemNames($meal));
        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
    }

    // -----------------------------------------------------------------------

    /**
     * A plate whose first analysis is still in flight — the state this file is
     * about. The queue is `sync` under test, so the upload's dispatch would run
     * the job before the request finished, leaving no window to type into;
     * faking it records the dispatch instead, and `runJobs()` puts the real
     * manager back.
     *
     * @return array{Meal, MealPhoto, VisionRequest}
     */
    private function analysing(?string $hint = null): array
    {
        $this->queue ??= Queue::getFacadeRoot();

        Queue::fake();

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid, $hint))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();
        $photo = $meal->photos()->sole();

        return [$meal, $photo, VisionRequest::query()->latest('id')->firstOrFail()];
    }

    /**
     * A plate past its analysis, waiting on the user.
     *
     * @return array{Meal, MealPhoto}
     */
    private function settled(): array
    {
        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', $this->payload($uuid))->assertStatus(202);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        return [$meal, $meal->photos()->sole()];
    }

    /**
     * Hand the real queue manager back: `dispatchSync` routes to the `sync`
     * CONNECTION rather than bypassing the queue, so a fake swallows it too and
     * every job this file runs would be a no-op that asserted nothing.
     */
    private function runJobs(): void
    {
        if ($this->queue !== null) {
            Queue::swap($this->queue);
        }
    }

    /** @return list<string> */
    private function itemNames(Meal $meal): array
    {
        return array_values(array_map(
            static fn (mixed $name): string => (string) $name,
            $meal->refresh()->items()->orderBy('id')->pluck('name')->all()
        ));
    }

    /** @return TestResponse<JsonResponse> */
    private function reanalyze(Meal $meal, MealPhoto $photo, ?string $hint = null): TestResponse
    {
        return $this->postJson("/api/meals/{$meal->uuid}/photos/{$photo->client_id}/vision", [
            'idempotency_key' => (string) Str::uuid(),
            ...($hint === null ? [] : ['hint' => $hint]),
        ]);
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
}
