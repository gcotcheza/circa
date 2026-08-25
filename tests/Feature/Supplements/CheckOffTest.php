<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The tap, and everything that has to survive it being sent twice.
 *
 * This button is pressed in a kitchen at nine at night on one bar of 4G, so it
 * rides the offline queue — at-least-once by construction. The same tick can
 * arrive twice from two tabs, from an `online` event racing a foreground flush,
 * or from an action written to IndexedDB yesterday.
 *
 * The queue's JavaScript cannot be exercised here. What can be — and is the half
 * that would silently rewrite somebody's history — is the shape of the responses
 * it reads: a replay is a success rather than a duplicate, and an undo of
 * something already gone is a success rather than a 404 the queue would treat as
 * permanent and block on.
 */
final class CheckOffTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    /** The exact headers resources/js/lib/queue.js sends. */
    private const QUEUE_HEADERS = [
        'Accept'           => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    public function test_a_tap_records_an_intake_for_the_day_being_viewed(): void
    {
        // isToday() below compares against now(); freeze it on the day under
        // test so a run crossing midnight cannot flake.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 12:00', (string) config('health.timezone')));

        $supplement = Supplement::factory()->create(['name' => 'Magnesium']);

        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => true])
            ->assertOk()
            ->assertJsonPath('date', '2026-08-08')
            ->assertJsonPath('supplements.takenCount', 1)
            ->assertJsonPath('supplements.total', 1)
            ->assertJsonPath('supplements.items.0.taken', true);

        $intake = SupplementIntake::query()->sole();

        self::assertSame($supplement->id, $intake->supplement_id);
        self::assertSame('2026-08-08', $intake->local_date->toDateString());
        // taken_at is NOT NULL in the schema — this confirms it stamped the
        // moment of the tap (today), not merely that the column is typed.
        self::assertTrue($intake->taken_at->isToday());
    }

    /** The queue replaying the same action: one row, one success, no complaint. */
    public function test_replaying_the_same_tick_twice_records_one_intake(): void
    {
        $supplement = Supplement::factory()->create();

        $payload = ['date' => '2026-08-08', 'taken' => true];

        $this->withHeaders(self::QUEUE_HEADERS)->putJson($this->url($supplement), $payload)->assertOk();
        $this->withHeaders(self::QUEUE_HEADERS)->putJson($this->url($supplement), $payload)->assertOk();

        self::assertSame(1, SupplementIntake::query()->count());
    }

    /**
     * The whole queued sequence replayed from the top, as a flush does it: tick,
     * undo, tick. The last statement wins and nothing is duplicated.
     */
    public function test_a_whole_queued_sequence_replays_to_the_last_statement(): void
    {
        $supplement = Supplement::factory()->create();

        $actions = [
            ['date' => '2026-08-08', 'taken' => true],
            ['date' => '2026-08-08', 'taken' => false],
            ['date' => '2026-08-08', 'taken' => true],
        ];

        foreach ([...$actions, ...$actions] as $payload) {
            $this->withHeaders(self::QUEUE_HEADERS)
                ->putJson($this->url($supplement), $payload)
                ->assertOk();
        }

        self::assertSame(1, SupplementIntake::query()->count());
    }

    public function test_tapping_again_removes_the_intake(): void
    {
        $supplement = Supplement::factory()->create();

        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => true])->assertOk();

        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => false])
            ->assertOk()
            ->assertJsonPath('supplements.takenCount', 0)
            ->assertJsonPath('supplements.items.0.taken', false);

        self::assertSame(0, SupplementIntake::query()->count());
    }

    /**
     * A 404 here reads to the queue as PERMANENT: the action blocks and a red
     * badge appears on the phone for a tap that had already worked.
     */
    public function test_undoing_something_that_was_never_ticked_is_a_success(): void
    {
        $supplement = Supplement::factory()->create();

        $this->withHeaders(self::QUEUE_HEADERS)
            ->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => false])
            ->assertOk();

        self::assertSame(0, SupplementIntake::query()->count());
    }

    /** Logging yesterday's forgotten pill — the reason the date is in the body. */
    public function test_a_past_day_can_still_be_ticked(): void
    {
        $supplement = Supplement::factory()->create();

        $yesterday = CarbonImmutable::now((string) config('health.timezone'))->subDay()->toDateString();

        $this->putJson($this->url($supplement), ['date' => $yesterday, 'taken' => true])->assertOk();

        self::assertSame($yesterday, SupplementIntake::query()->sole()->local_date->toDateString());
    }

    public function test_the_same_supplement_on_two_days_is_two_intakes(): void
    {
        $supplement = Supplement::factory()->create();

        $this->putJson($this->url($supplement), ['date' => '2026-08-07', 'taken' => true])->assertOk();
        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => true])->assertOk();

        self::assertSame(2, SupplementIntake::query()->count());
    }

    public function test_an_undo_on_one_day_leaves_the_other_day_alone(): void
    {
        $supplement = Supplement::factory()->create();

        $this->putJson($this->url($supplement), ['date' => '2026-08-07', 'taken' => true])->assertOk();
        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => true])->assertOk();
        $this->putJson($this->url($supplement), ['date' => '2026-08-08', 'taken' => false])->assertOk();

        self::assertSame(['2026-08-07'], SupplementIntake::query()->pluck('local_date')->map(
            static fn ($date): string => CarbonImmutable::parse($date)->toDateString()
        )->all());
    }

    public function test_a_malformed_date_is_a_422_the_queue_can_read(): void
    {
        $supplement = Supplement::factory()->create();

        $this->withHeaders(self::QUEUE_HEADERS)
            ->putJson($this->url($supplement), ['date' => 'yesterday', 'taken' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    // --- the day view ----------------------------------------------------

    public function test_the_day_view_carries_the_card_for_the_date_being_looked_at(): void
    {
        $supplement = Supplement::factory()->taking(2)->create([
            'name'         => 'Magnesium',
            'serving_text' => 'per capsule',
        ]);

        Supplement::factory()->inactive()->create(['name' => 'The spare']);

        SupplementIntake::factory()->for($supplement)->on('2026-08-07')->create();

        $this->get('/?date=2026-08-07')->assertInertia(
            fn ($page) => $page
                ->where('supplements.total', 1)
                ->where('supplements.takenCount', 1)
                ->where('supplements.items.0.name', 'Magnesium')
                ->where('supplements.items.0.unitsPerDay', 2)
                ->where('supplements.items.0.servingText', 'per capsule')
                ->where('supplements.items.0.taken', true)
                ->etc()
        );

        // The same supplement on a day it was not taken.
        $this->get('/?date=2026-08-06')->assertInertia(
            fn ($page) => $page
                ->where('supplements.takenCount', 0)
                ->where('supplements.items.0.taken', false)
                ->etc()
        );
    }

    /**
     * Today, after the evening hour, something unticked: the one flag the card
     * branches on, deliberately false on every past day.
     */
    public function test_the_evening_state_is_todays_alone(): void
    {
        Supplement::factory()->create();

        $tz = (string) config('health.timezone');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 19:30', $tz));

        $this->get('/?date=2026-08-08')->assertInertia(
            fn ($page) => $page->where('supplements.needsAttention', true)->etc()
        );

        // Yesterday's blank row is a fact to look at, not a nag.
        $this->get('/?date=2026-08-07')->assertInertia(
            fn ($page) => $page->where('supplements.needsAttention', false)->etc()
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 11:00', $tz));

        $this->get('/?date=2026-08-08')->assertInertia(
            fn ($page) => $page->where('supplements.needsAttention', false)->etc()
        );

        CarbonImmutable::setTestNow();
    }

    public function test_the_evening_state_goes_out_once_everything_is_ticked(): void
    {
        $supplement = Supplement::factory()->create();

        $tz = (string) config('health.timezone');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08 21:00', $tz));

        SupplementIntake::factory()->for($supplement)->on('2026-08-08')->create();

        $this->get('/?date=2026-08-08')->assertInertia(
            fn ($page) => $page
                ->where('supplements.needsAttention', false)
                ->where('supplements.takenCount', 1)
                ->etc()
        );

        CarbonImmutable::setTestNow();
    }

    private function url(Supplement $supplement): string
    {
        return '/api/supplements/'.$supplement->id.'/intake';
    }
}
