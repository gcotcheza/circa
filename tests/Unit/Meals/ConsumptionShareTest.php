<?php

declare(strict_types=1);

namespace Tests\Unit\Meals;

use PHPUnit\Framework\TestCase;
use App\Services\Meals\ConsumptionShare;

/**
 * The one multiplication in the shared-plate feature. Everything else is
 * plumbing around this class; if it drifts, a day's calories drift with it and
 * nothing on screen says so.
 */
final class ConsumptionShareTest extends TestCase
{
    public function test_a_missing_or_unreadable_share_is_all_of_it(): void
    {
        /*
         * The default is All in every spelling: "not shared" is what almost
         * every row means, and 1.0 rather than 0 is the safe direction — an
         * under-count is invisible, and a meal that quietly shrank is worse.
         */
        foreach ([null, '', 'half', NAN, INF, [], new \stdClass] as $value) {
            self::assertSame(1.0, ConsumptionShare::of($value)->fraction);
        }
    }

    public function test_a_share_is_clamped_to_something_edible(): void
    {
        self::assertSame(1.0, ConsumptionShare::of(2)->fraction);
        self::assertSame(1.0, ConsumptionShare::of(1)->fraction);
        self::assertSame(0.5, ConsumptionShare::of(0.5)->fraction);

        // Zero is not a share: "I ate none" is a line to delete, and the sheet can.
        self::assertSame(ConsumptionShare::MIN, ConsumptionShare::of(0)->fraction);
        self::assertSame(ConsumptionShare::MIN, ConsumptionShare::of(-3)->fraction);
    }

    public function test_the_eaten_portion_is_the_plate_times_the_share(): void
    {
        $row = ConsumptionShare::of(0.5)->apply([
            'name'              => 'Pho',
            'portion_g_min'     => 600.0,
            'portion_g_max'     => 900.0,
            'kcal_per_100g_min' => 15.0,
            'kcal_per_100g_max' => 22.0,
        ]);

        self::assertSame(300.0, $row['portion_g_min']);
        self::assertSame(450.0, $row['portion_g_max']);

        // The estimate is kept, whole, beside the half that was eaten.
        self::assertSame(600.0, $row['portion_full_g_min']);
        self::assertSame(900.0, $row['portion_full_g_max']);
        self::assertSame(0.5, $row['share_fraction']);

        // Densities are a fact about the FOOD. Half a bowl of pho is still pho.
        self::assertSame(15.0, $row['kcal_per_100g_min']);
        self::assertSame(22.0, $row['kcal_per_100g_max']);
    }

    /**
     * A row from before this feature says what it always said: callers that
     * hand over only `portion_g_*` meant "this is the portion", and reading it
     * as the estimate is what makes the model hook safe on every insert.
     */
    public function test_a_row_with_no_estimate_treats_its_portion_as_one(): void
    {
        $row = ConsumptionShare::all()->apply(['portion_g_min' => 250.0, 'portion_g_max' => 250.0]);

        self::assertSame(250.0, $row['portion_g_min']);
        self::assertSame(250.0, $row['portion_full_g_min']);
        self::assertSame(1.0, $row['share_fraction']);
    }

    /**
     * Running it twice changes nothing — the property that lets
     * `MealItem::creating` apply it to every insert without auditing which
     * callers already had. `meals.repeat` copies an already-scaled row, and a
     * re-projection maps rows that have been through here once.
     */
    public function test_applying_a_share_twice_is_the_same_as_applying_it_once(): void
    {
        $once = ConsumptionShare::of(0.25)->apply(['portion_g_min' => 400.0, 'portion_g_max' => 400.0]);
        $twice = ConsumptionShare::fromRow($once)->apply($once);

        self::assertSame($once, $twice);
        self::assertSame(100.0, $twice['portion_g_min']);
        self::assertSame(400.0, $twice['portion_full_g_min']);
    }

    /**
     * ½, then ⅓, then All — and the model's number comes back EXACTLY. This is
     * why the unscaled portion is a stored column rather than
     * `portion_g / share_fraction`: under division this sequence returns 99.99 g
     * of a 100 g estimate, then 99.97, and nothing on screen explains why the
     * rice keeps shrinking. `assertSame` on a float is the right assertion
     * because "close enough" is what this rejects.
     */
    public function test_changing_your_mind_repeatedly_does_not_shave_the_estimate(): void
    {
        $original = ['portion_g_min' => 100.0, 'portion_g_max' => 175.0];

        $row = ConsumptionShare::of(0.5)->apply($original);
        $row = ConsumptionShare::of(1 / 3)->apply($row);
        $row = ConsumptionShare::of(0.25)->apply($row);
        $row = ConsumptionShare::of(0.75)->apply($row);
        $row = ConsumptionShare::all()->apply($row);

        self::assertSame(100.0, $row['portion_g_min']);
        self::assertSame(175.0, $row['portion_g_max']);
        self::assertSame(1.0, $row['share_fraction']);

        // And the estimate itself never moved, at any point in that sequence.
        self::assertSame(100.0, $row['portion_full_g_min']);
        self::assertSame(175.0, $row['portion_full_g_max']);
    }

    /**
     * A stated portion beats a stale estimate beside it — a partial override: a
     * factory filled in an estimate, then a caller said "no, 100–200 g".
     * Believing the leftover estimate would replace their number with one they
     * never asked for, and the row would still look like a row.
     */
    public function test_a_portion_that_contradicts_its_estimate_is_taken_as_the_plate(): void
    {
        $row = ConsumptionShare::all()->apply([
            'portion_g_min' => 100.0,
            'portion_g_max' => 200.0,
            // Left over from somewhere else entirely.
            'portion_full_g_min' => 119.0,
            'portion_full_g_max' => 161.0,
            'share_fraction'     => 1,
        ]);

        self::assertSame(100.0, $row['portion_g_min']);
        self::assertSame(200.0, $row['portion_g_max']);
        self::assertSame(100.0, $row['portion_full_g_min']);
        self::assertSame(200.0, $row['portion_full_g_max']);
    }

    /**
     * A row that already satisfies the invariant keeps its estimate:
     * `meals.repeat` copies a stored row verbatim, and re-logging half a dinner
     * must not quarter it.
     */
    public function test_a_coherent_row_is_left_exactly_as_it_is(): void
    {
        $stored = [
            'portion_g_min'      => 60.0,
            'portion_g_max'      => 100.0,
            'portion_full_g_min' => 120.0,
            'portion_full_g_max' => 200.0,
            'share_fraction'     => 0.5,
        ];

        $row = ConsumptionShare::fromRow($stored)->apply($stored);

        self::assertSame(60.0, $row['portion_g_min']);
        self::assertSame(120.0, $row['portion_full_g_min']);
        self::assertSame(0.5, $row['share_fraction']);
    }

    public function test_a_third_of_a_plate_stays_a_third_through_the_column(): void
    {
        // 0.3333 is what `decimal(5,4)` holds; the chips match with a tolerance.
        $row = ConsumptionShare::of(1 / 3)->apply(['portion_g_min' => 300.0, 'portion_g_max' => 300.0]);

        self::assertSame(0.3333, $row['share_fraction']);
        self::assertEqualsWithDelta(100.0, $row['portion_g_min'], 0.05);

        // Back to All from the stored value, not from the float we started with.
        $whole = ConsumptionShare::all()->apply($row);

        self::assertSame(300.0, $whole['portion_g_min']);
    }
}
