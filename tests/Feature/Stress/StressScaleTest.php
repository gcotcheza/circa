<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Enums\StressBand;
use App\Services\Stress\StressScale;

/**
 * The z -> 1-99 map, and the two anchors it is solved from. Arithmetic, no
 * database: it lives under Feature only because the anchors and band floors are
 * config, and config wants an application.
 */
final class StressScaleTest extends TestCase
{
    public function test_a_day_on_your_own_baseline_lands_in_the_middle_of_normal(): void
    {
        $scale = new StressScale;

        // The feature's whole argument in one assertion: z = 0 is an ORDINARY
        // day and must not read as a warning. Mapping it to 50 — the floor of
        // Normal — puts half an ordinary life into "Pay attention".
        self::assertSame(65, $scale->score(0.0));
        self::assertSame(StressBand::Normal, $scale->band(0.0));
    }

    public function test_the_great_anchor_is_where_great_begins(): void
    {
        $scale = new StressScale;

        $greatZ = (float) config('health.stress.scale.great_z');

        self::assertSame(80, $scale->score($greatZ));
        self::assertSame(StressBand::Great, $scale->band($greatZ));

        // And a hair below it is not Great yet.
        self::assertSame(StressBand::Normal, $scale->band($greatZ - 0.05));
    }

    public function test_moving_an_anchor_moves_the_map_and_nothing_else_has_to_change(): void
    {
        // The anchors are the editable decision; s and z0 are solved from them.
        // A harsher app: an ordinary day reads 55, not 65.
        config()->set('health.stress.scale.baseline_score', 55);

        $scale = new StressScale;

        self::assertSame(55, $scale->score(0.0));
        self::assertSame(80, $scale->score((float) config('health.stress.scale.great_z')));
    }

    public function test_the_map_is_strictly_increasing(): void
    {
        $scale = new StressScale;

        $previous = 0;

        for ($z = -4.0; $z <= 4.0; $z += 0.05) {
            $score = $scale->score($z);

            self::assertGreaterThanOrEqual($previous, $score);

            $previous = $score;
        }
    }

    public function test_it_saturates_instead_of_clamping(): void
    {
        $scale = new StressScale;

        // A clamp piles every extreme day onto one number, hiding the
        // difference between an awful day and an unprecedented one.
        self::assertGreaterThan($scale->score(-6.0), $scale->score(-3.5));
        self::assertLessThan($scale->score(6.0), $scale->score(3.5));

        // ...but never off the ends of the published scale.
        self::assertSame(1, $scale->score(-50.0));
        self::assertSame(99, $scale->score(50.0));
    }

    public function test_the_band_boundaries_sit_where_the_solved_map_says_they_do(): void
    {
        $scale = new StressScale;

        // Great starts at exactly the configured anchor...
        self::assertEqualsWithDelta(0.85, $scale->zFor(80), 1e-6);
        // ...Pay attention about 0.71 sigma below baseline...
        self::assertEqualsWithDelta(-0.712, $scale->zFor(50), 1e-3);
        // ...and Overload only past 2.27 sigma below, which is a rare day.
        self::assertEqualsWithDelta(-2.273, $scale->zFor(20), 1e-3);
    }

    public function test_the_bands_ship_with_their_own_boundaries_and_meanings(): void
    {
        $bands = (new StressScale)->bands();

        self::assertCount(4, $bands);

        // Best first, contiguous, no gaps and no overlaps — what stops a legend
        // disagreeing with a colour.
        self::assertSame(['great', 'normal', 'attention', 'overload'], array_column($bands, 'band'));
        self::assertSame([80, 50, 20, 1], array_column($bands, 'from'));
        self::assertSame([99, 79, 49, 19], array_column($bands, 'to'));

        foreach ($bands as $band) {
            self::assertNotSame('', $band['meaning']);
        }
    }

    public function test_a_degenerate_anchor_pair_still_answers(): void
    {
        // Both anchors on one score is a config error; it must not divide by
        // zero and hand every day a NAN.
        config()->set('health.stress.scale.baseline_score', 80);

        $scale = new StressScale;

        self::assertGreaterThanOrEqual(1, $scale->score(0.0));
        self::assertLessThanOrEqual(99, $scale->score(0.0));
    }
}
