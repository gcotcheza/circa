<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\User;
use App\Models\Supplement;
use App\Models\SupplementIntake;
use Database\Seeders\SupplementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The five bottles the app ships with.
 *
 * WHAT IS WORTH ASSERTING ABOUT SEEDED DATA. Pinning every figure would be a
 * second copy of the seeder written in assertions: it would pass by
 * construction, and would fail for the right change the day somebody corrected
 * a transcription against the physical bottle. So what is pinned is the
 * CONTRACT — idempotent, never invents a number, never fabricates an intake,
 * daily totals at what is actually taken, spare bottle off. The figures are
 * checked by a human holding the packaging, the only check that means anything.
 */
final class SupplementSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplementSeeder::class);
    }

    public function test_it_seeds_the_five_bottles_with_four_switched_on(): void
    {
        self::assertSame(5, Supplement::query()->count());
        self::assertSame(4, Supplement::query()->where('active', true)->count());

        // Bought and not started: `active` as a choice at creation.
        $spare = Supplement::query()->where('brand', 'Verdant')->sole();

        self::assertFalse($spare->active);
        self::assertNotNull($spare->notes);
    }

    /** Run at deploy, and again by anyone who re-runs the seeders. */
    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(SupplementSeeder::class);
        $this->seed(SupplementSeeder::class);

        self::assertSame(5, Supplement::query()->count());
    }

    /**
     * A second run must not overwrite a correction made against the bottle — a
     * seeder that argued with the user is worse than one that did nothing.
     */
    public function test_a_corrected_row_survives_a_re_seed(): void
    {
        $supplement = Supplement::query()->where('name', 'Daily Complete 50')->sole();

        $supplement->forceFill(['units_per_day' => 2, 'name' => 'Daily Complete 50'])->save();

        $this->seed(SupplementSeeder::class);

        self::assertSame(2, $supplement->refresh()->units_per_day);
    }

    /** Reference data only. Not one evening of history is invented. */
    public function test_it_seeds_no_intakes(): void
    {
        self::assertSame(0, SupplementIntake::query()->count());
    }

    public function test_every_row_says_where_its_figures_came_from(): void
    {
        foreach (Supplement::query()->get() as $supplement) {
            self::assertContains(
                $supplement->data_source,
                ['manufacturer_published', 'retailer_published'],
                $supplement->name.' does not say where its figures came from'
            );

            self::assertStringStartsWith('https://', (string) $supplement->source_url);
            self::assertNotNull($supplement->serving_text);
        }
    }

    /** The one arithmetic rule, on the two products that exercise both sides. */
    public function test_the_daily_totals_are_what_is_actually_taken(): void
    {
        // Published per capsule; two capsules a day.
        $magnesium = Supplement::query()->where('name', 'Magnesium Glycinate-120')->sole();

        self::assertSame('per capsule', $magnesium->serving_text);
        self::assertSame(2, $magnesium->units_per_day);

        $line = $magnesium->dailyNutrients()[0];

        self::assertSame(120.0, $line['amount']);
        self::assertSame(240.0, $line['perDay']);

        /*
         * Published per TWO softgels, the only way Helixa publishes it — so one
         * serving a day, not two. Halving 1250 mg of fish oil would be arithmetic
         * dressed up as transcription, which the label prompt forbids.
         */
        $omega = Supplement::query()->where('name', 'Omega-3 375')->sole();

        self::assertSame('per 2 mini softgels', $omega->serving_text);
        self::assertSame(1, $omega->units_per_day);

        $epa = $this->notNull(collect($omega->dailyNutrients())->firstWhere('nutrient', 'EPA (eicosapentaeenzuur)'));

        self::assertSame(375.0, $epa['amount']);
        self::assertSame(375.0, $epa['perDay']);
    }

    /**
     * The published panel contradicts itself on vitamin D — "400 iu", "5 mcg"
     * and "600% RI" cannot all be true. Seeded as the one self-consistent
     * reading (5 µg = 200 IE), with the note keeping the contradiction on record.
     */
    public function test_the_contradictory_line_is_seeded_as_the_one_consistent_reading(): void
    {
        $multi = Supplement::query()->where('name', 'Daily Complete 50')->sole();

        $vitaminD = $multi->nutrients()->where('nutrient', 'like', 'Vitamine D%')->sole();

        self::assertSame(5.0, (float) $vitaminD->amount);
        self::assertSame('mcg', $vitaminD->unit);
        self::assertStringContainsString('Vitamine D', (string) $multi->notes);
        self::assertStringContainsString('400 iu', (string) $multi->notes);

        // And no line on that panel is left without a figure.
        self::assertSame(0, $multi->nutrients()->whereNull('amount')->count());
    }

    public function test_the_seeded_shelf_is_immediately_tickable(): void
    {
        $this->actingAs(User::factory()->create());

        $supplement = Supplement::query()->active()->firstOrFail();

        $this->putJson('/api/supplements/'.$supplement->id.'/intake', [
            'date'  => '2026-08-08',
            'taken' => true,
        ])->assertOk()->assertJsonPath('supplements.total', 4);

        self::assertSame(1, SupplementIntake::query()->count());
    }

    /** Nothing about a seeded row is read-only. */
    public function test_a_seeded_supplement_is_editable_like_any_other(): void
    {
        $this->actingAs(User::factory()->create());

        $supplement = Supplement::query()->where('name', 'Omega-3 375')->sole();

        $this->put('/supplements/'.$supplement->id, [
            'name'          => 'Omega-3 375',
            'brand'         => 'Helixa',
            'serving_text'  => 'per 2 mini softgels',
            'units_per_day' => 1,
            'active'        => true,
            'nutrients'     => [
                ['nutrient' => 'EPA (eicosapentaeenzuur)', 'amount' => '380', 'unit' => 'mg'],
            ],
        ])->assertRedirect(route('supplements.index'));

        $supplement->refresh();

        self::assertSame(1, $supplement->nutrients()->count());
        self::assertSame(380.0, (float) $supplement->nutrients()->sole()->amount);

        // Saving makes it yours: the manufacturer is no longer answerable for
        // the row, though the source stays reachable.
        self::assertSame('hand_entered', $supplement->data_source);
        self::assertStringStartsWith('https://', (string) $supplement->source_url);
    }
}
