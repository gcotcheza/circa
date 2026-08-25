<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use App\Models\MealItem;
use Illuminate\Support\Str;
use Tests\Concerns\ReadsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE PHANTOM PER-100 g ROW, AND THE KEYSTROKES IT ATE.
 *
 * A new line on the Quick tab of "Log a meal" rendered BOTH rows: the four
 * totals, and under them grams + four densities that had no business being
 * there. Typing "250" into that grams box put a 2 in it and the row VANISHED
 * mid-word; "100" left a 1 behind the same way. A nonsense helper line came
 * with it — "Totals for the 1 g portion — the weight is kept" — and the strays
 * were saved as STATED portions, so the next estimate was told
 * "Portion: 2 g (GIVEN — use exactly this)".
 *
 * The cause was a `v-else` chained onto the wrong thing: the per-100 g row was
 * `v-else`, and the element above it was not the Quick row but the helper
 * paragraph, so the row's real condition was
 *
 *     NOT (basis === 'absolute' AND hasPortion(item))
 *
 * true for every Quick item without a weight. The first keystroke gave the item
 * a weight, `hasPortion` flipped, and Vue unmounted the input the caret was in,
 * with the partial value already written to the model.
 *
 * The rules are structural, so most of this is source inspection — the approach
 * QuickTabConversionTest and ConfirmRequestShapeTest take to a client-side rule
 * in a project with no JS test runner: (1) in Quick mode the per-100 g row
 * NEVER renders; (2) only the mode toggle switches rows, so no render condition
 * may mention a VALUE; (3) typing in any field never changes which fields
 * exist. The last test is the payload half, and why these are tests rather than
 * a comment: the damage was silent, permanent and in the direction of inventing
 * a claim the user never made.
 */
final class QuickModeRowsTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const SHEET = 'resources/js/Components/MealSheet.vue';

    private const DATE = '2026-06-15';

    /** The Quick row and the per-100 g row, each on its own explicit condition. */
    public function test_each_row_renders_on_its_own_mode_and_nothing_else(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertStringContainsString(
            '<div v-if="item.basis === \'absolute\'" class="mt-2 grid grid-cols-4 gap-2">',
            $code,
            self::SHEET.': the Quick totals row no longer renders on the mode alone.'
        );

        self::assertStringContainsString(
            '<div v-if="item.basis === \'per_100g\'" class="mt-2 grid grid-cols-5 gap-2">',
            $code,
            self::SHEET.': the per-100 g row is not on an explicit `per_100g` condition. If it is a '
                .'`v-else` again it will chain onto whatever happens to sit above it — which is how it '
                .'came to render in Quick mode and eat keystrokes.'
        );
    }

    /**
     * No `v-else` on any element that carries an input. The bug was never the
     * paragraph between the rows — it is that a conditional whose meaning
     * depends on its NEIGHBOUR cannot be read locally. Both rows state their
     * own condition, so a paragraph inserted between them changes nothing.
     */
    public function test_the_field_rows_do_not_chain_off_a_neighbour(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertSame(
            0,
            preg_match('/<div v-else[\s>]/', $code),
            self::SHEET.' has a `v-else` on a div again. The rows in the item card must each carry '
                .'their own `v-if` on `item.basis`.'
        );
    }

    /**
     * The conditions mention the MODE and never a value: `hasPortion(item)`,
     * `item.grams`, `item.kcal` in a row's condition each mean a field that
     * exists or stops existing according to what is typed into it.
     */
    public function test_no_row_condition_depends_on_a_typed_value(): void
    {
        $code = $this->template(self::SHEET);

        self::assertSame(
            1,
            preg_match_all('/hasPortion\(item\)/', $code),
            self::SHEET.': `hasPortion` is meant to be read in exactly one place in the template — the '
                .'helper SENTENCE under the Quick row. Nothing that renders a field may consult it.'
        );

        self::assertMatchesRegularExpression(
            '/<p v-if="item\.basis === \'absolute\' && hasPortion\(item\)"/',
            $code,
            self::SHEET.': the helper line is no longer the one place `hasPortion` is read.'
        );
    }

    /**
     * Exactly one grams box, in the per-100 g row — the rule with teeth. A
     * weight can only be given in the mode that OWNS weights, so in Quick mode
     * the corruption below has no entry point at all.
     */
    public function test_the_grams_box_exists_only_in_the_per_100g_row(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertSame(
            1,
            preg_match_all("/edit\(item, 'grams'/", $code),
            self::SHEET.' renders more than one grams box.'
        );

        $perHundred = strpos($code, '<div v-if="item.basis === \'per_100g\'" class="mt-2 grid grid-cols-5 gap-2">');
        $grams = strpos($code, "edit(item, 'grams'");

        self::assertIsInt($perHundred);
        self::assertIsInt($grams);
        self::assertGreaterThan(
            $perHundred,
            $grams,
            self::SHEET.': the grams box is outside the per-100 g row.'
        );
    }

    /**
     * The helper line says something true about a real portion. `hasPortion` is
     * >0 and not the 100 g basis (lib/basis.js), so the sentence cannot appear
     * on an item nobody weighed — "Totals for the 1 g portion" was the phantom
     * row's 1, not a wrong test. The weight prints through `box()`, the same
     * text the other tab's grams field holds, rounded to a tenth
     * (FieldPrecisionTest): "the 130.83333333333334 g portion" is no more
     * readable in prose than it was in the box.
     */
    public function test_the_helper_line_reads_as_a_sentence_about_a_weighed_item(): void
    {
        $code = $this->sourceWithoutComments(self::SHEET);

        self::assertStringContainsString(
            'These are the totals for the {{ box(item, \'grams\') }} g portion, and that weight is kept',
            $code,
            self::SHEET.': the helper line under the Quick row has changed. It must name the portion and '
                .'say the weight survives an edit, which is the only reason it is there.'
        );
    }

    /**
     * THE PAYLOAD HALF: what the phantom row actually cost. Typing "250" into a
     * box that should not have existed posted a per-100 g item with `grams: 2`
     * — `payloadFor` reads a Quick item WITH a weight as per-100 g
     * (lib/basis.js), and by then it had one — and blanked the kcal figure
     * typed in the Quick row, per-100 g mode not owning the totals.
     */
    public function test_a_stray_gram_value_lands_as_a_stated_portion(): void
    {
        $this->actingAs(User::factory()->create());

        // Exactly the bytes the sheet posted after the phantom row took the "2".
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 2, 'kcal_per_100g' => null],
        ]))->assertSessionHasNoErrors();

        $corrupted = MealItem::query()->sole();

        // A 2 g portion claimed as though weighed — what the estimate path
        // then reads back as "Portion: 2 g (GIVEN)".
        self::assertSame(2.0, (float) $corrupted->portion_full_g_min);
        self::assertSame(2.0, (float) $corrupted->portion_full_g_max);
        self::assertSame(0.0, (float) $corrupted->kcal_per_100g_max);

        $meal = $corrupted->meal;
        self::assertNotNull($meal);
        $meal->delete();

        // What the fixed sheet posts for the same gesture: Quick mode, the
        // whole figure, no stated weight. 100 g is the BASIS.
        $this->post('/meals', $this->payload([
            ['name' => 'Rice', 'basis' => 'absolute', 'kcal' => 250],
        ]))->assertSessionHasNoErrors();

        $item = MealItem::query()->sole();

        self::assertSame(100.0, (float) $item->portion_full_g_min);
        self::assertSame(250.0, (float) $item->kcal_per_100g_min);
        self::assertSame(250.0, (float) $item->kcal_per_100g_max);

        // The complete number, not the first digit of it.
        self::assertSame(
            250.0,
            (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '12:30',
            'items' => $items,
        ];
    }

    /**
     * The `<template>` half only. `hasPortion` is read in the script as well
     * (`editTotal` re-derives a density over an item's existing weight) and
     * that reading is correct; this file is about the MARKUP.
     */
    private function template(string $path): string
    {
        $code = $this->sourceWithoutComments($path);

        $start = strpos($code, '<template>');

        self::assertIsInt($start, $path.' has no template block.');

        return substr($code, $start);
    }
}
