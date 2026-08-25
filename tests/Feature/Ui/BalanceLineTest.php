<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\Source;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use Tests\Concerns\ReadsSource;
use App\Enums\MetricAggregation;
use Tests\Concerns\ActsAsFreshUser;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The balance line reaches the screen as a direction, not as a sign.
 *
 * EnergyBalanceTest pins the arithmetic and the four states; this pins the two
 * ends of the wire — the day's props carry the pieces, and the card renders them
 * rather than redoing the subtraction, which is what produced "Surplus -143–-49
 * kcal" on a real phone. That covers the PICTURE too: the old track compared the
 * intake band against the burn to place its geometry, while the meter that
 * replaced it gets the balance object and nothing else, so the browser holds no
 * second opinion about which side of maintenance the day landed on.
 */
final class BalanceLineTest extends TestCase
{
    use ActsAsFreshUser;
    use ReadsSource;
    use RefreshDatabase;

    private const CARD = 'resources/js/Components/BalanceCard.vue';

    private const MATHS = 'resources/js/lib/balance.js';

    private const DATE = '2026-06-15';

    private const DAY_START = '2026-06-14 22:00:00';

    /** A day that ate more than it burned says "surplus", unsigned. */
    public function test_the_days_props_carry_the_direction_and_the_magnitudes(): void
    {
        $watch = Source::factory()->watch()->create();

        // 24 x (25 + 60) = 2 040 kcal burned.
        for ($hour = 0; $hour < 24; $hour++) {
            $this->bucket('active_energy', $watch, $hour, 25.0);
            $this->bucket('basal_energy_burned', $watch, $hour, 60.0);
        }

        // 2 200 kcal eaten, zero-width: a surplus of exactly 160.
        $this->meal('Pizza', portion: 400.0, kcalPer100g: 550.0);

        $this->get('/?date='.self::DATE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.balance.direction', 'surplus')
                // Cast in: JSON does not distinguish 160 from 160.0, and the figure is what matters.
                ->where('summary.balance.lo', fn (mixed $value): bool => (float) $value === 160.0)
                ->where('summary.balance.hi', fn (mixed $value): bool => (float) $value === 160.0)
                // Yesterday's day, so nothing is still accruing.
                ->where('summary.balance.provisional', false)
                ->etc()
            );
    }

    /** Nothing logged, nothing to balance — and the card has a sentence for it. */
    public function test_a_day_with_no_intake_has_no_balance(): void
    {
        $watch = Source::factory()->watch()->create();

        for ($hour = 0; $hour < 24; $hour++) {
            $this->bucket('basal_energy_burned', $watch, $hour, 60.0);
        }

        $this->get('/?date='.self::DATE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.balance', null)
                ->etc()
            );
    }

    /** Today's balance is provisional, because today's burn is not finished. */
    public function test_todays_balance_is_marked_provisional(): void
    {
        $today = CarbonImmutable::now((string) config('health.timezone'))->startOfDay();

        $watch = Source::factory()->watch()->create();

        // Both halves: `totalKcalOut()` is null unless active and resting are
        // both known. Hour by hour rather than one bucket, because a day's basal
        // under one hourly stamp is the sync catch-up artifact the rate ceiling
        // refuses (App\Services\Rollup\BucketSelector) — leaving no burn at all.
        for ($hour = 0; $hour < 24; $hour++) {
            $this->metric('basal_energy_burned', $watch, $today->addHours($hour)->utc(), 62.5);
        }

        $this->metric('active_energy', $watch, $today->utc(), 200.0);

        $meal = Meal::factory()->eatenAt($today->addHours(12)->utc())->create();

        MealItem::factory()->for($meal)->create([
            'name'               => 'Lunch',
            'portion_g_min'      => 100,
            'portion_g_max'      => 100,
            'portion_full_g_min' => 100,
            'portion_full_g_max' => 100,
            'kcal_per_100g_min'  => 300,
            'kcal_per_100g_max'  => 300,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.balance.provisional', true)
                ->where('summary.balance.direction', 'deficit')
                ->etc()
            );
    }

    /**
     * The card renders what it is given: the moment the component derives the
     * balance again, the four states stop being testable and the sign comes back.
     */
    public function test_the_card_renders_the_server_shape_and_derives_nothing(): void
    {
        $code = $this->sourceWithoutComments(self::CARD);

        self::assertStringContainsString(
            'const balance = computed(() => props.summary.balance ?? null)',
            $code,
            self::CARD.' derives the balance itself again.'
        );

        self::assertSame(
            0,
            preg_match('/kcalOut\.value\s*-\s*kcalIn/', $code),
            self::CARD.' subtracts intake from burn in the browser. That arithmetic, and the four states '
                .'it decides between, belong in App\Services\Reporting\EnergyBalance where they can be tested.'
        );

        // A glyph and a colour per state. The WORD moved to lib/balance.js — it
        // varies with `provisional` and is covered by tests/js/balance.test.js.
        foreach (['deficit', 'surplus', 'balanced'] as $direction) {
            self::assertStringContainsString($direction.': {', $code, self::CARD.' has no rendering for '.$direction.'.');
        }

        self::assertStringContainsString("deficit: { arrow: '▼', tone: 'text-teal-600", $code, self::CARD.': a deficit is the app\'s teal.');
        self::assertStringContainsString("surplus: { arrow: '▲', tone: 'text-orange-600", $code, self::CARD.': a surplus is orange.');
        self::assertStringContainsString("balanced: { arrow: '≈', tone: 'text-stone-500", $code, self::CARD.': a straddling band is neutral.');

        // A zero-width band collapses to one certain number; a straddling one
        // reads "≈ 0" with its width spelt out by rangeLine. Both in the module.
        $maths = $this->sourceWithoutComments(self::MATHS);

        self::assertStringContainsString('balance.lo === balance.hi', $maths);

        // The straddle is near-zero in the big slot, never the "−50 to +80" that
        // rendered as "−544−638" on a real phone.
        self::assertStringContainsString("return '≈ 0'", $maths);
        self::assertStringNotContainsString(
            'signed(-balance.max)',
            $maths,
            self::MATHS.': the straddle is emitting a signed range into the hero again.'
        );

        // The range line spells the straddle out, so no dash reads as a sign.
        self::assertStringContainsString('under and ${num(toStep(-balance.min))} over', $maths);

        // "so far" on a day that is still accruing its burn.
        self::assertStringContainsString('balance?.provisional ?', $maths);
        self::assertStringContainsString('so far', $maths);

        /*
         * THE PICTURE IS DRAWN FROM THE SERVER'S OBJECT, NOT FROM THE TOTALS.
         * The track this replaces derived its geometry from the intake band and
         * the burn — a second browser-side opinion that could disagree on screen
         * with a day the server rounded to "balanced". The meter gets the balance
         * object and nothing else.
         */
        self::assertStringContainsString('const geometry = computed(() => meter(headline.value))', $code);

        // `headline` CHOOSES between two server objects, never assembling a
        // third: the projection on a day in progress, else the measured balance.
        self::assertStringContainsString('const headline = computed(() => projection.value ?? balance.value)', $code);

        self::assertSame(
            0,
            preg_match('/meter\(\s*kcal/', $code),
            self::CARD.': the meter is being fed the raw totals again.'
        );

        /*
         * And no sign in front of the number to justify. `EnergyBalance` computes
         * burn − intake, so a surplus is negative and the hero used to flip it by
         * direction and print "eaten − burned" to say which way round. The user
         * asked for the sign to go — word, arrow, colour and side of the meter
         * say it four times already — and the caption went with it.
         */
        foreach (['eaten − burned', 'burn − intake'] as $caption) {
            self::assertStringNotContainsString(
                $caption,
                $code,
                self::CARD.": \"{$caption}\" is back on a hero that has no sign to explain."
            );
        }
    }

    private function meal(string $name, float $portion, float $kcalPer100g): void
    {
        $meal = Meal::factory()->eatenAt(
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(13)
        )->create();

        MealItem::factory()->for($meal)->create([
            'name'               => $name,
            'portion_g_min'      => $portion,
            'portion_g_max'      => $portion,
            'portion_full_g_min' => $portion,
            'portion_full_g_max' => $portion,
            'kcal_per_100g_min'  => $kcalPer100g,
            'kcal_per_100g_max'  => $kcalPer100g,
        ]);
    }

    private function bucket(string $metric, Source $source, int $hour, float $value, string $unit = 'kcal'): void
    {
        $this->metric($metric, $source, CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours($hour), $value, $unit);
    }

    private function metric(string $metric, Source $source, CarbonImmutable $start, float $value, string $unit = 'kcal'): void
    {
        HealthMetric::query()->create([
            'metric'                    => $metric,
            'aggregation'               => MetricAggregation::Sum,
            'period'                    => 'hour',
            'value'                     => $value,
            'unit'                      => $unit,
            'started_at'                => $start,
            'ended_at'                  => $start->addHour(),
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $source->id,
            'ingested_at'               => CarbonImmutable::now(),
        ]);
    }
}
