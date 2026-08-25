<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * The supplements card is ONE ROW until it is asked to be more.
 *
 * WHY THESE ARE READ OFF THE SOURCE. Same reason ReportTabTest and
 * BalanceLineTest are: there is no component renderer here, and a DOM to mount
 * one Vue file would be a dependency and a build step for very little. What CAN
 * be pinned are the structural claims a person only notices on a phone and a
 * tidy-up would silently undo. The card used to be a row per bottle — five rows
 * of a phone screen, every day, for a fact almost always "yes, all of them" —
 * so the rows now live behind an expander and the default is the answer plus a
 * button:
 *
 *   IT STARTS SHUT, on every day including a past one with a partial count; an
 *   auto-open would reflow the whole day view on every arrow press.
 *   THE BUTTON IS OUTSIDE THE EXPANDER: behind a tap it would be two taps for
 *   the thing this redesign exists to make one.
 *   THE EVENING STATE IS ON THE COLLAPSED SHELL, or the nudge is behind a tap
 *   and is not a nudge.
 *   THE PRESS IS A LOOP OVER THE PER-BOTTLE WRITE: a bulk endpoint would break
 *   the offline queue's per-(bottle, day) de-duplication — see
 *   resources/js/lib/supplements.js.
 */
final class SupplementsCardTest extends TestCase
{
    use ReadsSource;

    private const CARD = 'resources/js/Components/SupplementsCard.vue';

    private const CLIENT = 'resources/js/lib/supplements.js';

    public function test_the_card_starts_collapsed(): void
    {
        self::assertStringContainsString('const expanded = ref(false)', $this->sourceWithoutComments(self::CARD));
    }

    /**
     * Nothing writes the expansion down — it is per-visit state. A card that
     * reopened itself tomorrow because of today would have a height that
     * depends on history.
     */
    public function test_the_expansion_is_not_persisted_anywhere(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringNotContainsString('localStorage', $code);
        self::assertStringNotContainsString('sessionStorage', $code);
    }

    /** The rows — the old card in full — are behind the expander. */
    public function test_the_per_bottle_rows_only_exist_once_it_is_opened(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        $expander = strpos($code, 'v-show="expanded"');
        $rows = strpos($code, 'v-for="item in items"');

        self::assertIsInt($expander);
        self::assertIsInt($rows);
        self::assertLessThan($rows, $expander, 'The per-bottle rows must sit inside the expanded block.');
    }

    /** One tap, not two: the button is on the collapsed line. */
    public function test_take_all_is_reachable_without_opening_the_card(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        $button = strpos($code, '@click="takeAll"');

        self::assertIsInt($button);
        self::assertLessThan(
            strpos($code, 'v-show="expanded"'),
            $button,
            'The Take all button must be outside the expanded block.'
        );

        // It goes away rather than sitting disabled once nothing is left.
        self::assertStringContainsString('v-if="!allTaken"', $code);
    }

    /** Tapping the row itself opens it, and says so to a screen reader. */
    public function test_the_row_is_the_expand_target(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringContainsString(':aria-expanded="expanded"', $code);
        self::assertStringContainsString('@click="expanded = !expanded"', $code);
    }

    /**
     * One number in one sense: taken out of how many, or in the evening with
     * something outstanding, what is left.
     */
    public function test_the_collapsed_line_carries_the_count(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringContainsString('${takenCount.value}/${items.value.length}', $code);
        self::assertStringContainsString('still to take', $code);
        self::assertStringContainsString('all taken', $code);
    }

    /** The amber edge is on the shell, above the expander, or it is not a nudge. */
    public function test_the_evening_state_is_on_the_collapsed_shell(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        $amber = strpos($code, 'border-amber-300');

        self::assertIsInt($amber);
        self::assertStringContainsString('wantsAttention', substr($code, 0, $amber));
        self::assertLessThan(strpos($code, 'v-show="expanded"'), $amber);
    }

    /** Exception handling survives: an opened row still un-ticks one bottle. */
    public function test_a_single_bottle_can_still_be_toggled(): void
    {
        self::assertStringContainsString(
            'setSupplementTaken(item.id, props.date, next)',
            $this->sourceWithoutComments(self::CARD)
        );
    }

    /**
     * The whole press is the per-bottle write, N times. One intake URL, built
     * from a supplement id — anything else is a bulk action the offline queue
     * cannot de-duplicate against a later un-tick.
     */
    public function test_take_all_loops_the_per_bottle_write(): void
    {
        $client = $this->sourceWithoutComments(self::CLIENT);

        self::assertStringContainsString('setSupplementTaken(item.id, date, true)', $client);
        self::assertSame(
            1,
            substr_count($client, '/intake'),
            'There must be exactly one intake URL in the client, and it is the per-supplement one.'
        );
        self::assertStringContainsString('/api/supplements/${supplementId}/intake', $client);
    }
}
