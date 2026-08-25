<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\User;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use App\Services\Supplements\SupplementAdherence;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "supplements: 6/7 evenings" — the arithmetic, and the three ways it could have
 * lied. Every test is one of the rules in SupplementAdherence: a day before the
 * bottle existed is not a miss, a day that has not happened yet is not a miss,
 * and an evening counts only when everything was ticked.
 */
final class AdherenceTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-08';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(self::TODAY.' 22:00', (string) config('health.timezone'))
        );

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_nothing_on_the_shelf_is_nothing_to_say(): void
    {
        self::assertNull($this->adherence()->forRange(7));
    }

    public function test_an_inactive_supplement_is_not_scored(): void
    {
        Supplement::factory()->inactive()->create(['created_at' => '2026-07-01 08:00:00']);

        self::assertNull($this->adherence()->forRange(7));
    }

    public function test_a_perfect_week_is_seven_of_seven(): void
    {
        $supplement = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        foreach ($this->lastDays(7) as $date) {
            SupplementIntake::factory()->for($supplement)->on($date)->create();
        }

        $result = $this->notNull($this->adherence()->forRange(7));

        self::assertSame(7, $result['counted']);
        self::assertSame(7, $result['complete']);
        self::assertSame('2026-08-02', $result['start']);
        self::assertSame(self::TODAY, $result['end']);
    }

    public function test_one_missed_evening_is_six_of_seven(): void
    {
        $supplement = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        foreach ($this->lastDays(7) as $index => $date) {
            if ($index === 3) {
                continue;
            }

            SupplementIntake::factory()->for($supplement)->on($date)->create();
        }

        $result = $this->notNull($this->adherence()->forRange(7));

        self::assertSame(7, $result['counted']);
        self::assertSame(6, $result['complete']);
    }

    /** Rule 3. Three of four is not three quarters of an evening — it is a miss. */
    public function test_an_evening_counts_only_when_everything_was_ticked(): void
    {
        $magnesium = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);
        $omega = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        foreach ($this->lastDays(7) as $date) {
            SupplementIntake::factory()->for($magnesium)->on($date)->create();
        }

        // The omega was taken on only two of the seven.
        foreach (array_slice($this->lastDays(7), 0, 2) as $date) {
            SupplementIntake::factory()->for($omega)->on($date)->create();
        }

        $result = $this->notNull($this->adherence()->forRange(7));

        self::assertSame(7, $result['counted']);
        self::assertSame(2, $result['complete']);
    }

    /**
     * Rule 1. A bottle bought on Thursday must not make the three weeks before
     * it read as three weeks of missed doses.
     */
    public function test_days_before_the_bottle_existed_are_not_in_the_denominator(): void
    {
        // Created three days ago, taken on every day since.
        $supplement = Supplement::factory()->create(['created_at' => '2026-08-06 19:00:00']);

        foreach (['2026-08-06', '2026-08-07', self::TODAY] as $date) {
            SupplementIntake::factory()->for($supplement)->on($date)->create();
        }

        $result = $this->notNull($this->adherence()->forRange(7));

        self::assertSame(3, $result['counted']);
        self::assertSame(3, $result['complete']);

        // Still the week the user asked for: only the scoring is narrower, and
        // the prop says so.
        self::assertSame(7, $result['days']);
    }

    /** A second bottle raises the bar from the day it arrived, not before. */
    public function test_a_supplement_added_mid_window_only_raises_the_bar_from_its_own_day(): void
    {
        $old = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);
        $new = Supplement::factory()->create(['created_at' => '2026-08-07 19:00:00']);

        foreach ($this->lastDays(7) as $date) {
            SupplementIntake::factory()->for($old)->on($date)->create();
        }

        // The new one was taken today but not on the day it was added.
        SupplementIntake::factory()->for($new)->on(self::TODAY)->create();

        $result = $this->notNull($this->adherence()->forRange(7));

        self::assertSame(7, $result['counted']);
        // Six days with only the old one, or both taken; the 7th (2026-08-07)
        // had the new one and it was not ticked.
        self::assertSame(6, $result['complete']);
    }

    /** Rule 2. Tomorrow is not a missed evening. */
    public function test_the_window_never_runs_into_the_future(): void
    {
        Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        $result = $this->notNull($this->adherence()->forRange(7, '2026-09-30'));

        self::assertSame(self::TODAY, $result['end']);
        self::assertSame(7, $result['counted']);
    }

    public function test_the_trends_page_carries_the_line(): void
    {
        $supplement = Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        foreach (array_slice($this->lastDays(7), 0, 5) as $date) {
            SupplementIntake::factory()->for($supplement)->on($date)->create();
        }

        $this->get('/trends?range=7')->assertInertia(
            fn ($page) => $page
                ->where('supplements.complete', 5)
                ->where('supplements.counted', 7)
                ->where('supplements.days', 7)
                ->etc()
        );
    }

    public function test_the_line_follows_the_range_buttons(): void
    {
        Supplement::factory()->create(['created_at' => '2026-07-01 08:00:00']);

        $this->get('/trends?range=28')->assertInertia(
            fn ($page) => $page->where('supplements.days', 28)->where('supplements.counted', 28)->etc()
        );
    }

    public function test_the_trends_page_says_nothing_when_there_is_nothing_to_say(): void
    {
        $this->get('/trends')->assertInertia(
            fn ($page) => $page->where('supplements', null)->etc()
        );
    }

    /**
     * The last `$count` local dates, most recent first.
     *
     * @return list<string>
     */
    private function lastDays(int $count): array
    {
        $today = CarbonImmutable::parse(self::TODAY);

        return array_map(
            static fn (int $back): string => $today->subDays($back)->toDateString(),
            range(0, $count - 1)
        );
    }

    private function adherence(): SupplementAdherence
    {
        return new SupplementAdherence;
    }
}
