<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use Tests\Concerns\ActsAsFreshUser;
use App\Services\Supplements\SupplementDay;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Take all" — the whole shelf in one press.
 *
 * There is no bulk endpoint, deliberately: the button loops the per-bottle PUT,
 * because that is the write the offline queue already de-duplicates by
 * (supplement, day) — see resources/js/lib/supplements.js for the argument and
 * tests/js/supplements.test.js for the traffic it produces. So what the server
 * has to survive is a BURST of the existing write, and what goes wrong with a
 * burst is not what goes wrong with a single tap. It must cover the shelf the
 * card is showing, every active supplement and nothing else, or "take all"
 * quietly resurrects a bottle somebody stopped taking. A second press must move
 * nothing — not a second row, and not the `taken_at` on the first, because that
 * timestamp is the evidence of when the dose was actually taken. And a partial
 * shelf must stay honest: filling the two gaps in a 2/4 day lands on 4/4 without
 * disturbing the two that were already there.
 *
 * The press is reproduced here the way the card performs it — read the day, skip
 * what is ticked, PUT the rest with the queue's own headers — so this test fails
 * if either half of that changes.
 */
final class TakeAllTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    /** The exact headers resources/js/lib/queue.js sends. */
    private const QUEUE_HEADERS = [
        'Accept'           => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    private const DATE = '2026-08-10';

    public function test_one_press_records_every_active_supplement(): void
    {
        $active = Supplement::factory()->count(4)->create();

        Supplement::factory()->inactive()->create(['name' => 'The one I stopped']);

        self::assertSame(4, $this->press(self::DATE));

        self::assertEqualsCanonicalizing(
            $active->pluck('id')->all(),
            SupplementIntake::query()->pluck('supplement_id')->all(),
        );

        $day = $this->day(self::DATE);

        self::assertSame(4, $day['total']);
        self::assertSame(4, $day['takenCount']);
        self::assertFalse($day['needsAttention']);
    }

    /** Nothing left to say, so nothing is sent — and nothing is disturbed. */
    public function test_a_second_press_writes_nothing_and_moves_nothing(): void
    {
        Supplement::factory()->count(4)->create();

        $this->press(self::DATE);

        $first = SupplementIntake::query()->orderBy('id')->pluck('taken_at', 'id');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        self::assertSame(0, $this->press(self::DATE));

        CarbonImmutable::setTestNow();

        self::assertSame(4, SupplementIntake::query()->count());
        self::assertEquals($first, SupplementIntake::query()->orderBy('id')->pluck('taken_at', 'id'));
    }

    /**
     * The queue replaying the whole press an hour later — four actions written to
     * IndexedDB on a train, flushed when the radio came back.
     */
    public function test_replaying_the_whole_press_is_still_one_intake_each(): void
    {
        $supplements = Supplement::factory()->count(4)->create();

        $this->press(self::DATE);

        $first = SupplementIntake::query()->orderBy('id')->pluck('taken_at', 'id');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        foreach ($supplements as $supplement) {
            $this->withHeaders(self::QUEUE_HEADERS)
                ->putJson($this->url($supplement), ['date' => self::DATE, 'taken' => true])
                ->assertOk();
        }

        CarbonImmutable::setTestNow();

        self::assertSame(4, SupplementIntake::query()->count());
        self::assertEquals($first, SupplementIntake::query()->orderBy('id')->pluck('taken_at', 'id'));
    }

    /** The 2/4 evening: the press fills the gaps and leaves the rest alone. */
    public function test_a_press_on_a_partial_shelf_only_fills_the_gaps(): void
    {
        $supplements = Supplement::factory()->count(4)->create()->all();

        SupplementIntake::factory()->for($supplements[0])->on(self::DATE)->create();
        SupplementIntake::factory()->for($supplements[2])->on(self::DATE)->create();

        $before = $this->day(self::DATE);

        self::assertSame(2, $before['takenCount']);
        self::assertSame(4, $before['total']);

        $untouched = SupplementIntake::query()->orderBy('id')->pluck('taken_at', 'id');

        self::assertSame(2, $this->press(self::DATE));

        $after = $this->day(self::DATE);

        self::assertSame(4, $after['takenCount']);
        self::assertSame(4, $after['total']);
        self::assertSame(4, SupplementIntake::query()->count());

        // The two that were already there kept their own timestamps.
        foreach ($untouched as $id => $takenAt) {
            self::assertEquals($takenAt, SupplementIntake::query()->findOrFail($id)->taken_at);
        }
    }

    /** Un-ticking one afterwards: the collapsed line has to be able to say 3/4. */
    public function test_un_ticking_one_afterwards_reads_as_a_partial_count(): void
    {
        $supplements = Supplement::factory()->count(4)->create()->all();

        $this->press(self::DATE);

        $this->putJson($this->url($supplements[1]), ['date' => self::DATE, 'taken' => false])
            ->assertOk()
            ->assertJsonPath('supplements.takenCount', 3)
            ->assertJsonPath('supplements.total', 4);

        self::assertSame(3, $this->day(self::DATE)['takenCount']);
        self::assertSame(3, SupplementIntake::query()->count());
    }

    /** The one thing the amber edge branches on goes out with the last bottle. */
    public function test_the_press_puts_out_the_evening_state(): void
    {
        Supplement::factory()->count(4)->create();

        $tz = (string) config('health.timezone');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 19:30', $tz));

        self::assertTrue($this->day(self::DATE)['needsAttention']);

        $this->press(self::DATE);

        self::assertFalse($this->day(self::DATE)['needsAttention']);

        CarbonImmutable::setTestNow();
    }

    /** The card is per-date, so the press is too. */
    public function test_a_press_on_a_past_day_lands_on_that_day(): void
    {
        Supplement::factory()->count(2)->create();

        $yesterday = CarbonImmutable::now((string) config('health.timezone'))->subDay()->toDateString();

        self::assertSame(2, $this->press($yesterday));

        self::assertSame(
            [$yesterday, $yesterday],
            SupplementIntake::query()->pluck('local_date')->map(
                static fn ($date): string => CarbonImmutable::parse($date)->toDateString()
            )->all(),
        );

        self::assertSame(0, $this->day(CarbonImmutable::now((string) config('health.timezone'))->toDateString())['takenCount']);
    }

    /** An empty shelf has nothing to press, and the button is not on screen. */
    public function test_an_empty_shelf_writes_nothing(): void
    {
        self::assertSame(0, $this->press(self::DATE));

        self::assertSame(0, SupplementIntake::query()->count());
    }

    // --- the press, as the card performs it --------------------------------

    /**
     * Read the day, skip what is already ticked, PUT the rest.
     *
     * @return int how many requests the button would have sent
     */
    private function press(string $date): int
    {
        $sent = 0;

        foreach ($this->day($date)['items'] as $item) {
            if ($item['taken']) {
                continue;
            }

            $this->withHeaders(self::QUEUE_HEADERS)
                ->putJson('/api/supplements/'.$item['id'].'/intake', ['date' => $date, 'taken' => true])
                ->assertOk();

            $sent++;
        }

        return $sent;
    }

    /**
     * The card's own payload, from the class that builds it, so "what the card is
     * showing" is not paraphrased here.
     *
     * @return array<string, mixed>
     */
    private function day(string $date): array
    {
        return app(SupplementDay::class)->props($date);
    }

    private function url(Supplement $supplement): string
    {
        return '/api/supplements/'.$supplement->id.'/intake';
    }
}
