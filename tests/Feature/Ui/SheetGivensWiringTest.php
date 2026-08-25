<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * THE ASK CARRIES THE SHEET — the client half.
 *
 * tests/Feature/Vision/SheetGivensTest.php proves what the SERVER does with a
 * corrected value: it becomes a given, is described to the model as one, is
 * recorded on the audit row and is restored over the answer. None of that is
 * worth anything if the sheet does not send it, and the sheet is JavaScript in a
 * project with no JS test runner — so this pins the wiring by reading it, as
 * QuickTabConversionTest and ConfirmRequestShapeTest do. The bug was ENTIRELY
 * here: both endpoints could always have been told, and the button sent an
 * idempotency key and nothing else.
 */
final class SheetGivensWiringTest extends TestCase
{
    use ReadsSource;

    private const SHEET = 'resources/js/Components/ProposalReview.vue';

    private const CLIENT = 'resources/js/lib/vision.js';

    /** Both "ask again" buttons carry the sheet's state. */
    public function test_both_re_runs_send_what_the_sheet_is_holding(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertStringContainsString(
            'const stated = statedItems()',
            $code,
            self::SHEET.': the re-run no longer collects what the user has corrected.'
        );

        self::assertStringContainsString(
            'entryShare.value, hintValue.value, stated)',
            $code,
            self::SHEET.': "Analyse this photo again" no longer carries the sheet\'s corrections '
                .'alongside the chip row and the note.'
        );

        self::assertStringContainsString(
            'estimateMeal(props.meal.uuid, crypto.randomUUID(), stated)',
            $code,
            self::SHEET.': "Estimate this again" no longer carries the sheet\'s corrections — which is the '
                .'original bug: the server then rebuilds the question from the previous ANSWER.'
        );
    }

    /**
     * A given is a value the user touched AND settled on. An untouched range is
     * the answer being questioned, not a claim; a range narrowed but left open
     * is still uncertainty, which a single number cannot express.
     */
    public function test_a_given_is_touched_and_zero_width(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertStringContainsString('if (!touched) return null', $code, self::SHEET.': an untouched proposal would be sent as though the user had typed it.');

        self::assertStringContainsString(
            'return Number(min) === Number(max) ? Number(min) : null',
            $code,
            self::SHEET.': a range that is still a range would be sent as a single figure — a certainty '
                .'the user did not express.'
        );

        // Matched by ROW ID, so adding or removing a line does not make the
        // lines below it look edited.
        self::assertStringContainsString('const before = asLoaded.value.get(item.id)', $code);

        // And the snapshot is rebuilt whenever a fresh answer lands.
        self::assertStringContainsString('asLoaded.value = loadedById()', $code);
    }

    /**
     * Nothing corrected, nothing sent — not an optimisation. With no edits the
     * sheet is the previous answer read back, and sending it as the question
     * would hand the model its own numbers as though a person had typed them,
     * the exact failure MealEstimateController::typedFrom exists to avoid.
     */
    public function test_an_untouched_sheet_sends_nothing(): void
    {
        self::assertStringContainsString(
            'return stated ? items : null',
            $this->sourceWithoutComments(self::SHEET),
            self::SHEET.': an untouched sheet would send its items as givens.'
        );

        $client = $this->sourceWithoutComments(self::CLIENT);

        // Omitted rather than sent empty, so an unedited ask is byte-identical
        // to the request these endpoints have always received.
        self::assertSame(
            2,
            preg_match_all('/items === null \|\| items\.length === 0 \? \{\} : \{ items \}/', $client),
            self::CLIENT.': one of the two re-run helpers sends an empty `items` key.'
        );
    }
}
