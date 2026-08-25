<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPhoto;
use Illuminate\Support\Facades\DB;
use App\Services\Reporting\DailyView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A meal reads the same way twice.
 *
 * ---------------------------------------------------------------------------
 * WHAT WENT WRONG, AND WHY IT LOOKED LIKE NOTHING
 *
 * `Meal::items()` had no ordering, which is not "insertion order" but whatever a
 * sequential scan returns — and in Postgres an UPDATE writes a NEW tuple at the
 * end of the heap, so any maintenance touching any column moved rows in every
 * screen that reads them.
 *
 * The photo-provenance relink (fix/photo-provenance) rewrote `meal_photo_id` on
 * items that had lost it. Correct, no number changed, and it silently reordered a
 * three-course dinner on every screen — including the edit sheet, whose new order
 * the user then SAVED, so the scrambling outlived the query that caused it
 * (`MealWriter::update` replaces rows wholesale in the order posted).
 *
 * Not cosmetic: `canonical_name` on a remembered meal is the item names joined in
 * list order, then FROZEN in a column.
 *
 * ---------------------------------------------------------------------------
 * THE RULE, STATED ONCE
 *
 *   items   plate position, then row id. Typed, scanned and text-estimated
 *           lines have no plate and sort LAST (Postgres puts NULLs last).
 *   photos  position, then row id.
 *
 * Pinned in the RELATION rather than at the nine call sites, one of which will
 * always be forgotten.
 * ---------------------------------------------------------------------------
 */
final class StableOrderTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-07';

    // -----------------------------------------------------------------------
    // The rule
    // -----------------------------------------------------------------------

    public function test_items_read_plate_by_plate_with_the_typed_lines_last(): void
    {
        $meal = $this->dinner();

        self::assertSame(
            ['Spring rolls', 'Dipping sauce', 'Pho broth', 'Beef', 'Durian ice cream', 'Two beers'],
            $meal->items->pluck('name')->all(),
        );
    }

    public function test_a_plates_own_items_read_in_the_order_the_model_listed_them(): void
    {
        $meal = $this->dinner();

        $starter = $meal->photos->first();
        self::assertNotNull($starter);

        self::assertSame(['Spring rolls', 'Dipping sauce'], $starter->items->pluck('name')->all());
    }

    // -----------------------------------------------------------------------
    // ...and it survives the thing that broke it
    // -----------------------------------------------------------------------

    /**
     * Perturb the heap the way the relink did, and assert nothing moved. The
     * first assertions prove the perturbation happened, because a test that
     * pinned the order without disturbing anything would pass on the broken code.
     */
    public function test_an_update_that_moves_the_heap_moves_nothing_on_screen(): void
    {
        $meal = $this->dinner();

        $before = $meal->items->pluck('name')->all();

        $heapBefore = $this->heapOrder($meal);

        // Exactly what the relink did. Same value, so only where Postgres keeps
        // the row changes.
        DB::table('meal_items')
            ->where('meal_id', $meal->id)
            ->whereIn('name', ['Spring rolls', 'Dipping sauce'])
            ->update(['meal_photo_id' => DB::raw('meal_photo_id')]);

        self::assertNotSame(
            $heapBefore,
            $this->heapOrder($meal),
            'The perturbation did not happen, so this test proves nothing. The rows were expected to move on disk.',
        );

        $refreshed = $meal->fresh();
        self::assertNotNull($refreshed);
        self::assertSame($before, $refreshed->items->pluck('name')->all());
    }

    public function test_plates_keep_their_order_when_their_rows_are_rewritten(): void
    {
        $meal = $this->dinner();

        MealPhoto::query()->where('meal_id', $meal->id)->where('position', 0)
            ->update(['model_notes' => 'moved to the end of the heap']);

        $refreshed = $meal->fresh();
        self::assertNotNull($refreshed);
        self::assertSame([0, 1, 2], $refreshed->photos->pluck('position')->all());
    }

    /**
     * `position` is a TOTAL order within a meal, so the id tie-break never
     * decides anything. Drop that index and two plates could share a slot,
     * putting the pair back on heap order, silently.
     */
    public function test_two_plates_cannot_share_a_position(): void
    {
        $meal = Meal::factory()->create(['eaten_at' => self::DATE.' 19:00:00']);

        MealPhoto::factory()->for($meal)->atPosition(1)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        MealPhoto::factory()->for($meal)->atPosition(1)->create();
    }

    // -----------------------------------------------------------------------
    // ...all the way to the props
    // -----------------------------------------------------------------------

    public function test_the_day_card_lists_the_courses_before_the_dessert(): void
    {
        $meal = $this->dinner();

        DB::table('meal_items')
            ->where('meal_id', $meal->id)
            ->where('name', 'Spring rolls')
            ->update(['meal_photo_id' => DB::raw('meal_photo_id')]);

        $props = (new DailyView)->props(self::DATE);

        self::assertSame(
            ['Spring rolls', 'Dipping sauce', 'Pho broth', 'Beef', 'Durian ice cream', 'Two beers'],
            array_column($props['meals'][0]['items'], 'name'),
        );
    }

    /** `recentMeals` joins the item names, so an unordered read re-words the quick-add list on its own. */
    public function test_the_quick_add_label_does_not_re_word_itself(): void
    {
        $meal = $this->dinner();

        $before = (new DailyView)->props(self::DATE)['recentMeals'][0]['label'];

        DB::table('meal_items')
            ->where('meal_id', $meal->id)
            ->whereIn('name', ['Spring rolls', 'Pho broth'])
            ->update(['meal_photo_id' => DB::raw('meal_photo_id')]);

        self::assertSame($before, (new DailyView)->props(self::DATE)['recentMeals'][0]['label']);

        self::assertSame(
            'Spring rolls, Dipping sauce, Pho broth, Beef, Durian ice cream, Two beers',
            $before,
        );
    }

    // -----------------------------------------------------------------------

    /**
     * Three plates and two typed lines, written in an order that is NOT the order
     * they should read in, so the assertions above are about the ordering rather
     * than the insert sequence.
     */
    private function dinner(): Meal
    {
        $meal = Meal::factory()->create(['eaten_at' => self::DATE.' 19:00:00']);

        $starter = MealPhoto::factory()->for($meal)->atPosition(0)->create();
        $main = MealPhoto::factory()->for($meal)->atPosition(1)->create();
        $pudding = MealPhoto::factory()->for($meal)->atPosition(2)->create();

        // Pudding first, beers in the middle: insert order and read order are
        // different sequences. Inserting them sorted would pass on the broken code.
        $this->line($meal, $pudding->id, 'Durian ice cream');
        $this->line($meal, $main->id, 'Pho broth');
        $this->line($meal, null, 'Two beers');
        $this->line($meal, $starter->id, 'Spring rolls');
        $this->line($meal, $main->id, 'Beef');
        $this->line($meal, $starter->id, 'Dipping sauce');

        return $meal->refresh();
    }

    /**
     * The rows in PHYSICAL order — what an unordered query is at the mercy of and
     * what an UPDATE rearranges. `ctid` is the tuple's (page, slot) address;
     * ordering by it is Postgres-specific, which is fine because this suite runs
     * on Postgres on purpose (see phpunit.xml).
     *
     * @return array<int, string>
     */
    private function heapOrder(Meal $meal): array
    {
        return DB::table('meal_items')
            ->where('meal_id', $meal->id)
            ->orderBy('ctid')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    private function line(Meal $meal, ?int $photoId, string $name): MealItem
    {
        return MealItem::factory()->for($meal)->named($name)->create([
            'meal_photo_id' => $photoId,
            'confirmed_at'  => now(),
        ]);
    }
}
