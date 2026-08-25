<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * THE BODY CARD DRAWS ITSELF — and, like its two neighbours, must never vanish.
 *
 * WeekChartDrawInTest has the long version — no hidden state outside
 * `@keyframes`, every `animation` behind `prefers-reduced-motion: no-preference`,
 * so a browser running none of it paints the finished chart rather than an empty
 * card — and asserts that file-wide, across every gate including this one.
 *
 * Here is only what this chart alone can get wrong. No duration is pinned: that
 * is a person looking at a phone, and the week chart's number has already moved
 * once on exactly that evidence. Four things are, each failing SILENTLY — the
 * chart still renders, it just stops meaning what it says: the journey is one
 * path with its bridges, the dash is longer than the path it hides, the bridge
 * wipe states both ends, and the draw replays on both chips. Each is argued
 * where it is asserted, below.
 */
final class BodyChartDrawInTest extends TestCase
{
    use ReadsSource;

    private const CSS = 'resources/css/app.css';

    private const CHART = 'resources/js/Components/WeightChart.vue';

    // ------------------------------- The guarantee -------------------------

    /**
     * Every rule that mentions this chart is inside the gate, and the COUNTING
     * makes that a test rather than a spot check: a sixth `.body-trend-draw` rule
     * outside the query hides a mark from a reader whose browser never runs the
     * animation that would reveal it — the fault nobody sees while developing.
     */
    public function test_the_whole_effect_is_behind_the_reduced_motion_gate(): void
    {
        $gate = $this->bodyGate();

        foreach (['.btd-line', '.btd-bridge', '.btd-ring', '.btd-num', '.btd-tail'] as $mark) {
            self::assertStringContainsString('.body-trend-draw '.$mark, $gate);
        }

        self::assertSame(5, substr_count($gate, 'animation:'));

        self::assertSame(
            substr_count($this->sourceWithoutBlockComments(self::CSS), '.body-trend-draw'),
            substr_count($gate, '.body-trend-draw'),
            self::CSS.': a `.body-trend-draw` rule sits outside the gate, so it applies to a reader who '
                .'asked for no motion.'
        );
    }

    /**
     * `backwards`, on all five: it holds a mark off screen for the length of its
     * delay. Without it every ring paints at once and is snatched back when its
     * own animation starts — on the All view, forty-seven rings blinking.
     */
    public function test_a_delayed_mark_waits_off_screen_rather_than_appearing_twice(): void
    {
        self::assertSame(5, substr_count($this->bodyGate(), 'backwards'));
    }

    /**
     * Linear on the pen and the wipe, eased on the rings. Ring delays are
     * fractions of ARC LENGTH, and of ELAPSED TIME only while the front moves at
     * constant speed — easing either carrier pulls every ring out of step with
     * the line it lands under. The softness is the rings' own.
     */
    public function test_the_front_moves_at_one_speed_so_the_delays_mean_what_they_say(): void
    {
        $gate = $this->bodyGate();

        foreach (['.btd-line', '.btd-bridge'] as $mark) {
            $rule = $this->blockAt($gate, '.body-trend-draw '.$mark);

            self::assertStringContainsString('linear', $rule);
            self::assertStringNotContainsString('ease', $rule);
        }

        self::assertStringContainsString(
            'cubic-bezier(',
            $this->blockAt($gate, '.body-trend-draw .btd-ring')
        );
    }

    /**
     * BOTH ends written out: `clip-path` animates DISCRETELY against `none`, so a
     * `from` alone would not wipe — the dashes would sit clipped away and flip
     * into place half way across. Same trap as the balance card's entrance.
     */
    public function test_the_bridge_wipe_states_both_ends(): void
    {
        $frames = $this->blockAt($this->sourceWithoutBlockComments(self::CSS), '@keyframes btd-wipe');

        self::assertSame(2, substr_count($frames, 'clip-path:'));

        // The start clips the bridge away from the right; the end clips nothing.
        self::assertStringContainsString('clip-path: inset(-4px 100% -4px -4px)', $frames);

        // Negative on the three still sides, so a 2-unit stroke's overhang is not shaved.
        self::assertSame(1, preg_match('/to\s*\{[^}]*-4px -4px -4px -4px/s', $frames));
    }

    // ------------------------------ The choreography -----------------------

    /** A ring lands when the line reaches it: delay measured in ink, not counted in readings. */
    public function test_the_rings_are_paced_by_arc_length_and_not_by_index(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        // Off coordinates the component already computes — no getTotalLength(),
        // so nothing waits on layout or on WebKit agreeing about a path.
        self::assertStringContainsString('Math.hypot(', $code);
        self::assertStringNotContainsString('getTotalLength', $code);

        self::assertStringContainsString('(distance / journey.value.total) * DRAW_MS', $code);
        self::assertStringContainsString('(length / journey.value.total) * DRAW_MS', $code);
    }

    /**
     * A gap is travel: it costs the pen its own length. Without that the front
     * teleports across a fifty-seven-day hole and starts the far run too early —
     * worst on the All view, with its eight runs and seven holes.
     */
    public function test_a_gap_is_travel_and_not_a_jump(): void
    {
        self::assertMatchesRegularExpression(
            '/crossings\.push\(\{ at: covered, length: span \}\)\s+covered \+= span/',
            $this->sourceWithoutComments(self::CHART),
            self::CHART.': a bridge no longer advances the pen, so everything after a gap arrives early.'
        );
    }

    /**
     * The dash is at least as long as the curve it covers: `Math.hypot` measures
     * the control polyline and the browser draws the longer spline through it, so
     * a pad below 1 paints every run's tail early. What the pad IS is the
     * component's own measurement; that it exceeds 1 is the test.
     */
    public function test_the_dash_is_padded_past_the_length_of_its_own_polyline(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        preg_match('/const CURVE_PAD = ([\d.]+)/', $code, $matches);

        // No match is a 0: renaming the constant away is the same bug as a wrong pad.
        self::assertGreaterThan(
            1.0,
            (float) ($matches[1] ?? 0),
            self::CHART.': the dash is now shorter than the path it hides, so the end of every run shows '
                .'through before the pen reaches it.'
        );

        self::assertStringContainsString('leg.length * CURVE_PAD', $code);
    }

    /** Every mark reads its own timing off the element it is drawn on. */
    public function test_the_chart_hands_every_mark_its_own_timing(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString("'--btd-len': String(shape.length)", $code);
        self::assertStringContainsString("'--btd-delay': `\${shape.delay}ms`", $code);
        self::assertStringContainsString("'--btd-dur': `\${shape.duration}ms`", $code);
        self::assertStringContainsString("'--btd-delay': `\${bridge.delay}ms`", $code);

        // Rings on a sparse window, dots on a crowded one — same mark, same
        // timing. Dots left out is the All view drawing over forty-odd readings.
        self::assertStringContainsString(':style="ringStyle(point)"', $code);
        self::assertStringContainsString(':style="dotStyle(point)"', $code);
        self::assertStringContainsString('`${arrivalOf(point.date)}ms`', $code);

        // A number follows its own ring rather than arriving with it.
        self::assertStringContainsString('`${arrivalOf(point.date) + LABEL_LAG_MS}ms`', $code);
    }

    /**
     * The quiet layers wait for the trend, and the thin raw line is not a second
     * pen: two strokes racing nearly the same path is twice the motion for one
     * story, and the thin line's job is to be found second.
     */
    public function test_the_wash_and_the_raw_line_arrive_after_the_trend(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString("'--btd-delay': `\${DRAW_MS + TAIL_MS}ms`", $code);
        self::assertStringContainsString(':style="washStyle"', $code);
        self::assertStringContainsString(':style="rawStyle"', $code);

        self::assertSame(
            1,
            substr_count($code, 'class="btd-line"'),
            self::CHART.': there is more than one pen now — the raw line fades in with the wash, it does '
                .'not draw itself.'
        );
    }

    /**
     * Both chips replay the draw. Vue patches the existing SVG in place, and a
     * CSS animation on an element that was never replaced does not run again — so
     * without a key the chart draws once, on whichever range it opened on, and
     * every chip after that swaps the geometry in silently.
     */
    public function test_the_draw_replays_when_either_chip_changes(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString(':key="drawKey"', $code);

        self::assertStringContainsString(
            "`\${series.value?.key ?? ''}/\${range.value?.key ?? ''}`",
            $code,
            self::CHART.': the key no longer changes with both chips, so one of them redraws the chart '
                .'without redrawing it.'
        );
    }

    // -----------------------------------------------------------------------

    /** The gate block this chart's rules live in. */
    private function bodyGate(): string
    {
        $css = $this->sourceWithoutBlockComments(self::CSS);
        $gate = '@media (prefers-reduced-motion: no-preference)';
        $from = 0;

        while (($found = mb_strpos($css, $gate, $from)) !== false) {
            $block = $this->blockAt(mb_substr($css, (int) $found), $gate);

            if (str_contains($block, '.body-trend-draw')) {
                return $block;
            }

            $from = (int) $found + mb_strlen($gate);
        }

        self::fail(self::CSS.': the body card has no reduced-motion gate at all.');
    }

    /** The text between the braces that follow $opener, brace-counted. */
    private function blockAt(string $css, string $opener): string
    {
        $found = mb_strpos($css, $opener);

        self::assertNotFalse($found, "Could not find `{$opener}` in the stylesheet.");

        $brace = mb_strpos($css, '{', (int) $found);

        self::assertNotFalse($brace, "No block after `{$opener}`.");

        $open = (int) $brace;
        $depth = 0;
        $length = mb_strlen($css);

        for ($i = $open; $i < $length; $i++) {
            $char = mb_substr($css, $i, 1);

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return mb_substr($css, $open + 1, $i - $open - 1);
                }
            }
        }

        self::fail("Unbalanced braces after `{$opener}`.");
    }
}
