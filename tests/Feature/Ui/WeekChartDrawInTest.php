<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * THE WEEK CHART DRAWS ITSELF — and the one thing it must never do is vanish.
 *
 * Whether the pen takes 760 ms or 950 ms to cross the week is a person looking
 * at a phone, not a test; nothing here pins the number, because it has already
 * been changed once on exactly that evidence and a test asserting it would have
 * failed for the sole reason that somebody looked. The failure mode is what is
 * testable, and it is nasty: the usual draw-in is `opacity: 0` in a base rule
 * plus an animation back to 1, so any browser that does not run the animation —
 * an old WebKit, a half-applied stylesheet, anything supporting `opacity` and
 * not `@keyframes` — applies the hiding half and never the revealing half, and
 * a week of stress data renders as an empty card at six in the morning.
 *
 * So the chart is written the other way round: THERE IS NO HIDDEN STATE
 * ANYWHERE EXCEPT INSIDE `@keyframes`, which are inert until an `animation`
 * property reaches them, and every `animation` is behind
 * `prefers-reduced-motion: no-preference`. Reduced motion is then not a special
 * case to maintain but what the stylesheet says with the animation block
 * skipped — the same thing an unsupported browser sees. The choreography rests
 * on a second decision that can be silently undone: the delays are ARC LENGTHS
 * along the line, equal to elapsed time only while the line's own timing
 * function is linear. Ease the line and every dot drifts out of step.
 */
final class WeekChartDrawInTest extends TestCase
{
    use ReadsSource;

    private const CSS = 'resources/css/app.css';

    private const CHART = 'resources/js/Components/StressWeekChart.vue';

    // --- The guarantee -------------------------------------------------------

    /**
     * Nothing that hides anything may live outside a `@keyframes` block: a
     * keyframe is unreachable without an `animation` property, and every one of
     * those is gated below, whereas a plain rule applies the moment the
     * stylesheet parses.
     */
    public function test_no_rule_can_hide_the_chart(): void
    {
        $outside = $this->withoutKeyframes($this->sourceWithoutBlockComments(self::CSS));

        $hiding = [
            // `(?<![\w-])` keeps the band palette's
            // `--stress-field-opacity: 0.22` out of this.
            'opacity: 0'          => '/(?<![\w-])opacity:\s*0\s*[;}]/',
            'visibility: hidden'  => '/(?<![\w-])visibility:\s*hidden/',
            'display: none'       => '/(?<![\w-])display:\s*none/',
            'transform: scale(0)' => '/(?<![\w-])transform:[^;}]*scale\(\s*0\s*\)/',

            // The balance card wipes in with `clip-path`, which hides as
            // thoroughly as `opacity` and is easier to leave behind: an inset
            // of 100% from any side clips the element away entirely.
            'clip-path: inset(… 100% …)' => '/(?<![\w-])clip-path:[^;}]*100%/',
        ];

        foreach ($hiding as $what => $pattern) {
            self::assertDoesNotMatchRegularExpression(
                $pattern,
                $outside,
                self::CSS.": `{$what}` outside a @keyframes block is a chart that stays invisible on any "
                    .'browser that does not run the animation.'
            );
        }
    }

    /** And every animation that reaches those keyframes is behind the gate. */
    public function test_every_animation_is_behind_the_reduced_motion_gate(): void
    {
        $css = $this->sourceWithoutBlockComments(self::CSS);

        $gate = $this->reducedMotionBlock($css);

        self::assertStringContainsString('.stress-week-draw .swd-line', $gate);
        self::assertStringContainsString('.stress-week-draw .swd-dot', $gate);
        self::assertStringContainsString('.stress-week-draw .swd-num', $gate);
        self::assertStringContainsString('.stress-week-draw .swd-train', $gate);

        // All four animations are in there.
        self::assertSame(4, substr_count($gate, 'animation:'));

        /*
         * And NOTHING in the stylesheet animates outside a gate. This was once
         * a whole-file `assertSame(4, substr_count($css, 'animation:'))`, which
         * said the same thing while the week chart was the app's only animated
         * thing; once the balance card gained an entrance of its own, bumping
         * that number would have turned a rule about the whole stylesheet into
         * a rule about one feature. So it is counted the way it was always
         * meant: every `animation:` anywhere in the file is inside SOME
         * `prefers-reduced-motion: no-preference` block.
         */
        $gated = array_sum(array_map(
            static fn (string $block): int => substr_count($block, 'animation:'),
            $this->reducedMotionBlocks($css)
        ));

        self::assertSame(
            substr_count($css, 'animation:'),
            $gated,
            self::CSS.': something animates outside a `prefers-reduced-motion: no-preference` block, so a '
                .'phone set to reduce motion will run it anyway.'
        );

        // The dash is what makes an undrawn line invisible, so it is gated too:
        // with the preference set there is no dash array at all and the line is
        // byte-for-byte the one that predates this feature. Counted across
        // every gate for the same reason the animations are — the body card now
        // dashes a trend of its own — so that no rule anywhere dashes a line
        // for a reader who never sees it drawn.
        self::assertStringContainsString('stroke-dasharray:', $gate);

        $dashed = array_sum(array_map(
            static fn (string $block): int => substr_count($block, 'stroke-dasharray:'),
            $this->reducedMotionBlocks($css)
        ));

        self::assertSame(
            substr_count($css, 'stroke-dasharray:'),
            $dashed,
            self::CSS.': a `stroke-dasharray:` sits outside the gate, so a line is dashed for everybody — '
                .'animation or no animation.'
        );
    }

    /**
     * The gate is `no-preference`, not `reduce` switching things off
     * afterwards. The difference is the browser that supports neither:
     * `no-preference` never matches there, so nothing animates and nothing
     * hides, whereas the other way round the animation applies and only the
     * opt-out is missing.
     */
    public function test_the_gate_is_stated_positively(): void
    {
        $css = $this->sourceWithoutBlockComments(self::CSS);

        self::assertStringContainsString('@media (prefers-reduced-motion: no-preference)', $css);
        self::assertStringNotContainsString('prefers-reduced-motion: reduce', $css);
    }

    // --- The choreography ----------------------------------------------------

    /** A dot lands when the line reaches it: its delay is ink, not days. */
    public function test_the_dots_are_paced_by_arc_length_and_not_by_index(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        // Measured off coordinates the component already computes — no
        // getTotalLength(), so nothing waits on layout or on WebKit.
        self::assertStringContainsString('Math.hypot(', $code);
        self::assertStringContainsString('covered / total', $code);
        self::assertStringContainsString('travel.value.at[index] * DRAW_MS', $code);

        // Otherwise a leg with a day missing draws nothing yet still costs
        // time, or the front teleports the hole and lands early.
        self::assertMatchesRegularExpression(
            '/a\.score === null \|\| b\.score === null\s*\?\s*step\.value/',
            $code,
            self::CHART.': an unscored day no longer costs the pen any travel, so everything after a gap '
                .'arrives too early.'
        );
    }

    /**
     * Linear, and only linear, on the line itself: the delays above are
     * fractions of ARC LENGTH, and fractions of ELAPSED TIME only while the
     * front moves at constant speed. Easing the dash breaks every one of them.
     */
    public function test_the_line_is_linear_so_the_delays_mean_what_they_say(): void
    {
        $line = $this->declarationFor($this->reducedMotionBlock($this->sourceWithoutBlockComments(self::CSS)), '.stress-week-draw .swd-line');

        self::assertStringContainsString('linear', $line);
        self::assertStringNotContainsString('ease', $line);

        // The softness is the dot's, not the line's.
        $dot = $this->declarationFor($this->reducedMotionBlock($this->sourceWithoutBlockComments(self::CSS)), '.stress-week-draw .swd-dot');

        self::assertStringContainsString('cubic-bezier(', $dot);
    }

    /**
     * `backwards`, on all four: it holds a mark off screen for the length of
     * its delay. Without it every dot is painted at once and then disappears
     * when its animation starts, which is worse than no animation at all.
     */
    public function test_a_delayed_mark_waits_off_screen_rather_than_appearing_twice(): void
    {
        self::assertSame(4, substr_count($this->reducedMotionBlock($this->sourceWithoutBlockComments(self::CSS)), 'backwards'));
    }

    /** The line, the dot and the number all read their timing off the element. */
    public function test_the_chart_hands_every_mark_its_own_timing(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString("'--swd-len': String(segment.length)", $code);
        self::assertStringContainsString("'--swd-delay': `\${segment.delay}ms`", $code);
        self::assertStringContainsString("'--swd-dur': `\${segment.duration}ms`", $code);

        // Ring and coloured dot are one mark in two elements sharing a delay;
        // the number follows its dot rather than arriving with it.
        self::assertStringContainsString(':style="delayStyle(point.delay)"', $code);
        self::assertStringContainsString(':style="dotStyle(point)"', $code);
        self::assertStringContainsString(':style="delayStyle(point.delay + LABEL_LAG_MS)"', $code);

        // Training marks are context and wait for the week to finish.
        self::assertStringContainsString(':style="delayStyle(DRAW_MS + TAIL_MS)"', $code);
    }

    /**
     * Browsing to another week draws it again. Vue patches the existing SVG in
     * place on a prop swap, and a CSS animation on an element that was never
     * replaced does not run twice — so without a key the effect happens once,
     * on whichever week the user first lands on.
     */
    public function test_the_draw_replays_when_the_week_changes(): void
    {
        $code = $this->sourceWithoutComments(self::CHART);

        self::assertStringContainsString(':key="weekKey"', $code);

        self::assertStringContainsString(
            "props.days.map((day) => day.date).join('/')",
            $code,
            self::CHART.': the key no longer changes with the week, so the chart draws itself once and '
                .'never again.'
        );
    }

    // -----------------------------------------------------------------------

    private const GATE = '@media (prefers-reduced-motion: no-preference)';

    /** The body of the FIRST gate block, which is the week chart's. */
    private function reducedMotionBlock(string $css): string
    {
        return $this->blockAt($css, self::GATE);
    }

    /**
     * Every gate block in the file — one per animated feature, so a feature's
     * rules die with the feature instead of being picked out of a shared media
     * query, and the guarantee is about all of them at once.
     *
     * @return list<string>
     */
    private function reducedMotionBlocks(string $css): array
    {
        $blocks = [];
        $from = 0;

        while (($found = mb_strpos($css, self::GATE, $from)) !== false) {
            $blocks[] = $this->blockAt(mb_substr($css, (int) $found), self::GATE);
            $from = (int) $found + mb_strlen(self::GATE);
        }

        self::assertNotSame([], $blocks, self::CSS.': the reduced-motion gate has gone.');

        return $blocks;
    }

    /**
     * Everything that is NOT inside a `@keyframes` block. One level of nesting
     * is all such a block has (`from { ... }`), so a pattern allowing exactly
     * one is enough and stays readable.
     */
    private function withoutKeyframes(string $css): string
    {
        return (string) preg_replace('/@keyframes[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $css);
    }

    /** One rule's declarations, by selector, within $css. */
    private function declarationFor(string $css, string $selector): string
    {
        return $this->blockAt($css, $selector);
    }

    /**
     * The text between the braces that follow $opener. Brace-counted rather
     * than regexed, because a media block contains rules with braces of their
     * own.
     */
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
