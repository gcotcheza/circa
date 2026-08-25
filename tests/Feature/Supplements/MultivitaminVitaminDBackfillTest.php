<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\Supplement;
use App\Models\SupplementNutrient;
use Database\Seeders\SupplementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The shelves seeded while the vitamin D row was still blank. SupplementSeeder
 * skips a supplement that already exists — on purpose, so it cannot argue with
 * somebody holding the bottle — so on those databases the figure arrives only
 * through the migration: fill the blank, touch nothing else, including a row
 * the user has already answered themselves. `fill()` is called directly rather
 * than through `migrate`, which the suite has already run by the time a test
 * starts, leaving it nothing to find.
 */
final class MultivitaminVitaminDBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_the_blank_row_with_the_self_consistent_figure(): void
    {
        $row = $this->vitaminDRow($this->multivitamin(), null, null);

        $this->fill();

        $row->refresh();

        self::assertSame(5.0, (float) $row->amount);
        self::assertSame('mcg', $row->unit);

        // Same label as the seeder's: migrated and freshly seeded read alike.
        self::assertSame('Vitamine D (cholecalciferol/ergocalciferol)', $row->nutrient);
    }

    /** The `NULL` guard: 10 µg typed off the user's own bottle already answers this. */
    public function test_a_row_somebody_has_already_filled_is_left_alone(): void
    {
        $row = $this->vitaminDRow($this->multivitamin(), '10.0000', 'mcg');

        $this->fill();

        $row->refresh();

        self::assertSame(10.0, (float) $row->amount);
        self::assertSame('Vitamine D (cholecalciferol)', $row->nutrient);
    }

    /** Scoped by the supplement, so the D3 bottle's own rows are not in range. */
    public function test_another_supplements_blank_row_is_not_touched(): void
    {
        $other = Supplement::factory()->create(['name' => 'Vitamin D3 75 mcg (3000 IU)']);

        $row = $this->vitaminDRow($other, null, null);

        $this->fill();

        self::assertNull($row->refresh()->amount);
    }

    /** Fresh seed: nothing to fix. Second run: nothing written. One guard does both. */
    public function test_it_is_a_silent_no_op_on_a_freshly_seeded_shelf(): void
    {
        $this->seed(SupplementSeeder::class);

        $this->fill();
        $this->fill();

        $multi = Supplement::query()->where('name', 'Daily Complete 50')->sole();

        $vitaminD = $multi->nutrients()->where('nutrient', 'like', 'Vitamine D%')->sole();

        self::assertSame(5.0, (float) $vitaminD->amount);
        self::assertSame('mcg', $vitaminD->unit);
    }

    private function multivitamin(): Supplement
    {
        return Supplement::factory()->create(['name' => 'Daily Complete 50']);
    }

    /** The pre-migration shape: the row named, and its figure left open. */
    private function vitaminDRow(Supplement $supplement, ?string $amount, ?string $unit): SupplementNutrient
    {
        return SupplementNutrient::factory()->create([
            'supplement_id' => $supplement->id,
            'nutrient'      => 'Vitamine D (cholecalciferol)',
            'amount'        => $amount,
            'unit'          => $unit,
        ]);
    }

    private function fill(): void
    {
        $migration = require database_path('migrations/2026_08_17_100000_fill_multivitamin_vitamin_d.php');

        $migration->fill();
    }
}
