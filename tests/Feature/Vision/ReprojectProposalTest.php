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
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `vision:reproject` — repairing a stored proposal without buying it again.
 *
 * A meal written under the old completion rule is on disk with a sixth item
 * that should never have existed: the user's own one-line description, at the
 * 100 g storage basis and 0 kcal, beside the five components the model split it
 * into. Fixing the rule fixes every FUTURE meal; this fixes the one already
 * written, from `raw_response` (what the model said) and `input_payload` (what
 * the user typed), the two columns step 9 added to make a stored request
 * replayable.
 *
 * No API call. A meal written under unchanged rules comes out byte-identical,
 * which is what makes the command safe to point at anything.
 */
final class ReprojectProposalTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const LINE = 'Full kwark 250g with 1 tablespoons of honey and 1 handful of blueberries';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(VisionAnalyzer::class, new FakeVisionAnalyzer);
    }

    public function test_it_drops_the_described_line_the_old_rule_left_behind(): void
    {
        $meal = $this->mealWrittenUnderTheOldRule();

        self::assertSame(3, $meal->items()->count());

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid, '--apply' => true])
            ->assertExitCode(0);

        $names = $meal->refresh()->items()->orderBy('id')->pluck('name')->all();

        self::assertSame(['full-fat kwark', '1 handful of blueberries'], $names);
        self::assertNotContains(self::LINE, $names);
    }

    public function test_it_keeps_the_numbers_the_user_confirmed(): void
    {
        $meal = $this->mealWrittenUnderTheOldRule();

        $before = $meal->items()->where('slug', 'full-fat-kwark')->sole()->only([
            'portion_g_min', 'portion_g_max', 'kcal_per_100g_min', 'kcal_per_100g_max', 'confirmed_at',
        ]);

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid, '--apply' => true])->assertExitCode(0);

        $after = $meal->refresh()->items()->where('slug', 'full-fat-kwark')->sole();

        self::assertSame((float) $before['portion_g_min'], (float) $after->portion_g_min);
        self::assertSame((float) $before['portion_g_max'], (float) $after->portion_g_max);
        self::assertSame((float) $before['kcal_per_100g_min'], (float) $after->kcal_per_100g_min);
        self::assertSame((float) $before['kcal_per_100g_max'], (float) $after->kcal_per_100g_max);

        // `confirmed_at` still records when the user agreed, not when this ran.
        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertEquals($before['confirmed_at'], $after->confirmed_at);
    }

    public function test_it_rehomes_the_model_note_without_touching_the_users(): void
    {
        $meal = $this->mealWrittenUnderTheOldRule(userNotes: 'Ate it at my desk.');

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid, '--apply' => true])->assertExitCode(0);

        $meal->refresh();

        self::assertSame('Ate it at my desk.', $meal->notes);
        self::assertStringStartsWith('Split the single line', (string) $meal->model_notes);
    }

    public function test_without_apply_it_writes_nothing(): void
    {
        $meal = $this->mealWrittenUnderTheOldRule();

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid])->assertExitCode(0);

        self::assertSame(3, $meal->refresh()->items()->count());
    }

    public function test_it_refuses_a_meal_it_cannot_rebuild(): void
    {
        $meal = Meal::factory()->create(['status' => MealStatus::Confirmed]);

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid])->assertExitCode(1);

        $this->runArtisan('vision:reproject', ['meal' => 'not-a-meal'])->assertExitCode(1);
    }

    /**
     * Exactly the state production was in: three items, the third the
     * description at 100 g / 0 kcal, the model's note in `meals.notes`.
     */
    private function mealWrittenUnderTheOldRule(?string $userNotes = null): Meal
    {
        $meal = Meal::factory()->create([
            'status'      => MealStatus::Confirmed,
            'eaten_at'    => '2026-08-07 07:21:00',
            'meal_type'   => 'breakfast',
            'notes'       => $userNotes,
            'model_notes' => null,
        ]);

        $confirmedAt = '2026-08-07 07:26:30';

        $meal->items()->create($this->row('full-fat kwark', 'full-fat-kwark', 250, 250, 96, 120, $confirmedAt));
        $meal->items()->create($this->row('1 handful of blueberries', '1-handful-of-blueberries', 45, 90, 48.889, 55.556, $confirmedAt));
        // The fabricated one.
        $meal->items()->create($this->row(self::LINE, str(self::LINE)->slug()->value(), 100, 100, 0, 0, $confirmedAt));

        VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'request_kind'    => VisionRequestKind::Text,
            'idempotency_key' => 'reproject-test-key',
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v1-text',
            'image_sha256'    => null,
            'status'          => VisionRequestStatus::Succeeded,
            'input_payload'   => [
                'time'      => '09:21',
                'meal_type' => 'breakfast',
                'notes'     => $userNotes,
                'items'     => [[
                    'name'            => self::LINE,
                    'basis'           => 'absolute',
                    'grams'           => null,
                    'values'          => ['kcal' => null, 'protein' => null, 'carbs' => null, 'fat' => null],
                    'food_product_id' => null,
                ]],
            ],
            // As the SDK serialises it: a thinking block first, then the
            // structured-output document. Reading past the first is what
            // StoredAnswer has to get right.
            'raw_response' => [
                'id'          => 'msg_reproject',
                'model'       => 'claude-opus-5',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                    ['type' => 'text', 'text' => json_encode([
                        'items' => [
                            [
                                'name'        => 'full-fat kwark', 'portion_g_min' => 250, 'portion_g_max' => 250,
                                'kcal_min'    => 240, 'kcal_max' => 300, 'protein_g_min' => 17, 'protein_g_max' => 21,
                                'carbs_g_min' => 8.5, 'carbs_g_max' => 11, 'fat_g_min' => 14, 'fat_g_max' => 21,
                                'confidence'  => 'high',
                            ],
                            [
                                'name'        => '1 handful of blueberries', 'portion_g_min' => 45, 'portion_g_max' => 90,
                                'kcal_min'    => 22, 'kcal_max' => 50, 'protein_g_min' => 0.3, 'protein_g_max' => 0.7,
                                'carbs_g_min' => 5.4, 'carbs_g_max' => 13, 'fat_g_min' => 0.2, 'fat_g_max' => 0.5,
                                'confidence'  => 'high',
                            ],
                        ],
                        'notes' => 'Split the single line into its components; the 250 g kwark is as given.',
                    ])],
                ],
            ],
        ]);

        return $meal->refresh()->load('items');
    }

    /** @return array<string, mixed> */
    private function row(string $name, string $slug, float $gMin, float $gMax, float $kcalMin, float $kcalMax, string $confirmedAt): array
    {
        return [
            'name'                 => $name,
            'slug'                 => $slug,
            'portion_g_min'        => $gMin,
            'portion_g_max'        => $gMax,
            'kcal_per_100g_min'    => $kcalMin,
            'kcal_per_100g_max'    => $kcalMax,
            'protein_per_100g_min' => 0,
            'protein_per_100g_max' => 0,
            'carbs_per_100g_min'   => 0,
            'carbs_per_100g_max'   => 0,
            'fat_per_100g_min'     => 0,
            'fat_per_100g_max'     => 0,
            'confirmed_at'         => $confirmedAt,
        ];
    }

    /**
     * It rebuilds ONE plate, not the meal. The request it reads was about one
     * plate, so diffing against the whole meal would report the main course as
     * rows the dessert's answer had "lost", and `--apply` would delete them.
     */
    public function test_it_rebuilds_only_the_plate_its_request_looked_at(): void
    {
        $meal = Meal::factory()->create([
            'status' => MealStatus::Proposed,
            'source' => MealSource::Photo,
        ]);

        $main = MealPhoto::factory()->for($meal)->atPosition(0)->create();
        $dessert = MealPhoto::factory()->for($meal)->atPosition(1)->create();

        // The main course: confirmed, and nothing to do with the request below.
        $meal->items()->create([
            'name'                 => 'White rice',
            'slug'                 => 'white-rice',
            'meal_photo_id'        => $main->id,
            'portion_g_min'        => 120, 'portion_g_max' => 200,
            'kcal_per_100g_min'    => 130, 'kcal_per_100g_max' => 130,
            'protein_per_100g_min' => 2.7, 'protein_per_100g_max' => 2.7,
            'carbs_per_100g_min'   => 28, 'carbs_per_100g_max' => 28,
            'fat_per_100g_min'     => 0.3, 'fat_per_100g_max' => 0.3,
            'confirmed_at'         => now(),
        ]);

        // A typed line, with no plate at all.
        $meal->items()->create([
            'name'                 => 'Sourdough toast',
            'slug'                 => 'sourdough-toast',
            'portion_g_min'        => 60, 'portion_g_max' => 60,
            'kcal_per_100g_min'    => 250, 'kcal_per_100g_max' => 250,
            'protein_per_100g_min' => 9, 'protein_per_100g_max' => 9,
            'carbs_per_100g_min'   => 48, 'carbs_per_100g_max' => 48,
            'fat_per_100g_min'     => 3, 'fat_per_100g_max' => 3,
            'confirmed_at'         => now(),
        ]);

        // The dessert's stale answer, which is what the command is pointed at.
        $meal->items()->create([
            'name'                 => 'Ice cream',
            'slug'                 => 'ice-cream',
            'meal_photo_id'        => $dessert->id,
            'portion_g_min'        => 1, 'portion_g_max' => 1,
            'kcal_per_100g_min'    => 0, 'kcal_per_100g_max' => 0,
            'protein_per_100g_min' => 0, 'protein_per_100g_max' => 0,
            'carbs_per_100g_min'   => 0, 'carbs_per_100g_max' => 0,
            'fat_per_100g_min'     => 0, 'fat_per_100g_max' => 0,
        ]);

        VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'meal_photo_id'   => $dessert->id,
            'request_kind'    => VisionRequestKind::Photo,
            'idempotency_key' => 'dessert-key',
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v2-photo',
            'status'          => VisionRequestStatus::Succeeded,
            'raw_response'    => self::rawResponse([
                [
                    'name'          => 'Vanilla ice cream',
                    'portion_g_min' => 80, 'portion_g_max' => 120,
                    'kcal_min'      => 160, 'kcal_max' => 240,
                    'protein_g_min' => 2.8, 'protein_g_max' => 4.2,
                    'carbs_g_min'   => 19, 'carbs_g_max' => 28,
                    'fat_g_min'     => 8, 'fat_g_max' => 12,
                    'confidence'    => 'high',
                ],
            ], 'Judged against the bowl.'),
        ]);

        $this->runArtisan('vision:reproject', ['meal' => $meal->uuid, '--apply' => true])
            ->expectsOutputToContain('Scope: photo 2')
            ->assertExitCode(0);

        $names = $meal->refresh()->items()->pluck('name')->all();

        // Only the dessert's row was rebuilt. The order is the relation's —
        // plate 1, plate 2, then anything plateless — so the rebuilt dessert
        // sits BEHIND the main course and AHEAD of the typed toast, not at the
        // end of the table where its new row happens to live.
        self::assertSame(['White rice', 'Vanilla ice cream', 'Sourdough toast'], $names);

        $rebuilt = $meal->items()->where('name', 'Vanilla ice cream')->firstOrFail();

        self::assertSame($dessert->id, $rebuilt->meal_photo_id);
        self::assertSame(80.0, (float) $rebuilt->portion_g_min);

        // The plate's own note is refreshed too, so its review sheet quotes the
        // answer it was rebuilt from.
        self::assertSame('Judged against the bowl.', $dessert->refresh()->model_notes);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function rawResponse(array $items, string $notes): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['items' => $items, 'notes' => $notes], JSON_THROW_ON_ERROR),
            ]],
        ];
    }
}
