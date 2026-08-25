<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * Burn minus intake, said in a direction rather than in a sign.
 *
 * What the user saw:
 *
 *     Surplus  -143–-49 kcal
 *
 * Four glyphs doing different jobs — a minus, a range dash, another minus
 * — and the number meaning "you ate 143 kcal more than you burned" is
 * written as the SMALLER of two negatives. Correct, and unreadable, which
 * on the one line of this app somebody checks every day is the same as
 * being wrong.
 *
 * The direction is already in the numbers; the display was making the
 * reader work it out. So the direction is named and the figures unsigned:
 *
 *   whole band above zero   Deficit 49–143 kcal   — burned more than eaten
 *   whole band below zero   Surplus 49–143 kcal   — ate more than burned
 *   the band straddles zero Balanced              — not knowable which
 *                                                   side of maintenance you
 *                                                   landed on, and a word
 *                                                   that pretends otherwise
 *                                                   is the false precision
 *                                                   this app exists to
 *                                                   avoid. Signed range
 *                                                   still shown, small.
 *
 * `lo` and `hi` are ABSOLUTE and small end first, why a surplus reads
 * "49–143" for a band of −143..−49: as a quantity of surplus, 49 is the
 * small end. Sorting by magnitude is why this is computed here rather
 * than in the component — it's the step that inverts.
 *
 * Server-side because BalanceCard used to derive the band itself: the
 * arithmetic is trivial, the DECISIONS are not — which side of zero
 * counts as "clearly", what a zero-width band says, what happens at
 * exactly zero, whether rounding happens before or after the direction is
 * chosen. Those were the parts that were wrong, untestable in a Vue
 * component with no JS test runner. Here each is a case in
 * tests/Unit/Reporting/EnergyBalanceTest.php and the component just
 * renders what it's given.
 *
 * Rounding happens first, direction chosen second: a band of 0.4..50 kcal
 * is not a deficit anybody should be told about — rounded it starts at 0,
 * and "Deficit 0–50" while claiming the whole band is above zero would
 * argue with itself. The word and the figures must be two readings of the
 * same numbers.
 */
final readonly class EnergyBalance
{
    public const DEFICIT = 'deficit';

    public const SURPLUS = 'surplus';

    public const BALANCED = 'balanced';

    private function __construct(
        /** deficit | surplus | balanced */
        public string $direction,
        /** The magnitude, small end first. Equal ends are a single figure. */
        public float $lo,
        public float $hi,
        /** The signed band, burn − intake, for the balanced case's small print. */
        public float $min,
        public float $max,
        /** A day still in progress: the burn is not finished. */
        public bool $provisional,
    ) {}

    /**
     * The band, or null when there is nothing to say.
     *
     * Null on a day with no expenditure figure and on a day with nothing
     * logged — the card has a sentence for each, and inventing a balance
     * out of one half of the subtraction is exactly the invention this app
     * is built not to commit.
     */
    public static function from(?float $kcalOut, ?float $kcalInMin, ?float $kcalInMid, ?float $kcalInMax, bool $provisional): ?self
    {
        if ($kcalOut === null || $kcalInMid === null) {
            return null;
        }

        // A band's low end comes from the intake's HIGH end: eating more leaves
        // less of a deficit.
        $min = round($kcalOut - ($kcalInMax ?? $kcalInMid));
        $max = round($kcalOut - ($kcalInMin ?? $kcalInMid));

        // Defensive, and cheap: an intake band whose ends arrived the wrong way
        // round would otherwise render as a negative-width balance.
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $direction = match (true) {
            $min > 0.0 => self::DEFICIT,
            $max < 0.0 => self::SURPLUS,
            default    => self::BALANCED,
        };

        // Magnitudes, small end first. For a surplus that inverts the ends: the
        // band -143..-49 is 49 to 143 kcal OF SURPLUS.
        $lo = min(abs($min), abs($max));
        $hi = max(abs($min), abs($max));

        return new self(
            direction: $direction,
            // A straddling band is not a quantity of anything, so its
            // magnitudes are meaningless — the card shows the signed range
            // instead. Zeroed rather than left to be misread.
            lo: $direction === self::BALANCED ? 0.0 : $lo,
            hi: $direction === self::BALANCED ? 0.0 : $hi,
            min: $min,
            max: $max,
            provisional: $provisional,
        );
    }

    /** @return array{direction: string, lo: float, hi: float, min: float, max: float, provisional: bool} */
    public function toArray(): array
    {
        return [
            'direction'   => $this->direction,
            'lo'          => $this->lo,
            'hi'          => $this->hi,
            'min'         => $this->min,
            'max'         => $this->max,
            'provisional' => $this->provisional,
        ];
    }
}
