<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Tests\TestCase;
use App\Models\Supplement;
use App\Models\SupplementIntake;
use App\Models\SupplementNutrient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The three supplement tables, and the constraints that are doing work.
 *
 * Most of this is the boring kind of schema test. The two that matter are the
 * unique index — the entire idempotency story for a button that rides an
 * at-least-once queue — and the cascade, since deleting a bottle must not leave
 * orphan label lines.
 */
final class SupplementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_columns_the_feature_needs(): void
    {
        self::assertTrue(Schema::hasTable('supplements'));
        self::assertTrue(Schema::hasTable('supplement_nutrients'));
        self::assertTrue(Schema::hasTable('supplement_intakes'));

        self::assertTrue(Schema::hasColumns('supplements', [
            'name', 'brand', 'serving_text', 'units_per_day', 'photo_path',
            'data_source', 'source_url', 'notes', 'position', 'active',
            'created_at', 'updated_at',
        ]));

        self::assertTrue(Schema::hasColumns('supplement_nutrients', [
            'supplement_id', 'nutrient', 'amount', 'unit', 'position',
        ]));

        self::assertTrue(Schema::hasColumns('supplement_intakes', [
            'supplement_id', 'local_date', 'taken_at',
        ]));
    }

    public function test_the_factories_build_a_supplement_with_its_label(): void
    {
        $supplement = Supplement::factory()
            ->has(SupplementNutrient::factory()->count(3), 'nutrients')
            ->create();

        self::assertSame(3, $supplement->nutrients()->count());
        self::assertTrue($supplement->active);
        self::assertSame(1, $supplement->units_per_day);
    }

    /** Why a tick is safe to replay: the DATABASE says a (supplement, day) pair happens once. */
    public function test_one_supplement_cannot_be_taken_twice_on_one_day(): void
    {
        $supplement = Supplement::factory()->create();

        SupplementIntake::factory()->for($supplement)->on('2026-08-08')->create();

        $this->expectException(QueryException::class);

        SupplementIntake::factory()->for($supplement)->on('2026-08-08')->create();
    }

    public function test_the_same_day_on_two_supplements_is_two_rows(): void
    {
        $first = Supplement::factory()->create();
        $second = Supplement::factory()->create();

        SupplementIntake::factory()->for($first)->on('2026-08-08')->create();
        SupplementIntake::factory()->for($second)->on('2026-08-08')->create();

        self::assertSame(2, SupplementIntake::query()->count());
    }

    public function test_deleting_a_supplement_takes_its_label_and_its_history_with_it(): void
    {
        $supplement = Supplement::factory()
            ->has(SupplementNutrient::factory()->count(2), 'nutrients')
            ->create();

        SupplementIntake::factory()->for($supplement)->on('2026-08-07')->create();

        $supplement->delete();

        self::assertSame(0, SupplementNutrient::query()->count());
        self::assertSame(0, SupplementIntake::query()->count());
    }

    /**
     * `local_date` is a plain date column, NOT generated from `taken_at` as
     * `meals` does: ticking last night's magnesium this morning is the reason.
     */
    public function test_an_intake_can_be_recorded_for_a_day_other_than_the_one_it_was_tapped_on(): void
    {
        $supplement = Supplement::factory()->create();

        $intake = SupplementIntake::query()->create([
            'supplement_id' => $supplement->id,
            'local_date'    => '2026-08-07',
            'taken_at'      => '2026-08-08 09:15:00',
        ]);

        self::assertSame('2026-08-07', $intake->local_date->toDateString());
        self::assertSame('2026-08-08', $intake->taken_at->toDateString());
    }

    /**
     * A decimal column, not a float: 0.0125 mg of B12 is a real label line, shown
     * back to somebody proof-reading it against a bottle.
     */
    public function test_a_small_amount_survives_the_round_trip_exactly(): void
    {
        $supplement = Supplement::factory()->create();

        $supplement->nutrients()->create([
            'nutrient' => 'Vitamine B12',
            'amount'   => '0.0125',
            'unit'     => 'mg',
            'position' => 0,
        ]);

        self::assertSame('0.0125', (string) (float) $supplement->nutrients()->sole()->amount);
    }

    public function test_a_label_line_may_carry_no_figure_at_all(): void
    {
        $supplement = Supplement::factory()->create();

        $supplement->nutrients()->save(
            SupplementNutrient::factory()->unquantified()->make(['nutrient' => 'Bevat sporen van soja'])
        );

        $line = $supplement->nutrients()->sole();

        self::assertNull($line->amount);
        self::assertNull($line->unit);
    }

    /** The card's query, and the only place `active` is load-bearing. */
    public function test_the_active_scope_orders_by_position_and_hides_the_cupboard(): void
    {
        Supplement::factory()->atPosition(2)->create(['name' => 'Omega 3']);
        Supplement::factory()->atPosition(0)->create(['name' => 'Multi']);
        Supplement::factory()->inactive()->atPosition(1)->create(['name' => 'The spare']);

        self::assertSame(
            ['Multi', 'Omega 3'],
            Supplement::query()->active()->pluck('name')->all()
        );
    }

    /** The one arithmetic rule: printed figure x servings, for display, never stored. */
    public function test_the_daily_total_is_the_printed_figure_times_the_servings(): void
    {
        $supplement = Supplement::factory()->taking(2)->create(['serving_text' => 'per capsule']);

        $supplement->nutrients()->create(['nutrient' => 'Magnesium', 'amount' => '120', 'unit' => 'mg', 'position' => 0]);
        $supplement->nutrients()->create(['nutrient' => 'Bevat soja', 'amount' => null, 'unit' => null, 'position' => 1]);

        $daily = $supplement->refresh()->dailyNutrients();

        self::assertSame(120.0, $daily[0]['amount']);
        self::assertSame(240.0, $daily[0]['perDay']);

        // A line the label did not quantify gains no total by being doubled.
        self::assertNull($daily[1]['perDay']);
    }
}
