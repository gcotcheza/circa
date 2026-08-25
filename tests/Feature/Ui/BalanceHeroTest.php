<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * THE BALANCE IS THE CARD — the first screen, held to what it promises.
 *
 * Arithmetic is EnergyBalanceTest's; the wire (props carry a direction and two
 * magnitudes, the component renders rather than subtracts) BalanceLineTest's;
 * the geometry tests/js/balance.test.js's. Left over are four promises about how
 * the card BEHAVES, each undoable by a harmless-looking change:
 *
 *   1. The hero is ONE UNSIGNED number; shrink it or give it back a sign and
 *      the card is the one it replaced.
 *   2. A METER centred on break-even, placed from lib/balance.js — a percentage
 *      computed in the template is untestable, and the single track with the
 *      floating block (the user called it a slider) must not return.
 *   3. Today is live, yesterday is not: a pulsing dot on a finished day lies.
 *   4. The entrance can be switched off and the card is still there — a blank
 *      first screen because a keyframe did not fire is the worst bug here.
 *
 * Durations (720 ms, 900 ms) go untested: they are a person looking at a phone,
 * and pinning them fails the moment somebody looks. WeekChartDrawInTest takes
 * the same line and holds the stylesheet-wide half of promise 4.
 */
final class BalanceHeroTest extends TestCase
{
    use ReadsSource;

    private const CARD = 'resources/js/Components/BalanceCard.vue';

    private const MATHS = 'resources/js/lib/balance.js';

    private const CSS = 'resources/css/app.css';

    // --- 1. The balance is the hero ---------------------------------------

    /**
     * ONE number, in display type, in the direction's colour. `heroFigure` is
     * tested in tests/js/balance.test.js; this holds that the card puts it in the
     * big slot with `rangeLine` demoted beneath, not a signed range in 40 px.
     */
    public function test_the_balance_number_is_the_biggest_thing_on_the_card(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        // `headline` not `balance`: a running day puts the PROJECTION in the big
        // slot (DayPace). Either way the hero is one function of ONE object.
        self::assertStringContainsString('const figure = computed(() => heroFigure(headline.value))', $code);
        self::assertStringContainsString('const headline = computed(() => projection.value ?? balance.value)', $code);
        self::assertStringContainsString('class="bal-figure', $code, self::CARD.': the hero number has lost its slot.');

        // Tabular and fluid: the old fixed size overflowed the straddling case.
        self::assertMatchesRegularExpression(
            '/tnum text-\[clamp\([^\]]+\)\][^"]*font-semibold/',
            $code,
            self::CARD.': the hero number is no longer set in fluid display type.'
        );

        // The width is demoted, not dropped, and comes from the tested module.
        self::assertStringContainsString('const rangeText = computed(() => rangeLine(headline.value))', $code);
        self::assertStringContainsString('v-if="rangeText"', $code, self::CARD.': the range line has no place to render.');

        // The end label still SAYS a band; `band()` refuses the midpoint collapse.
        self::assertStringContainsString('value: band(kcalIn.value)', $code);
    }

    /** Direction is said four ways over, so colour is never carrying it alone. */
    public function test_the_direction_is_never_only_a_colour(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);
        $maths = $this->sourceWithoutComments(self::MATHS);

        // The arrow, and the ink.
        self::assertStringContainsString("deficit: { arrow: '▼', tone: 'text-teal-600", $code);
        self::assertStringContainsString("surplus: { arrow: '▲', tone: 'text-orange-600", $code);
        self::assertStringContainsString("balanced: { arrow: '≈', tone: 'text-stone-500", $code);

        // The word.
        self::assertStringContainsString('const word = computed(() => anchorWord(headline.value))', $code);

        // Fourth channel: the meter's side, so direction is in the picture.
        self::assertStringContainsString("const underBar = computed(() => bar(geometry.value?.under, 'right'))", $code);
        self::assertStringContainsString("const overBar = computed(() => bar(geometry.value?.over, 'left'))", $code);

        /*
         * The sign is deliberately GONE — the user asked: "It's red, so I know I
         * have eaten more than I had burned off". By the digits, direction has
         * been said four ways. The "eaten − burned" caption went too; the axis
         * labels state the convention. Both rejected — ask before restoring.
         */
        self::assertStringNotContainsString(
            "balance.direction === 'surplus' ? '+' : '−'",
            $maths,
            self::MATHS.': the hero has grown a sign again. Direction is the word, the arrow, the '
                .'colour and the side of the meter — the user asked for the sign to go.'
        );

        self::assertStringNotContainsString(
            'eaten − burned',
            $code,
            self::CARD.': the sign-convention caption is back on a number that has no sign.'
        );

        // The axis says it instead, in the two words the spoken label uses.
        self::assertStringContainsString('>under</span>', $code);
        self::assertStringContainsString('>over</span>', $code);
    }

    // --- 2. A meter centred on break-even, not drawn by the template -------

    /** A zero-centred meter, every number on it computed where it can be checked. */
    public function test_the_meter_is_placed_from_the_tested_module(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        // Handed A BALANCE OBJECT and nothing else — no intake, no burn, so no
        // subtraction is reachable from here.
        self::assertStringContainsString(
            'const geometry = computed(() => meter(headline.value))',
            $code,
            self::CARD.': the meter no longer takes its geometry from lib/balance.js.'
        );

        // The pieces, each placed at a percentage it was handed.
        foreach (['side.pct', 'side.background', 'geometry.value?.under', 'geometry.value?.over'] as $piece) {
            self::assertStringContainsString($piece, $code, self::CARD.": {$piece} has gone.");
        }

        self::assertStringContainsString('drop-shadow(0 0 6px ${geometry.value.glow})', $code);

        // The centre is MARKED: a meter whose zero is not drawn is a slider
        // again, and both anchors pin to 50% so the bar grows out of the tick.
        self::assertMatchesRegularExpression(
            '/left-1\/2[^"]*-translate-x-1\/2[^"]*bg-stone-300/',
            $code,
            self::CARD.': break-even is no longer marked on the rail.'
        );

        self::assertStringContainsString(
            "[anchor]: '50%'",
            $code,
            self::CARD.': the bar is no longer anchored at the centre of the rail.'
        );

        // The single track is GONE, not merely unused: a grey rail with a block
        // part-way along it is what the user called "the visual slider".
        foreach (['bal-overhang', 'commonPct', 'fromPct', 'toPct', 'marksBurn', 'leadsWith', 'soloTrack'] as $ghost) {
            self::assertStringNotContainsString(
                $ghost,
                $code,
                self::CARD.": the single-track design is back ({$ghost}). It shipped once and the person "
                    .'who uses this app called it a slider.'
            );
        }

        // The module owns the floor and its half, so the card names neither.
        foreach (['2600', '1300'] as $floor) {
            self::assertStringNotContainsString(
                $floor,
                $code,
                self::CARD.' holds a copy of the meter\'s floor. One of the two will be changed alone.'
            );
        }
    }

    /**
     * The module reads the direction, never decides one: the server chooses the
     * word after rounding, and a second opinion in the browser would show a teal
     * bar under a grey word.
     */
    public function test_the_geometry_looks_the_direction_up_rather_than_deriving_it(): void
    {
        $maths = $this->sourceWithoutComments(self::MATHS);

        self::assertStringContainsString('PAINT[direction] ?? PAINT.balanced', $maths);
        self::assertStringContainsString('WORDS[balance?.direction] ?? WORDS.balanced', $maths);

        self::assertSame(
            0,
            preg_match("/direction\s*=\s*['\"](surplus|deficit|balanced)/", $maths),
            self::MATHS.' assigns a direction. That decision is made once, on the server, in '
                .'App\Services\Reporting\EnergyBalance.'
        );

        // "so far" and the word are both chosen by `provisional`.
        self::assertStringContainsString('${word} so far', $maths);

        // Nor by accident: it never sees the two numbers a direction comes FROM,
        // which is the rule this module was split out to enforce.
        foreach (['kcalIn', 'kcalOut'] as $ingredient) {
            self::assertStringNotContainsString(
                $ingredient,
                $maths,
                self::MATHS.": {$ingredient} is back. The moment this module can see both halves of the "
                    .'subtraction it can disagree with App\Services\Reporting\EnergyBalance about the answer.'
            );
        }
    }

    // --- 3. Today is live; yesterday is finished ---------------------------

    /** The grey "Provisional" pill is gone, and its replacement skips a day that is over. */
    public function test_the_live_dot_belongs_to_today_alone(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringNotContainsString(
            'Provisional',
            $code,
            self::CARD.': the apology pill is back. A day in progress is a state, not a disclaimer.'
        );

        self::assertMatchesRegularExpression(
            '/v-if="isToday"[^>]*>\s*<span class="relative inline-flex size-2/s',
            $code,
            self::CARD.': the live dot is no longer gated on today.'
        );

        self::assertStringContainsString('day in progress', $code);
        self::assertStringContainsString('class="bal-live-ring', $code);

        // The chip that only makes sense on a finished day still waits for one.
        self::assertStringContainsString('v-else-if="!flags.hasFullMetricCoverage"', $code);
    }

    /** Every caveat the two-bar card carried is still on this one. */
    public function test_the_honesty_chips_all_survived_the_redesign(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        foreach ([
            'Incomplete metric coverage',
            'burn is a floor',
            'Log looks incomplete',
            'Nothing logged yet',
            'No expenditure data for this day.',
        ] as $caveat) {
            self::assertStringContainsString($caveat, $code, self::CARD.": \"{$caveat}\" has gone.");
        }

        // The split behind the burn figure — stated nowhere else in the app.
        self::assertStringContainsString('active', $code);
        self::assertStringContainsString('resting', $code);
    }

    // --- 4. The entrance is optional; the card is not ----------------------

    /** All five of this card's animations are inside a gate. */
    public function test_the_entrance_is_behind_the_reduced_motion_gate(): void
    {
        $gate = $this->balanceGate();

        foreach (['.bal-reveal', '.bal-word', '.bal-figure', '.bal-ends', '.bal-live-ring'] as $selector) {
            self::assertStringContainsString($selector, $gate, self::CSS.": {$selector} animates outside the gate.");
        }

        self::assertSame(5, substr_count($gate, 'animation:'));

        // Four entrances, all `backwards`, so a delayed piece waits off screen
        // instead of being painted and snatched back. The fifth is the live
        // dot's pulse: forever, from once, so a fill mode would mean nothing.
        self::assertSame(4, substr_count($gate, 'backwards'));
        self::assertSame(1, substr_count($gate, 'infinite'));
    }

    /**
     * BOTH ends are written out, which the week chart does not need: `clip-path`
     * animates DISCRETELY against `none`, so a lone `from` would sit clipped away
     * and flip into place half way through instead of interpolating.
     */
    public function test_the_grow_states_both_ends(): void
    {
        $css = $this->sourceWithoutBlockComments(self::CSS);

        $frames = $this->blockAt($css, '@keyframes bal-grow');

        self::assertSame(2, substr_count($frames, 'clip-path:'));

        // Zero width AT THE CENTRE, ending clipping nothing: a day pushed out of
        // break-even, not uncovered from one end. The old track's left-to-right
        // wipe is what made the picture read as a value sliding along a rail.
        self::assertStringContainsString('clip-path: inset(-40px 50% -40px 50%)', $frames);
        self::assertStringContainsString('clip-path: inset(-40px -40px -40px -40px)', $frames);

        self::assertStringNotContainsString(
            'inset(-40px 100%',
            $frames,
            self::CSS.': the meter is wiping in from one end again.'
        );

        // Negative top and bottom, so the glow is not sliced off on the way in.
        self::assertSame(1, preg_match('/to\s*\{[^}]*-40px -40px -40px -40px/s', $frames));
    }

    /**
     * Stepping to another day plays it again: Vue patches these divs in place,
     * and a CSS animation on an element that was never replaced does not run
     * twice, so without a key the entrance happens once ever.
     */
    public function test_the_entrance_replays_when_the_day_changes(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringContainsString(':key="revealKey"', $code);

        self::assertStringContainsString(
            "[kcalIn.value.min, kcalIn.value.mid, kcalIn.value.max, kcalOut.value, headline.value?.direction].join('/')",
            $code,
            self::CARD.': the key no longer changes with the day, so the card animates once and never again.'
        );
    }

    // ---

    /** The gate block this card's rules live in. */
    private function balanceGate(): string
    {
        $css = $this->sourceWithoutBlockComments(self::CSS);
        $gate = '@media (prefers-reduced-motion: no-preference)';
        $from = 0;

        while (($found = mb_strpos($css, $gate, $from)) !== false) {
            $block = $this->blockAt(mb_substr($css, (int) $found), $gate);

            if (str_contains($block, '.bal-')) {
                return $block;
            }

            $from = (int) $found + mb_strlen($gate);
        }

        self::fail(self::CSS.': the balance card has no reduced-motion gate at all.');
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
