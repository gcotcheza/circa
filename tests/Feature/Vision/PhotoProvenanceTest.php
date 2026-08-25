<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use Tests\Concerns\ActsAsFreshUser;
use Tests\Support\FakeVisionAnalyzer;
use Inertia\Testing\AssertableInertia;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `meal_items.meal_photo_id` — which plate a line came off, and keeping it.
 *
 * The regression: a three-course dinner with every item confirmed still showed
 * an orange "3 more photos to review" banner, because the ordinary edit sheet
 * replaced the meal's rows wholesale and nulled the column on all sixteen items.
 * Both ends of the lifecycle are held here — confirm stamps the plate from the
 * server's own knowledge, an edit keeps it, the banner is derived from it, and
 * `vision:relink` repairs the meals it already happened to. `share_fraction` and
 * `portion_full_g_*` ride the same wholesale writes and are asserted alongside.
 * See docs/rationale-frontend.md § "Photo provenance on meal items"
 */
final class PhotoProvenanceTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(VisionAnalyzer::class, new FakeVisionAnalyzer);
    }

    // 1. CONFIRM STAMPS THE PLATE

    /**
     * `client_id` names the ENTRY, not `meal_photo_id`: the internal key is
     * never exposed to the PWA (see MealPhoto's route key), so the client could
     * not send it even if it were trusted to. The stamp is resolved server-side
     * from the meal the request is on.
     */
    public function test_confirming_a_plate_stamps_its_items_with_that_plate(): void
    {
        $meal = $this->photoMeal();
        [$starter, $main] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);

        $items = $meal->refresh()->items;

        self::assertCount(1, $items);
        $firstItem = $this->notNull($items->first());
        self::assertSame($starter->id, $firstItem->meal_photo_id);
        self::assertNotNull($firstItem->confirmed_at);

        // The second course appends beside the first, wearing its own.
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        $byName = $meal->refresh()->items->keyBy('name');

        self::assertSame($starter->id, $this->notNull($byName['spring rolls'])->meal_photo_id);
        self::assertSame($main->id, $this->notNull($byName['pho broth'])->meal_photo_id);
    }

    /** Proves the assertion above is about the server, not a field the test supplied. */
    public function test_the_confirm_payload_contains_no_photo_id_at_all(): void
    {
        $meal = $this->photoMeal();
        [$starter] = $this->plates($meal);

        $payload = $this->confirmPayload($starter, [$this->line('spring rolls')]);

        self::assertArrayNotHasKey('meal_photo_id', $payload);
        self::assertArrayNotHasKey('meal_photo_id', $payload['items'][0]);
        self::assertStringNotContainsString('meal_photo_id', json_encode($payload, JSON_THROW_ON_ERROR));

        $this->put('/meals/'.$meal->uuid.'/proposal', $payload)->assertSessionHasNoErrors();

        self::assertSame($starter->id, $this->notNull($meal->refresh()->items->first())->meal_photo_id);
    }

    /**
     * The text path means no plate, and a null that says so: a confirm with no
     * `client_id` on a meal with several plates must not adopt one of them.
     */
    public function test_a_confirm_with_no_entry_leaves_the_items_unphotographed(): void
    {
        $meal = $this->photoMeal();
        $this->plates($meal);

        $this->put('/meals/'.$meal->uuid.'/proposal', [
            'date'  => '2026-08-07',
            'time'  => '19:00',
            'items' => [$this->line('a described dinner')],
        ])->assertSessionHasNoErrors();

        self::assertNull($this->notNull($meal->refresh()->items->first())->meal_photo_id);
    }

    // 2. THE EDIT SHEET KEEPS IT

    /**
     * The regression proper — the tap that broke meal 6 in production. The edit
     * form posts a flat list with no provenance in it, `update()` replaces the
     * rows wholesale, and before the fix every one came back null.
     */
    public function test_editing_a_confirmed_meal_keeps_every_item_on_its_own_plate(): void
    {
        $meal = $this->photoMeal();
        [$starter, $main] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        $this->put('/meals/'.$meal->uuid, [
            'uuid'      => $meal->uuid,
            'date'      => '2026-08-07',
            'time'      => '19:00',
            'meal_type' => 'dinner',
            'notes'     => 'edited',
            'items'     => [
                ['name' => 'spring rolls', 'basis' => 'per_100g', 'grams' => 280, 'kcal_per_100g' => 121.429],
                ['name' => 'pho broth', 'basis' => 'per_100g', 'grams' => 600, 'kcal_per_100g' => 15],
            ],
        ])->assertSessionHasNoErrors();

        $byName = $meal->refresh()->items->keyBy('name');

        self::assertCount(2, $byName);
        self::assertSame($starter->id, $this->notNull($byName['spring rolls'])->meal_photo_id);
        self::assertSame($main->id, $this->notNull($byName['pho broth'])->meal_photo_id);
    }

    /** A line typed INTO the edit must not inherit the plate of the row in its slot. */
    public function test_an_item_added_during_an_edit_has_no_plate(): void
    {
        $meal = $this->photoMeal();
        [$starter] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);

        $this->edit($meal, [
            ['name' => 'spring rolls', 'basis' => 'absolute', 'kcal' => 340],
            ['name' => 'a beer nobody photographed', 'basis' => 'absolute', 'kcal' => 150],
        ]);

        $byName = $meal->refresh()->items->keyBy('name');

        self::assertSame($starter->id, $this->notNull($byName['spring rolls'])->meal_photo_id);
        self::assertNull($this->notNull($byName['a beer nobody photographed'])->meal_photo_id);
    }

    /** Renaming a line past recognition drops the link rather than guessing. */
    public function test_renaming_an_item_during_an_edit_releases_its_plate(): void
    {
        $meal = $this->photoMeal();
        [$starter] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);

        $this->edit($meal, [['name' => 'summer rolls', 'basis' => 'absolute', 'kcal' => 340]]);

        self::assertNull($this->notNull($meal->refresh()->items->first())->meal_photo_id);
    }

    /** The shared plate survives the same replace, by round-trip not preservation. */
    public function test_an_edit_keeps_the_share_and_the_full_portion(): void
    {
        $meal = $this->photoMeal();
        [$starter] = $this->plates($meal);

        $this->confirm($meal, $starter, [
            ['name' => 'whipped cream', 'share_fraction' => 0.3333] + $this->line('whipped cream'),
        ]);

        $before = $this->notNull($meal->refresh()->items->first());

        self::assertEqualsWithDelta(0.3333, (float) $before->share_fraction, 0.0001);

        // The FULL plate and the fraction beside it, as MealSheet.vue posts it.
        $this->edit($meal, [[
            'name'           => 'whipped cream',
            'basis'          => 'per_100g',
            'grams'          => (float) $before->portion_full_g_min,
            'kcal_per_100g'  => 340,
            'share_fraction' => (float) $before->share_fraction,
        ]]);

        $after = $this->notNull($meal->refresh()->items->first());

        self::assertSame($starter->id, $after->meal_photo_id);
        self::assertEqualsWithDelta(0.3333, (float) $after->share_fraction, 0.0001);
        self::assertEqualsWithDelta(
            (float) $before->portion_full_g_min,
            (float) $after->portion_full_g_min,
            0.001
        );
        // portion = full x share still holds after the round trip.
        self::assertEqualsWithDelta(
            (float) $after->portion_full_g_min * 0.3333,
            (float) $after->portion_g_min,
            0.001
        );
    }

    /**
     * Re-logging must not carry the original's plate across: `MealPhoto::items()`
     * is a plain hasMany on this column, so a leaked id would let "remove this
     * plate and its items" reach across meals and delete food off another day.
     */
    public function test_relogging_a_photo_meal_gives_the_copy_no_plate(): void
    {
        $meal = $this->photoMeal();
        [$starter] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);

        $this->post('/meals/'.$meal->uuid.'/repeat', [
            'uuid' => (string) Str::uuid(),
            'date' => '2026-08-09',
            'time' => '19:00',
        ])->assertSessionHasNoErrors();

        $copy = Meal::query()->where('id', '>', $meal->id)->latest('id')->firstOrFail();

        self::assertNotSame($meal->id, $copy->id);
        $copyFirst = $this->notNull($copy->items->first());
        self::assertSame('spring rolls', $copyFirst->name);
        self::assertNull($copyFirst->meal_photo_id);
        // The original is untouched.
        self::assertSame($starter->id, $this->notNull($meal->refresh()->items->first())->meal_photo_id);
    }

    // 3. WHAT THE BANNER READS

    /**
     * `MealCard.vue` counts `photos.filter(p => p.state !== 'settled')`, and
     * `state` is `MealPhoto::entryState()` shipped through DailyView — so
     * asserting the prop is asserting the banner.
     */
    public function test_a_fully_confirmed_multi_plate_meal_has_no_plate_left_to_review(): void
    {
        $meal = $this->photoMeal();
        [$starter, $main] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        self::assertSame('settled', $starter->refresh()->entryState());
        self::assertSame('settled', $main->refresh()->entryState());

        $this->get('/?date=2026-08-07')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('meals', 1)
                ->where('meals.0.photos.0.state', 'settled')
                ->where('meals.0.photos.1.state', 'settled')
                ->where('meals.0.photos.0.confirmedItemCount', 1)
                ->where('meals.0.photos.1.confirmedItemCount', 1)
                ->etc()
        );

        self::assertSame(0, $this->pendingPlates('2026-08-07'));
    }

    /** An edit no longer un-reviews it, which is the whole complaint. */
    public function test_editing_the_meal_does_not_bring_the_banner_back(): void
    {
        $meal = $this->photoMeal();
        [$starter, $main] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        $this->edit($meal, [
            ['name' => 'spring rolls', 'basis' => 'absolute', 'kcal' => 340],
            ['name' => 'pho broth', 'basis' => 'absolute', 'kcal' => 90],
        ]);

        self::assertSame(0, $this->pendingPlates('2026-08-07'));
    }

    /** The damaged shape, proving the count is capable of being wrong. */
    public function test_a_plate_with_nothing_pointing_at_it_still_reads_as_outstanding(): void
    {
        $meal = $this->photoMeal();
        [$starter, $main] = $this->plates($meal);

        $this->confirm($meal, $starter, [$this->line('spring rolls')]);
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        // Exactly what the old edit path left behind.
        MealItem::query()->where('meal_id', $meal->id)->update(['meal_photo_id' => null]);

        self::assertSame(2, $this->pendingPlates('2026-08-07'));
    }

    // 4. THE REPAIR COMMAND

    public function test_relink_is_a_dry_run_by_default(): void
    {
        $meal = $this->damagedMeal();

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid])
            ->expectsOutputToContain('Would re-link 3 item(s)')
            ->assertExitCode(0);

        self::assertSame(0, $meal->items()->whereNotNull('meal_photo_id')->count());
        self::assertSame(2, $this->pendingPlates('2026-08-07'));
    }

    public function test_relink_apply_puts_every_item_back_on_the_plate_that_named_it(): void
    {
        $meal = $this->damagedMeal();

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])
            ->expectsOutputToContain('Re-linked 3 item(s)')
            ->assertExitCode(0);

        [$starter, $main] = $meal->photos()->orderBy('position')->get()->all();

        $byName = $meal->refresh()->items->keyBy('name');

        self::assertSame($starter->id, $this->notNull($byName['spring rolls'])->meal_photo_id);
        self::assertSame($starter->id, $this->notNull($byName['sweet chilli dipping sauce'])->meal_photo_id);
        self::assertSame($main->id, $this->notNull($byName['pho broth'])->meal_photo_id);

        // Which is the banner going away.
        self::assertSame(0, $this->pendingPlates('2026-08-07'));
    }

    /** It moves one column. Nothing about what was eaten may move with it. */
    public function test_relink_changes_no_number_no_share_and_no_status(): void
    {
        $meal = $this->damagedMeal();

        $columns = [
            'name', 'slug', 'portion_g_min', 'portion_g_max', 'portion_full_g_min', 'portion_full_g_max',
            'share_fraction', 'kcal_per_100g_min', 'kcal_per_100g_max', 'protein_per_100g_min',
            'carbs_per_100g_min', 'fat_per_100g_min', 'confidence', 'confirmed_at',
        ];

        $before = $meal->items()->orderBy('id')->get()->map->only($columns)->all();
        $statusBefore = $meal->status;

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])->assertExitCode(0);

        $after = $meal->refresh()->items()->orderBy('id')->get()->map->only($columns)->all();

        self::assertEquals($before, $after);
        self::assertSame($statusBefore, $meal->status);
    }

    public function test_relink_is_idempotent(): void
    {
        $meal = $this->damagedMeal();

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])->assertExitCode(0);

        $first = $meal->refresh()->items()->orderBy('id')->pluck('meal_photo_id')->all();

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])
            ->expectsOutputToContain('Nothing to re-link')
            ->assertExitCode(0);

        self::assertSame($first, $meal->refresh()->items()->orderBy('id')->pluck('meal_photo_id')->all());
    }

    /**
     * A name two plates both claim is left alone: the banner is cosmetic, but a
     * wrong photograph behind a food is a false record.
     */
    public function test_relink_refuses_a_name_that_two_plates_both_name(): void
    {
        $meal = $this->damagedMeal(sharedName: true);

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])
            ->expectsOutputToContain('named by 2 entries')
            ->assertExitCode(0);

        self::assertNull($this->notNull($meal->refresh()->items->firstWhere('name', 'pho broth'))->meal_photo_id);
    }

    /** A line the answers never mentioned keeps its honest null. */
    public function test_relink_leaves_a_typed_line_alone(): void
    {
        $meal = $this->damagedMeal();

        $meal->items()->create([
            'name'                 => 'a beer nobody photographed', 'slug' => 'a-beer-nobody-photographed',
            'portion_g_min'        => 330, 'portion_g_max' => 330, 'share_fraction' => 1,
            'kcal_per_100g_min'    => 43, 'kcal_per_100g_max' => 43,
            'protein_per_100g_min' => 0, 'protein_per_100g_max' => 0,
            'carbs_per_100g_min'   => 3, 'carbs_per_100g_max' => 3,
            'fat_per_100g_min'     => 0, 'fat_per_100g_max' => 0,
            'confirmed_at'         => now(),
        ]);

        $this->runArtisan('vision:relink', ['meal' => $meal->uuid, '--apply' => true])
            ->expectsOutputToContain('no answer names it')
            ->assertExitCode(0);

        self::assertNull($this->notNull($meal->refresh()->items->firstWhere('name', 'a beer nobody photographed'))->meal_photo_id);
    }

    public function test_relink_rejects_a_uuid_that_is_not_one(): void
    {
        $this->runArtisan('vision:relink', ['meal' => 'not-a-uuid'])->assertExitCode(1);
    }

    public function test_relink_rejects_a_meal_that_does_not_exist(): void
    {
        $this->runArtisan('vision:relink', ['meal' => (string) Str::uuid()])->assertExitCode(1);
    }

    // FIXTURES

    private function photoMeal(): Meal
    {
        return Meal::factory()->create([
            'status'    => MealStatus::Proposed,
            'source'    => 'photo',
            'eaten_at'  => '2026-08-07 17:00:00',
            'meal_type' => 'dinner',
            'notes'     => null,
        ]);
    }

    /**
     * Two plates, each with the succeeded analysis its state is read from.
     *
     * @return array{MealPhoto, MealPhoto}
     */
    private function plates(Meal $meal): array
    {
        $starter = MealPhoto::factory()->atPosition(0)->create(['meal_id' => $meal->id]);
        $main = MealPhoto::factory()->atPosition(1)->create(['meal_id' => $meal->id]);

        foreach ([$starter, $main] as $photo) {
            $this->answer($meal, $photo, []);
        }

        return [$starter, $main];
    }

    /**
     * The audit row a plate's state is derived from.
     *
     * @param  list<string>  $names
     */
    private function answer(Meal $meal, ?MealPhoto $photo, array $names): VisionRequest
    {
        return VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'meal_photo_id'   => $photo?->id,
            'request_kind'    => $photo === null ? VisionRequestKind::Text : VisionRequestKind::Photo,
            'idempotency_key' => (string) Str::uuid(),
            'model'           => 'claude-opus-5',
            'prompt_version'  => $photo === null ? 'v1-text' : 'v2-photo',
            'image_sha256'    => $photo?->sha256,
            'status'          => VisionRequestStatus::Succeeded,
            'raw_response'    => [
                'id'          => 'msg_'.Str::random(8),
                'model'       => 'claude-opus-5',
                'stop_reason' => 'end_turn',
                'content'     => [
                    // A thinking block first, as the SDK serialises it:
                    // StoredAnswer must walk past it to find the document.
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                    ['type' => 'text', 'text' => json_encode([
                        'items' => array_map(static fn (string $name): array => [
                            'name'          => $name,
                            'portion_g_min' => 120, 'portion_g_max' => 200,
                            'kcal_min'      => 180, 'kcal_max' => 260,
                            'protein_g_min' => 3, 'protein_g_max' => 5,
                            'carbs_g_min'   => 30, 'carbs_g_max' => 50,
                            'fat_g_min'     => 0.3, 'fat_g_max' => 0.6,
                            'confidence'    => 'high',
                        ], $names),
                        'notes' => 'Judged against the plate.',
                    ], JSON_THROW_ON_ERROR)],
                ],
            ],
        ]);
    }

    /** A confirmed two-plate meal with its provenance wiped — meal 6 in miniature. */
    private function damagedMeal(bool $sharedName = false): Meal
    {
        $meal = $this->photoMeal();

        $starter = MealPhoto::factory()->atPosition(0)->create(['meal_id' => $meal->id]);
        $main = MealPhoto::factory()->atPosition(1)->create(['meal_id' => $meal->id]);

        $this->answer($meal, $starter, array_merge(
            ['spring rolls', 'sweet chilli dipping sauce'],
            $sharedName ? ['pho broth'] : [],
        ));
        $this->answer($meal, $main, ['pho broth']);

        $this->confirm($meal, $starter, [
            $this->line('spring rolls'),
            $this->line('sweet chilli dipping sauce'),
        ]);
        $this->confirm($meal, $main, [$this->line('pho broth')]);

        // The wholesale replace, as `MealWriter::update()` used to leave it.
        MealItem::query()->where('meal_id', $meal->id)->update(['meal_photo_id' => null]);

        return $meal->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function confirm(Meal $meal, MealPhoto $photo, array $items): void
    {
        $this->put('/meals/'.$meal->uuid.'/proposal', $this->confirmPayload($photo, $items))
            ->assertSessionHasNoErrors();
    }

    /**
     * ProposalReview.vue's `form.data()`, field for field — no `meal_photo_id`.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function confirmPayload(MealPhoto $photo, array $items): array
    {
        return [
            'date'           => '2026-08-07',
            'time'           => '19:00',
            'meal_type'      => 'dinner',
            'notes'          => '',
            'client_id'      => $photo->client_id,
            'share_fraction' => 1,
            'items'          => $items,
        ];
    }

    /**
     * The edit sheet's payload: a flat list with no provenance in it.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function edit(Meal $meal, array $items): void
    {
        $this->put('/meals/'.$meal->uuid, [
            'uuid'      => $meal->uuid,
            'date'      => '2026-08-07',
            'time'      => '19:00',
            'meal_type' => 'dinner',
            'notes'     => null,
            'items'     => $items,
        ])->assertSessionHasNoErrors();
    }

    /** The review screen's shape: absolutes, in pairs. */
    /** @return array<string, mixed> */
    private function line(string $name): array
    {
        return [
            'name'          => $name,
            'portion_g_min' => 120, 'portion_g_max' => 200,
            'kcal_min'      => 180, 'kcal_max' => 260,
            'protein_g_min' => 3, 'protein_g_max' => 5,
            'carbs_g_min'   => 30, 'carbs_g_max' => 50,
            'fat_g_min'     => 0.3, 'fat_g_max' => 0.6,
            'confidence'    => 'high',
        ];
    }

    /** What MealCard.vue counts to decide whether to draw the banner. */
    private function pendingPlates(string $date): int
    {
        $pending = 0;

        $this->get('/?date='.$date)->assertInertia(function (AssertableInertia $page) use (&$pending): void {
            foreach ((array) $page->toArray()['props']['meals'] as $meal) {
                foreach ((array) ($meal['photos'] ?? []) as $photo) {
                    if (($photo['state'] ?? null) !== 'settled') {
                        $pending++;
                    }
                }
            }
        });

        return $pending;
    }
}
