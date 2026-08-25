<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * THE TAB CONVERTS, AND THE ARITHMETIC HAS ONE HOME.
 *
 * Switching to "Quick" fills the four totals from the per-100 g state, and an
 * edit there on a weighed item re-derives the density over that weight instead
 * of re-filing it at the 100 g basis. The rules live in resources/js/lib/basis.js
 * and this file pins that they stay there: a second copy of `x * grams / 100` in
 * the sheet is how the two tabs start disagreeing again.
 *
 * The server half is tests/Feature/Meals/QuickBasisConversionTest.php — between
 * them, both halves of a client-side conversion in a project with no JS test
 * runner, the way ConfirmRequestShapeTest covers the review sheet's request.
 */
final class QuickTabConversionTest extends TestCase
{
    use ReadsSource;

    private const SHEET = 'resources/js/Components/MealSheet.vue';

    private const MODULE = 'resources/js/lib/basis.js';

    public function test_the_sheet_converts_through_the_shared_module(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertMatchesRegularExpression(
            "/import \{[^}]*\btoQuick\b[^}]*\} from '\.\.\/lib\/basis'/",
            $code,
            self::SHEET.' no longer imports the conversion, so the tabs are two forms again.'
        );

        // One function for both tabs: "toggle and look" is a read, not a write.
        self::assertStringContainsString("@click=\"switchBasis(item, 'absolute')\"", $code);
        self::assertStringContainsString("@click=\"switchBasis(item, 'per_100g')\"", $code);

        self::assertStringNotContainsString(
            '@click="item.basis =',
            $code,
            self::SHEET.' assigns the basis straight from the template again, which is the original bug: '
                .'the boxes change and the numbers do not follow.'
        );
    }

    public function test_a_total_typed_in_quick_writes_the_density_back(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        // All four boxes: protein typed over a 120 g portion keeps the 120 g too.
        self::assertSame(
            4,
            preg_match_all("/editTotal\(item, '(kcal|protein|carbs|fat)'/", $code),
            self::SHEET.' has a Quick box that does not write its density back.'
        );

        self::assertStringContainsString(
            'if (hasPortion(item)) item[`${nutrient}_per_100g`] = densityFor(item[nutrient], item.grams)',
            $code,
            self::SHEET.' no longer re-derives the density over the weight the item already had, '
                .'so editing a weighed item in Quick throws the weight away.'
        );
    }

    public function test_the_payload_is_built_from_the_visible_mode(): void
    {
        self::assertStringContainsString(
            'items: payload.items.map(payloadFor)',
            $this->sourceWithoutComments(self::SHEET),
            self::SHEET.' posts the raw form again. A Quick-mode item with a real weight would then be '
                .'sent as `absolute`, and the server does not read `grams` in that mode.'
        );
    }

    /**
     * One definition of the arithmetic, in the module and nowhere else.
     *
     * `* 1000) / 1000` (display rounding) is deliberately not matched; what this
     * forbids is a second per-100 g conversion in the component.
     */
    public function test_the_sheet_does_no_per_100g_arithmetic_of_its_own(): void
    {
        self::assertSame(
            0,
            preg_match('#[*/] 100(?![0-9])#', $this->sourceWithoutComments(self::SHEET)),
            self::SHEET.' converts between the two bases itself. That arithmetic belongs in '
                .self::MODULE.', or the tabs will disagree the first time one copy is corrected.'
        );
    }

    public function test_the_module_states_both_directions_and_the_basis_identity(): void
    {
        $code = $this->sourceWithoutComments(self::MODULE);

        // grams x density / 100, and its inverse over the same weight.
        self::assertStringContainsString('return weight === null ? density : (density * weight) / 100', $code);
        self::assertStringContainsString('return weight === null ? value : (value * 100) / weight', $code);

        // At the 100 g basis both are the IDENTITY rather than `x * 100 / 100`,
        // which in doubles turns 123.456 into 123.45599999999999 — a number
        // that would change every time somebody tapped a tab.
        self::assertStringContainsString('grams !== null && grams > 0 && grams !== BASIS_G', $code);

        // A blank density is a blank total. Never 0: "no calories" is a claim.
        self::assertStringContainsString('if (density === null) return null', $code);
    }

    /**
     * A range collapses to its CENTRE when the sheet loads it.
     *
     * A box holds one number, so something has to give; taking `.min` — which
     * is what this did — marked every estimated item down to the bottom of its
     * own range the first time somebody opened the sheet to change the time.
     */
    public function test_the_loader_takes_the_midpoint_of_a_stored_band(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        foreach (['kcalPer100g', 'proteinPer100g', 'carbsPer100g', 'fatPer100g'] as $band) {
            self::assertStringContainsString('orBlank(mid(item.'.$band.'))', $code, self::SHEET.": {$band}");
        }

        self::assertStringNotContainsString(
            '.min)',
            $code,
            self::SHEET.' reads the low end of a band into a box again.'
        );

        // The 100 g BASIS test is the server's own: zero-width AND exactly 100.
        // A ranged 100–140 g portion is an estimate that happens to start at
        // 100, not the basis, and reading it as one threw the weight away.
        self::assertStringContainsString(
            'const perHundred = !(portion.min === portion.max && portion.min === 100)',
            $code
        );
    }
}
