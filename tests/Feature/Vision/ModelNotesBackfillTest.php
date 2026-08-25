<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\StoredAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The rule the `model_notes` migration backfills by. The migration runs once and
 * cannot be asserted on afterwards, so what is pinned is its decision: a note
 * leaves `meals.notes` only when the stored answer PROVES the model wrote it.
 * Anything the user typed, or edited before confirming, fails that comparison
 * and stays theirs — the difference between a migration and a data loss.
 */
final class ModelNotesBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_answer_identifies_the_note_the_model_wrote(): void
    {
        $note = 'Portion judged against a 27 cm plate.';

        $meal = Meal::factory()->create(['notes' => $note]);

        $answer = StoredAnswer::fromRawResponse((array) $this->request($meal, $note)->raw_response);

        self::assertNotNull($answer);
        self::assertSame($note, $answer->notes);
        self::assertSame($note, $meal->notes, 'the fixture is the pre-migration state');
    }

    public function test_a_note_the_user_edited_does_not_match_and_is_not_moved(): void
    {
        $meal = Meal::factory()->create(['notes' => 'Portion judged against a 27 cm plate. Actually it was a bowl.']);

        $answer = StoredAnswer::fromRawResponse((array) $this->request($meal, 'Portion judged against a 27 cm plate.')->raw_response);

        self::assertNotNull($answer);
        self::assertNotSame($meal->notes, $answer->notes);
    }

    public function test_a_response_with_no_readable_answer_moves_nothing(): void
    {
        $meal = Meal::factory()->create(['notes' => 'Ate it at my desk.']);

        $request = VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'request_kind'    => VisionRequestKind::Photo,
            'idempotency_key' => 'refusal-key',
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v1',
            'status'          => VisionRequestStatus::Failed,
            'raw_response'    => ['stop_reason' => 'refusal', 'content' => []],
        ]);

        self::assertNull(StoredAnswer::fromRawResponse((array) $request->raw_response));
    }

    private function request(Meal $meal, string $notes): VisionRequest
    {
        return VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'request_kind'    => VisionRequestKind::Photo,
            'idempotency_key' => 'backfill-'.$meal->id,
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v1',
            'status'          => VisionRequestStatus::Succeeded,
            'raw_response'    => [
                'content' => [
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => json_encode(['items' => [], 'notes' => $notes])],
                ],
            ],
        ]);
    }
}
