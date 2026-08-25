<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\TdeeFixture;
use Tests\Concerns\ReadsSource;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What the Trends page puts first, and why it depends on the day.
 *
 * Read off the source for SupplementsCardTest's and ReportTabTest's reason: no
 * component renderer here, and a DOM to mount one Vue file is a dependency and a
 * build step for very little. Order on a phone screen is the kind of claim a
 * person notices immediately and a tidy-up undoes silently.
 *
 * The page opened with the TDEE card, which for the first fortnight is a
 * progress meter — "Collecting data", two bars and the rule behind them — so
 * Trends opened with a paragraph about why there is nothing to see yet, above
 * the charts anyone taps the tab for. So position IS state: COLLECTING to the
 * bottom, full height, read after the lines; ESTIMATE to the top, because a
 * back-calculated expenditure is the point of this app and the day it appears it
 * is the news. Both are the same component with the same props, chosen by one
 * computed off `tdee.status`, and the two payload assertions below keep that
 * pair EXHAUSTIVE — a third status would leave the card rendering nowhere.
 */
final class TrendsOrderTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const PAGE = 'resources/js/Pages/Trends.vue';

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', 'Europe/Amsterdam'));
    }

    /** Energy, then weight, then steps — the order they were designed in. */
    public function test_the_three_charts_keep_their_order_among_themselves(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        $energy = $this->at($code, '<EnergyChart');
        $weight = $this->at($code, '<WeightChart');
        $steps = $this->at($code, '<StepsChart');

        self::assertLessThan($weight, $energy);
        self::assertLessThan($steps, $weight);
    }

    /**
     * After every chart AND the two captions belonging to them, so it is genuinely
     * last rather than demoted one slot.
     */
    public function test_while_it_is_collecting_the_tdee_card_is_at_the_bottom(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        $collecting = $this->at($code, '<TdeeCard v-if="!hasTdeeEstimate"');

        foreach (['<EnergyChart', '<WeightChart', '<StepsChart', '{{ completeLogDays }}', 'v-if="supplements"'] as $earlier) {
            self::assertLessThan(
                $collecting,
                $this->at($code, $earlier),
                "{$earlier} must come before the collecting-state TDEE card."
            );
        }
    }

    /** And on the day there is a number, it is back above the charts. */
    public function test_once_there_is_an_estimate_the_tdee_card_is_at_the_top(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        $estimate = $this->at($code, '<TdeeCard v-if="hasTdeeEstimate"');

        foreach (['<EnergyChart', '<WeightChart', '<StepsChart'] as $later) {
            self::assertGreaterThan(
                $estimate,
                $this->at($code, $later),
                "The estimate-state TDEE card must come before {$later}."
            );
        }
    }

    /**
     * ONE card in one of two places — never two, never none. Same component, same
     * prop, only the gate differs; a divergence is two TDEE cards on one screen
     * the day the estimate lands.
     */
    public function test_the_two_positions_are_the_same_card_and_are_mutually_exclusive(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertSame(2, substr_count($code, '<TdeeCard '));
        self::assertStringContainsString('<TdeeCard v-if="hasTdeeEstimate" :tdee="tdee" />', $code);
        self::assertStringContainsString('<TdeeCard v-if="!hasTdeeEstimate" :tdee="tdee" />', $code);
    }

    /** The switch reads the card's own payload — no second query, so this is layout, not a feature. */
    public function test_the_position_is_decided_by_the_payload_the_card_already_carries(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertStringContainsString(
            "const hasTdeeEstimate = computed(() => props.tdee?.status === 'estimate')",
            $code
        );
    }

    /** The gated payload really does say `collecting`, so the bottom branch is the live one. */
    public function test_a_short_history_puts_the_page_in_the_collecting_state(): void
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
                ->component('Trends')
                ->where('tdee.status', 'collecting')
                ->etc()
            );
    }

    /** And a full one says `estimate` — the only other value the pair covers. */
    public function test_a_full_history_puts_the_page_in_the_estimate_state(): void
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
                ->component('Trends')
                ->where('tdee.status', 'estimate')
                ->etc()
            );
    }

    private function at(string $code, string $needle): int
    {
        $at = mb_strpos($code, $needle);

        self::assertNotFalse($at, "Trends.vue no longer contains: {$needle}");

        return $at;
    }
}
