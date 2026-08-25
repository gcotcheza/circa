<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;
use Tests\Support\TdeeFixture;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What the TDEE card is handed in both states. The gated payload is not a stub
 * but production today — the first two weeks a user sees — so every number in
 * it must be real: the card's promise is counting, not stalling.
 */
final class TdeeCardTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', 'Europe/Amsterdam'));
    }

    public function test_the_collecting_state_carries_honest_progress_and_the_rule_behind_it(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 22,
            'weight_kg'       => $i % 10 === 0 ? 70.0 : null,
        ]);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tdee.status', 'collecting')
                ->where('tdee.estimate', null)
                ->where('tdee.progress.days', 6)
                ->where('tdee.progress.daysNeeded', 14)
                ->where('tdee.progress.weighIns', 3)
                ->where('tdee.progress.weighInsNeeded', 8)
                ->where('tdee.needsMoreDays', true)
                ->where('tdee.needsMoreWeighIns', true)
                // Read from the config that decides it, so the card's sentence cannot drift from the rule.
                ->where('tdee.completeLogRule.minItems', 3)
                ->where('tdee.completeLogRule.lastMealAfter', '18:00')
                ->where('tdee.completeLogRule.kcalFloor', 1000)
                // Strict assertion, so written as the wire has them: whole floats arrive as ints.
                ->where('tdee.kcalPerKg', 7700)
                ->where('tdee.window.end', self::TODAY)
                ->etc()
            );

        self::assertSame(0, TdeeEstimate::query()->count());
    }

    public function test_an_empty_database_still_renders_a_countable_card(): void
    {
        // Production today: 101 summarised days, zero complete logs.
        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tdee.status', 'collecting')
                ->where('tdee.progress.days', 0)
                ->where('tdee.progress.weighIns', 0)
                ->where('tdee.window.days', 28)
                ->etc()
            );
    }

    public function test_the_active_state_is_a_range_with_the_window_it_came_from(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tdee.status', 'estimate')
                ->where('tdee.progress.days', 28)
                ->where('tdee.progress.weighIns', 28)
                ->where('tdee.window.start', '2026-05-19')
                ->where('tdee.window.end', self::TODAY)
                ->where('tdee.intakeMean', 2000)
                ->where('tdee.methodVersion', 'v1')
                ->has('tdee.computedAt')
                ->has('tdee.estimate', fn (AssertableInertia $estimate) => $estimate
                    ->has('min')->has('mid')->has('max')
                )
                ->etc()
            );

        $props = $this->get('/trends')->viewData('page')['props']['tdee'];

        // A RANGE, never a point.
        self::assertGreaterThan($props['estimate']['min'], $props['estimate']['max']);
        self::assertGreaterThan($props['estimate']['min'], $props['estimate']['mid']);
        self::assertLessThan($props['estimate']['max'], $props['estimate']['mid']);

        // ~2 102 here: the arithmetic answer is 2 110 less ~8 kcal of EMA start-up, and the range covers it.
        self::assertEqualsWithDelta(2110, $props['estimate']['mid'], 25);
        self::assertLessThan(2110, $props['estimate']['min']);
        self::assertGreaterThan(2110, $props['estimate']['max']);

        // 2 000 eaten against a ~2 100 burn is a deficit, and the sign says which way round.
        self::assertLessThan(0, $props['intakeVersusTdee']);
    }

    public function test_rendering_the_page_records_the_estimate_exactly_once(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);

        $this->get('/trends')->assertOk();

        $row = TdeeEstimate::query()->sole();
        $computedAt = $row->computed_at;

        // Same window whatever the range asks for, so the row and its clock stand.
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 11:00:00', 'Europe/Amsterdam'));
        $this->get('/trends')->assertOk();
        $this->get('/trends?range=28')->assertOk();

        self::assertSame(1, TdeeEstimate::query()->count());
        self::assertTrue($computedAt->equalTo(TdeeEstimate::query()->sole()->computed_at));
    }

    public function test_the_card_does_not_follow_the_range_buttons(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);

        // A week of charts must not re-fit expenditure over seven days of ±1 kg noise.
        $this->get('/trends?range=7')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('range', 7)
                ->where('tdee.window.days', 28)
                ->where('tdee.progress.days', 28)
                ->etc()
            );
    }

    public function test_the_daily_view_shows_one_line_only_once_there_is_an_estimate(): void
    {
        $this->get('/?date='.self::TODAY)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tdee', null)->etc());

        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);

        $this->get('/?date='.self::TODAY)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tdee.min')
                ->has('tdee.max')
                ->where('tdee.window.days', 28)
                ->etc()
            );
    }

    public function test_a_guest_sees_none_of_it(): void
    {
        app('auth')->logout();

        $this->get('/trends')->assertRedirect('/login');
    }

    /**
     * The copy once promised a number fitted "to you rather than to the average
     * of a study population", planting a population figure on the one screen
     * whose argument is that this app has no reference class but the user's own
     * history; the same request stripped population from the stress UI. Reading
     * the source stands in, deliberately, for the component renderer this suite
     * hasn't got — SupplementsCardTest argues that at length.
     */
    public function test_the_collecting_copy_measures_against_no_population(): void
    {
        $card = (string) file_get_contents(base_path('resources/js/Components/TdeeCard.vue'));

        self::assertStringNotContainsString('study population', $card);
        self::assertStringNotContainsString('rather than to the average', $card);

        // What it does instead of guessing is the point, so that copy is pinned too.
        self::assertStringContainsString('Most apps guess your burn from your height and age', $card);
        self::assertStringContainsString('it becomes a number fitted', $card);
    }
}
