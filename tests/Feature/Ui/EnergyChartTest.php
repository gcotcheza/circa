<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use Tests\Concerns\ReadsSource;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE ENERGY CHART HAS TO ANSWER THE QUESTION IT IS ASKED.
 *
 * The owner opened the trends page and said they did not understand what the
 * chart was trying to show. They were right: intake was a narrow mark inside the
 * expenditure bar, so the question the card exists for — did I eat more than I
 * burned — was asked of the eye as "compare the top of this bar with the height
 * of that annotation". The redraw is two bars, side by side, from one baseline.
 * Held here are the parts that can be undone by accident:
 *
 *   the two bars do not overlap and never share an x       (the encoding)
 *   intake carries its range as a whisker                  (SPEC's ranges rule)
 *   a day with no food logged is not a day that ate zero   (the honesty)
 *   a bar that is still accruing is faded, both sides      (the honesty)
 *   the verdict is READ from the server, never recomputed  (one answer per day)
 *
 * Half of it is structural, as in BalanceLineTest and WeekChartDrawInTest: this
 * half of the app has no server-side symptom when it breaks, so only a test that
 * reads the component proves the template draws two bars and not one bar and a
 * mark.
 */
final class EnergyChartTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const CHART = 'resources/js/Components/EnergyChart.vue';

    private const PAGE = 'resources/js/Pages/Trends.vue';

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', 'Europe/Amsterdam'));
    }

    // --- The payload -------------------------------------------------------

    /**
     * Every day carries the verdict the day view would give it: deciding which
     * was bigger a second time is how two screens start disagreeing.
     * BalanceLineTest holds BalanceCard to the same rule.
     */
    public function test_each_day_carries_the_same_balance_the_day_view_shows(): void
    {
        // Burned 1 900, ate 2 200: a surplus of 300, knowable.
        $this->summary('2026-06-14', out: [500, 1400], in: [2150, 2200, 2250], complete: true);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.5.date', '2026-06-14')
                ->where('days.5.balance.direction', 'surplus')
                ->where('days.5.balance.lo', fn (mixed $v): bool => (float) $v === 250.0)
                ->where('days.5.balance.hi', fn (mixed $v): bool => (float) $v === 350.0)
                ->where('days.5.balance.provisional', false)
                ->etc()
            );
    }

    /** A day with no summary at all has no balance rather than a zero one. */
    public function test_a_day_with_nothing_recorded_has_no_balance(): void
    {
        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.balance', null)
                ->where('balanceTally.days', 0)
                ->where('balanceTally.sentence', null)
                ->etc()
            );
    }

    /**
     * THE TALLY COUNTS FULLY LOGGED DAYS AND NOTHING ELSE. A part-logged day's
     * intake is a floor; counting it tells somebody who logs breakfast and
     * forgets dinner that they ate under their burn every day of their life.
     */
    public function test_a_partial_food_log_is_drawn_but_never_counted(): void
    {
        $this->summary('2026-06-11', out: [500, 1400], in: [2150, 2200, 2250], complete: true);
        $this->summary('2026-06-12', out: [500, 1400], in: [2150, 2200, 2250], complete: true);

        // Same numbers, incomplete log: a bar on the chart, not a vote.
        $this->summary('2026-06-13', out: [500, 1400], in: [2150, 2200, 2250], complete: false);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Drawn: the day still has its intake and its verdict.
                ->where('days.4.balance.direction', 'surplus')
                ->where('days.4.isCompleteLog', false)
                // Counted: two, not three.
                ->where('balanceTally.days', 2)
                ->where('balanceTally.surplus', 2)
                ->where('balanceTally.sentence', 'Ate over your burn on all 2 fully logged days.')
                ->etc()
            );
    }

    /**
     * Nor is a day whose burn is still accruing: a complete food log against a
     * third of a day's burn is a guaranteed surplus.
     */
    public function test_a_day_still_being_measured_is_not_counted(): void
    {
        $this->summary('2026-06-13', out: [500, 1400], in: [1400, 1450, 1500], complete: true);
        $this->summary('2026-06-14', out: [500, 1400], in: [1400, 1450, 1500], complete: true);

        $this->summary(
            self::TODAY,
            out: [80, 700],
            in: [1400, 1450, 1500],
            complete: true,
            partial: true,
        );

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.6.balance.provisional', true)
                ->where('balanceTally.days', 2)
                ->where('balanceTally.sentence', 'Ate under your burn on all 2 fully logged days.')
                ->etc()
            );
    }

    // --- The picture -------------------------------------------------------

    /**
     * TWO BARS, AND THEY CANNOT OVERLAP — the whole redraw. Intake starts one
     * bar-width and gap right of burn, so no arrangement of data puts one on top
     * of the other, which is what the previous version did by design.
     */
    public function test_intake_and_expenditure_are_drawn_as_two_separate_bars(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString('const eatX = groupX + bar + gap', $code);
        self::assertStringContainsString('bar: Math.max(1.2, (group - gap) / 2)', $code);

        // One width for both: comparable, not a slim marker inside the other.
        self::assertSame(
            2,
            substr_count($code, ':width="column.width"'),
            self::CHART.': the two bars no longer share a width, so their heights are no longer the '
                .'only thing that differs between them.'
        );

        // Same baseline, which is what makes comparing their tops mean anything.
        self::assertSame(
            2,
            substr_count($code, 'H - PAD_B - y(day.'),
            self::CHART.': a bar is no longer measured from the axis.'
        );
    }

    /**
     * The intake bar is the MIDPOINT, the range over it. Drawn to `max` the eye
     * reads the top of the uncertainty as the estimate — here, a surplus that may
     * not be there; drawn to `min` it reads the other way.
     */
    public function test_the_intake_bar_is_the_midpoint_and_the_range_is_a_whisker(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString('eatY: y(day.kcalIn.mid)', $code);
        self::assertStringContainsString('whiskerTop: y(day.kcalIn.max)', $code);
        self::assertStringContainsString('whiskerBottom: y(day.kcalIn.min)', $code);

        // Centred on its own bar, not on the column or on the burn bar.
        self::assertStringContainsString('whiskerX: eatX + bar / 2', $code);
    }

    /**
     * A DAY WITH NO FOOD LOGGED IS NOT A DAY THAT ATE NOTHING: same picture, no
     * bar, opposite facts, so the absence is stated — as the stress week chart
     * marks an unscored day.
     */
    public function test_a_day_with_nothing_logged_is_marked_rather_than_left_blank(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        // The dash is the bar's v-else: never both blank and unexplained.
        self::assertMatchesRegularExpression(
            '/v-if="column\.hasIntake".*?v-else/s',
            $code,
            self::CHART.': a day with no food logged now renders as empty space.'
        );

        // In the slot the bar would have used, so it reads as that bar missing.
        self::assertStringContainsString('dashX: eatX + bar / 2', $code);
        self::assertStringContainsString(':x="column.dashX"', $code);
    }

    /** Both fade for the reason the burn bar always has: the figure is a floor. */
    public function test_a_figure_that_is_still_accruing_is_faded_on_both_series(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString(
            'burnPartial: day.activeKcalIsPartial || !day.hasFullMetricCoverage',
            $code,
        );
        self::assertStringContainsString('eatPartial: !day.isCompleteLog', $code);

        self::assertStringContainsString("'opacity-45': column.burnPartial", $code);
        self::assertStringContainsString("'opacity-45': column.eatPartial", $code);
    }

    /**
     * Only the clauses this window needs: a key explaining a mark that is not on
     * screen teaches the reader to skip keys. Same rule as the stress legend.
     */
    public function test_the_chart_explains_its_own_marks_and_only_the_ones_present(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString('Two bars a day: what you burned, then what you ate.', $code);
        self::assertStringContainsString('The whisker is how wide that intake estimate is.', $code);
        self::assertStringContainsString('A faded bar is still adding up.', $code);
        self::assertStringContainsString('A dash under the axis is a day with no food logged.', $code);

        foreach (['hasWhisker', 'hasFaded', 'hasDash'] as $gate) {
            self::assertStringContainsString('if ('.$gate.'.value) parts.push(', $code);
        }
    }

    /**
     * The verdict in the tooltip is READ, never worked out again: the moment the
     * component subtracts, EnergyBalance's four states stop being testable and
     * this chart can disagree with the card the user taps through to.
     */
    public function test_the_chart_renders_the_servers_verdict_and_derives_none(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString('const { direction, lo, hi } = day.balance', $code);

        self::assertSame(
            0,
            preg_match('/kcalOut\s*-\s*day\.kcalIn|day\.kcalIn[^\n]*-\s*day\.kcalOut/', $code),
            self::CHART.' subtracts intake from burn in the browser. That arithmetic, and the four states '
                .'it decides between, belong in App\Services\Reporting\EnergyBalance.'
        );

        // A server string, not a sentence built where nothing holds its register.
        self::assertStringContainsString('v-if="tally?.sentence"', $code);
        self::assertStringContainsString('{{ tally.sentence }}', $code);
        self::assertStringContainsString(':tally="balanceTally"', $this->sourceWithoutComments(self::PAGE));
    }

    /**
     * A tooltip resolves to the nearest ANCESTOR `<title>`; on a sibling it
     * answers for that sibling alone — on a column of two 15-unit bars and a lot
     * of air, most of the column then says nothing under a thumb.
     */
    public function test_the_whole_column_carries_the_days_figures(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertMatchesRegularExpression(
            '/<g v-for="column in columns"[^>]*>\s*<title>\{\{ summary\(column\.day\) \}\}<\/title>/s',
            $code,
            self::CHART.": the day's <title> is no longer the group's own child, so it only answers for "
                .'whichever element it ended up inside.'
        );

        // And the air between and above the bars is part of the column.
        self::assertStringContainsString('fill="transparent"', $code);
    }

    /** The plot did not shrink to pay for the row under the axis. */
    public function test_the_new_row_under_the_axis_cost_the_card_height_and_not_the_chart(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        $height = $this->constant($code, 'H');
        $padB = $this->constant($code, 'PAD_B');
        $padT = $this->constant($code, 'PAD_T');

        // Baseline and drawing height as before the dash row: 106 and 100.
        self::assertSame(106, $height - $padB);
        self::assertSame(100, $height - $padB - $padT);
    }

    /** The value of a `const NAME = <number>` in the component's setup block. */
    private function constant(string $code, string $name): int
    {
        $found = preg_match('/const '.preg_quote($name, '/').' = (\d+)/', $code, $matches);

        self::assertSame(
            1,
            $found,
            self::CHART.": there is no `const {$name}` any more — this test reads the chart's own geometry.",
        );

        return (int) $matches[1];
    }

    // ---

    /**
     * @param  array{float|int, float|int}  $out  active, resting
     * @param  array{float|int, float|int, float|int}|null  $in  min, mid, max
     */
    private function summary(
        string $date,
        array $out,
        ?array $in = null,
        bool $complete = false,
        bool $partial = false,
    ): void {
        DailySummary::factory()->onDate($date)->create([
            'active_kcal'              => $out[0],
            'resting_kcal'             => $out[1],
            'kcal_in_min'              => $in[0] ?? null,
            'kcal_in_mid'              => $in[1] ?? null,
            'kcal_in_max'              => $in[2] ?? null,
            'is_complete_log'          => $complete,
            'active_kcal_is_partial'   => $partial,
            'has_full_metric_coverage' => ! $partial,
        ]);
    }
}
