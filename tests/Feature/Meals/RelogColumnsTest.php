<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPhoto;
use App\Models\FoodProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Meals\MealWriter;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Which columns a re-log carries, pinned against the table.
 *
 * `MealWriter::COPIED` names the `meal_items` columns a "log this again"
 * copies, and an allowlist fails QUIETLY: a column left off it produces a
 * copy that still looks like a copy, one field poorer. So there are two
 * tests here, and they fail for opposite reasons. The first says the
 * listed columns actually arrive. The second says the list, plus the six
 * the copy must not take, is the WHOLE table — which is what turns
 * `ALTER TABLE ... ADD COLUMN` into a failing test rather than a silent
 * loss on the next re-log.
 */
final class RelogColumnsTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const CONFIRMED_AT = '2026-06-15 10:00:00';

    /**
     * The columns a re-log deliberately leaves behind, and why.
     *
     * `id` — the copy is a new row.
     * `meal_id` — set by the relation, to the meal being logged now.
     * `meal_photo_id` — the plate belongs to the meal that was photographed;
     *     see MealWriter::COPIED for what carrying it across would break.
     * `created_at` / `updated_at` — Eloquent's, about this row's own life.
     * `confirmed_at` — re-stamped by the writer: the copy is confirmed by
     *     being saved, not by the tap that confirmed the original.
     *
     * @var list<string>
     */
    private const NOT_COPIED = [
        'id',
        'meal_id',
        'meal_photo_id',
        'created_at',
        'updated_at',
        'confirmed_at',
    ];

    public function test_a_re_log_carries_every_column_it_is_meant_to(): void
    {
        $original = (string) Str::uuid();

        $this->post('/meals', [
            'uuid'  => $original,
            'date'  => '2026-06-15',
            'time'  => '13:20',
            'items' => [[
                'name'    => 'White rice',
                'basis'   => 'absolute',
                'kcal'    => 240,
                'protein' => 5,
                'carbs'   => 50,
                'fat'     => 1,
            ]],
        ])->assertRedirect();

        $meal = Meal::query()->where('uuid', $original)->firstOrFail();
        $product = FoodProduct::factory()->create();
        $photo = MealPhoto::factory()->create(['meal_id' => $meal->id]);

        // Written straight onto the row: these are the columns the vision
        // path, the barcode scanner and memory leave behind, and the form
        // this meal was typed into never posts them.
        $meal->items()->sole()->forceFill([
            'confidence'         => '0.750',
            'memory_adjusted'    => true,
            'food_product_id'    => $product->barcode,
            'share_fraction'     => '0.5000',
            'portion_full_g_min' => '240.000',
            'portion_full_g_max' => '260.000',
            'portion_g_min'      => '120.000',
            'portion_g_max'      => '130.000',
            'meal_photo_id'      => $photo->id,
            'confirmed_at'       => CarbonImmutable::parse(self::CONFIRMED_AT),
        ])->saveQuietly();

        $source = $meal->items()->sole();

        $repeat = (string) Str::uuid();

        $this->post("/meals/{$original}/repeat", [
            'uuid' => $repeat,
            'date' => '2026-06-16',
            'time' => '12:30',
        ])->assertRedirect('/?date=2026-06-16');

        $copy = Meal::query()->where('uuid', $repeat)->firstOrFail()->items()->sole();

        // Stated as values, not just as "same as the source": a column that
        // was null at both ends would pass a comparison and prove nothing.
        self::assertSame('White rice', $copy->name);
        self::assertSame('white-rice', $copy->slug);
        self::assertSame('0.750', $copy->confidence);
        self::assertTrue($copy->memory_adjusted);
        self::assertSame($product->barcode, $copy->food_product_id);

        // Both ends of every band. A re-log that carried the floor and
        // dropped the ceiling would read as a certainty the app never had.
        self::assertSame('240.000', $copy->kcal_per_100g_min);
        self::assertSame('240.000', $copy->kcal_per_100g_max);
        self::assertSame('5.000', $copy->protein_per_100g_min);
        self::assertSame('5.000', $copy->protein_per_100g_max);
        self::assertSame('50.000', $copy->carbs_per_100g_min);
        self::assertSame('50.000', $copy->carbs_per_100g_max);
        self::assertSame('1.000', $copy->fat_per_100g_min);
        self::assertSame('1.000', $copy->fat_per_100g_max);

        // The shared plate travels whole: the estimate AND the part of it
        // that was eaten, so re-logging half a bar stays half a bar.
        self::assertSame('0.5000', $copy->share_fraction);
        self::assertSame('240.000', $copy->portion_full_g_min);
        self::assertSame('260.000', $copy->portion_full_g_max);
        self::assertSame('120.000', $copy->portion_g_min);
        self::assertSame('130.000', $copy->portion_g_max);

        // And the sweep: whatever else is on the list arrives unchanged, so
        // adding a column to COPIED without asserting it by name is still
        // covered.
        foreach (MealWriter::COPIED as $column) {
            self::assertSame(
                $source->getAttribute($column),
                $copy->getAttribute($column),
                "a re-log changed or dropped `{$column}`",
            );
        }

        // Provenance does not travel: the plate is the source meal's.
        self::assertNull($copy->meal_photo_id);

        // Nor does the source's confirmation — this copy was confirmed by
        // being saved, just now.
        self::assertNotNull($copy->confirmed_at);
        self::assertTrue($copy->confirmed_at->greaterThan(CarbonImmutable::parse(self::CONFIRMED_AT)));
    }

    /**
     * Every column of `meal_items` is on exactly one of the two lists.
     *
     * The test that makes the allowlist safe to keep. A new column is a
     * decision — copied on a re-log, or deliberately not — and this is what
     * refuses to let the decision be skipped: until the column is named in
     * `MealWriter::COPIED` or in NOT_COPIED above, this fails.
     */
    public function test_every_meal_items_column_is_decided_one_way_or_the_other(): void
    {
        $columns = Schema::getColumnListing((new MealItem)->getTable());

        sort($columns);

        $decided = [...MealWriter::COPIED, ...self::NOT_COPIED];

        sort($decided);

        self::assertSame(
            $columns,
            $decided,
            'Every meal_items column must be either copied by a re-log (MealWriter::COPIED) '
            .'or deliberately left behind (RelogColumnsTest::NOT_COPIED).',
        );
    }
}
