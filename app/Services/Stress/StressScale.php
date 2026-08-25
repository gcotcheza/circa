<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Enums\StressBand;

/**
 * z (personal standard deviations from baseline) -> a 1-99 score.
 *
 * The map:
 *
 *     score = min + (max - min) x Phi((z - z0) / s)
 *
 * Phi is the standard normal CDF, used purely as a shape: smooth, strictly
 * increasing, saturating at both ends — the point. A linear map would need
 * a clamp, and a clamp puts a hard edge somewhere arbitrary: -3.4 sigma and
 * -6 sigma would both read exactly 1, the pile-up invisible. Here they read
 * 8 and 1, and the gap between an awful day and an unprecedented one
 * survives.
 *
 * s and z0 come from two anchors in config, not two magic numbers, so a
 * human edits the decisions and the arithmetic follows:
 *
 *   `baseline_score` (65): a day exactly on your own baseline (z = 0)
 *      scores 65, the middle of the Normal band. The obvious alternative,
 *      z = 0 -> 50, is interestingly wrong: 50 is the FLOOR of Normal, so
 *      the median day lands in "Pay attention" by construction — what the
 *      commercial app being replaced does, and why its score is ignored. A
 *      monitor that cries wolf every other day hasn't measured anything,
 *      it's added a coin flip to the home screen.
 *
 *   `great_z` (0.85): 0.85 personal sigma above baseline is where Great
 *      begins, mapping to the band floor 80 — roughly the best fifth of
 *      days, what "great" ought to mean.
 *
 * Two anchors, two unknowns:
 *
 *     Phi(( 0      - z0) / s) = (65 - 1) / 98
 *     Phi((great_z - z0) / s) = (80 - 1) / 98
 *
 * giving s = 1.808 and z0 = -0.712 for the shipped values, with band
 * boundaries at z = +0.85 (Great), z = -0.71 (Pay attention), z = -2.27
 * (Overload).
 *
 * What that produces on real data: across the 819 days of this user's
 * history with both readings and a baseline, 21.3% Great, 55.0% Normal,
 * 21.0% Pay attention, 2.7% Overload (last year alone: 20.9/54.4/23.1/1.6).
 * A measured property of the anchors and the data together, and the check
 * that would catch an anchor edit making the app useless or hysterical.
 */
final class StressScale
{
    public readonly int $min;

    public readonly int $max;

    /** Width of the squash, in personal sigma. */
    public readonly float $spread;

    /** The z that lands on the middle of the 1-99 range. */
    public readonly float $centre;

    /** @param  array<string, mixed>|null  $config  `health.stress.scale`, or a test's own */
    public function __construct(?array $config = null)
    {
        $config ??= (array) config('health.stress.scale', []);

        $this->min = (int) ($config['min'] ?? 1);
        $this->max = (int) ($config['max'] ?? 99);

        $span = max(1, $this->max - $this->min);

        $baselineScore = (float) ($config['baseline_score'] ?? 65);
        $greatZ = (float) ($config['great_z'] ?? 0.85);

        $greatScore = (float) ($config['bands']['great'] ?? 80);

        // The two probabilities the anchors correspond to, as fractions of the
        // 1-99 range.
        $pBaseline = ($baselineScore - $this->min) / $span;
        $pGreat = ($greatScore - $this->min) / $span;

        $qBaseline = Normal::inverseCdf($pBaseline);
        $qGreat = Normal::inverseCdf($pGreat);

        $gap = $qGreat - $qBaseline;

        // A degenerate config (anchors equal, or inverted) would divide by
        // ~zero and hand every day the same score. Falling back to a unit
        // spread keeps the app answering; the anchors are asserted in tests.
        $this->spread = abs($gap) < 1e-9 ? 1.0 : $greatZ / $gap;
        $this->centre = -$this->spread * $qBaseline;
    }

    /**
     * The score for a standardised deviation. Higher z (HRV above baseline) ->
     * higher score -> less stress.
     */
    public function score(float $z): int
    {
        $span = $this->max - $this->min;

        $raw = $this->min + $span * Normal::cdf(($z - $this->centre) / $this->spread);

        // Rounded once, here, at the edge of the calculation — everything
        // upstream stays float, so a stored z and score can't disagree
        // about which side of a band boundary a day fell.
        return (int) max($this->min, min($this->max, round($raw)));
    }

    public function band(float $z): StressBand
    {
        return StressBand::fromScore($this->score($z));
    }

    /**
     * The z at which a given score is reached — the inverse, used to state band
     * boundaries in the units they were reasoned about.
     */
    public function zFor(int $score): float
    {
        $span = max(1, $this->max - $this->min);

        return $this->centre + $this->spread * Normal::inverseCdf(($score - $this->min) / $span);
    }

    /**
     * The bands as the client draws them: floor, ceiling, label, meaning.
     *
     * Shipped with the page so the legend and colours come from the same
     * config that decided the score, rather than restated in a template
     * where they'd drift the first time a threshold moved.
     *
     * @return list<array<string, mixed>>
     */
    public function bands(): array
    {
        return array_map(static fn (StressBand $band): array => [
            'band'    => $band->value,
            'label'   => $band->label(),
            'meaning' => $band->meaning(),
            'from'    => $band->floor(),
            'to'      => $band->ceiling(),
        ], StressBand::cases());
    }
}
