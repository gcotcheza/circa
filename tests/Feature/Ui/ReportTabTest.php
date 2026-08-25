<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * The two things about this feature's front end that a props assertion cannot
 * reach, and that a person would only notice on a phone.
 *
 * There is no component renderer here — the JS suite is `node --test` over the
 * two lib modules that survive browser-only faults, and a DOM to render four Vue
 * files would be a dependency and a build step for very little. So the two
 * claims that have to hold get read out of the templates, exactly as
 * BalanceLineTest and ModelNoteVisibilityTest do:
 *
 *   THE DISCLAIMER IS UNCONDITIONAL — on the empty state, under a failed report
 *   and under a finished one. A `v-if` added later by somebody tidying up would
 *   remove the sentence saying this is not medical advice from the screen where
 *   it matters most.
 *
 *   THE MICRONUTRIENT TABLE NEVER ADDS THE TWO KINDS OF NUMBER TOGETHER. The
 *   supplement figure and the food figure are separate rows in a description
 *   list, and an absent food figure reads "not tracked", because a blank cell
 *   reads as zero and zero is the one thing it is not.
 */
final class ReportTabTest extends TestCase
{
    use ReadsSource;

    private const PAGE = 'resources/js/Pages/Report.vue';

    private const BODY = 'resources/js/Components/ReportBody.vue';

    private const LAYOUT = 'resources/js/Layouts/AppLayout.vue';

    public function test_the_bottom_nav_has_a_report_tab(): void
    {
        $layout = $this->source(self::LAYOUT);

        self::assertStringContainsString('href="/report"', $layout);

        /*
         * `startsWith` rather than equality: a single report has a URL of its
         * own (/report/42) and the tab must stay lit while one is open. The
         * other three nav items are single pages and compare exactly.
         */
        self::assertStringContainsString("current.startsWith('/report')", $layout);
    }

    public function test_the_nav_still_has_exactly_four_items(): void
    {
        $layout = $this->source(self::LAYOUT);

        // Day, Trends, Stress, Report. A fifth would not fit a thumb on a
        // 6.7-inch screen; the layout's own comment says this nav is full.
        self::assertSame(4, substr_count($layout, 'flex flex-1 flex-col items-center'));
    }

    /** The sentence that says what this is, on every view of the tab. */
    public function test_the_disclaimer_is_unconditional(): void
    {
        $page = $this->source(self::PAGE);

        self::assertStringContainsString('not medical', mb_strtolower($page));
        self::assertStringContainsString('not a diagnosis', mb_strtolower($page));
        self::assertStringContainsString('written by a language model', mb_strtolower($page));

        // The paragraph it lives in must not be conditional.
        $tag = $this->openingTagBefore($page, 'These reports are written by a language model');

        self::assertStringNotContainsString('v-if', $tag);
        self::assertStringNotContainsString('v-show', $tag);
    }

    /**
     * The micronutrient section is the one place in this app where two kinds of
     * number sit side by side, and the design exists to keep them apart.
     */
    public function test_the_micronutrient_row_labels_both_sides_and_never_totals_them(): void
    {
        $body = $this->source(self::BODY);

        self::assertStringContainsString('Supplement', $body);
        self::assertStringContainsString('not tracked', $body);
        self::assertStringContainsString('% of intake covered', $body);

        // No arithmetic in the template: a combined figure is the model's
        // sentence, written under a rule forbidding the addition of an exact
        // number to an unknown one.
        self::assertStringNotContainsString('foodCoveragePct +', $body);
        self::assertStringNotContainsString('supplementExact +', $body);
    }

    /** The evidence line makes an observation checkable; an uncheckable one should not be acted on. */
    public function test_observations_render_their_evidence(): void
    {
        $body = $this->source(self::BODY);

        self::assertStringContainsString('item.observation', $body);
        self::assertStringContainsString('item.evidence', $body);
    }

    /**
     * A failed report has no `body`, so the page must render it rather than
     * throw on a null — the row carries the reason instead.
     */
    public function test_a_failed_report_has_its_own_branch_before_the_body_is_read(): void
    {
        $body = $this->source(self::BODY);

        self::assertMatchesRegularExpression(
            '/v-if="report\.status === \'failed\'".*v-else-if="body"/s',
            $body,
            'The failed branch must come before the body branch, or a failed report dereferences a null.',
        );
    }

    /**
     * The markup tag that opens the element containing $needle. Deliberately
     * crude — back to the previous `<`, forward to the matching `>` — which is
     * enough to answer "is this element conditional?" without a parser.
     */
    private function openingTagBefore(string $source, string $needle): string
    {
        $at = mb_strpos($source, $needle);

        self::assertNotFalse($at, "Could not find: {$needle}");

        $open = mb_strrpos(mb_substr($source, 0, $at), '<');

        self::assertNotFalse($open);

        $close = mb_strpos($source, '>', $open);

        return mb_substr($source, $open, $close - $open + 1);
    }
}
