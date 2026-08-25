<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * How a window of days came out: over the burn, under it, or too close to call.
 *
 * The energy chart draws two ~2000 kcal quantities that differ by a few
 * hundred — a handful of pixels on a 0-2500 axis — so it shows "how much"
 * beautifully but "which was bigger" only on close inspection, which is the
 * question people actually open the card with. So that question is also
 * answered in words, once, underneath — same split as BalanceCard on the
 * day view: arithmetic and decisions happen here, testable, and the
 * component just renders what it's given.
 *
 * It counts DECISIONS, not differences: each day is already reduced to a
 * direction by EnergyBalance, the same class the day view uses, so the
 * chart's sentence and the day card can never disagree about a given
 * Tuesday. That means it inherits the case that matters: a day whose
 * INTAKE RANGE COVERS ITS BURN is `balanced` — not knowable, not "even" —
 * and is counted and named, never silently assigned to whichever side the
 * midpoint lands on.
 *
 * Only FULLY LOGGED days are in it. A day with two of four meals logged
 * has an intake figure that's a FLOOR, and "ate under your burn" computed
 * from a floor is a fact about how much was written down, not about the
 * day — it always leans the same way, so someone who logs breakfast and
 * forgets dinner would be told they're under their burn every day. So the
 * caller hands this only days with a complete food log AND a measured
 * burn, and the sentence prints that count as its denominator; below
 * `min_days` there's no sentence, since one day is not a pattern.
 */
final readonly class BalanceTally
{
    private function __construct(
        /** Days that were both fully logged and fully measured. */
        public int $days,
        public int $surplus,
        public int $deficit,
        /** Days whose intake range covers the burn: which side is not knowable. */
        public int $balanced,
        /** One sentence, or null when there is not enough to say one. */
        public ?string $sentence,
    ) {}

    /**
     * @param  list<EnergyBalance>  $balances  one per fully logged, fully measured day
     */
    public static function from(array $balances, ?int $minDays = null): self
    {
        $minDays ??= max(2, (int) config('health.trends.balance_min_days', 2));

        $count = count($balances);

        $surplus = self::count($balances, EnergyBalance::SURPLUS);
        $deficit = self::count($balances, EnergyBalance::DEFICIT);
        $balanced = self::count($balances, EnergyBalance::BALANCED);

        return new self(
            days: $count,
            surplus: $surplus,
            deficit: $deficit,
            balanced: $balanced,
            sentence: $count < $minDays
                ? null
                : self::sentence($count, $surplus, $deficit),
        );
    }

    /**
     * The line itself — five forms, no more. "Over"/"under" rather than the
     * day card's "surplus"/"deficit," since this captions a picture of two
     * bars and "ate over your burn" is what the taller one means; "surplus"
     * is a word the day card can afford to teach next to its formula. The
     * denominator appears in every form — the only thing saying this is
     * about three days, not seven.
     */
    private static function sentence(int $count, int $surplus, int $deficit): string
    {
        $days = "of the {$count} fully logged days";

        if ($surplus === $count) {
            return "Ate over your burn on all {$count} fully logged days.";
        }

        if ($deficit === $count) {
            return "Ate under your burn on all {$count} fully logged days.";
        }

        // Every knowable day was unknowable: each intake range straddles that
        // day's burn. A real answer, and a different one from "it was even".
        if ($surplus === 0 && $deficit === 0) {
            return "Too close to call on all {$count} fully logged days — each day's intake range covers its burn.";
        }

        if ($surplus > $deficit) {
            return "Ate over your burn on {$surplus} {$days}.";
        }

        if ($deficit > $surplus) {
            return "Ate under your burn on {$deficit} {$days}.";
        }

        return "Ate over your burn on {$surplus} {$days} and under on {$deficit}.";
    }

    /**
     * @param  list<EnergyBalance>  $balances
     */
    private static function count(array $balances, string $direction): int
    {
        return count(array_filter(
            $balances,
            static fn (EnergyBalance $balance): bool => $balance->direction === $direction,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'days'     => $this->days,
            'surplus'  => $this->surplus,
            'deficit'  => $this->deficit,
            'balanced' => $this->balanced,
            'sentence' => $this->sentence,
        ];
    }
}
