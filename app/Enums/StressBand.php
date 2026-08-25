<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four bands of the 1-99 stress score. HIGHER SCORE = LESS STRESS.
 *
 * The direction and the four names deliberately match the commercial
 * app this replaces, since the user has two years of intuition about
 * them and relearning a scale is a cost with no benefit. What changed
 * is underneath: boundaries fall on the user's OWN distribution, not a
 * population's (see config('health.stress.scale')), so "Great" means
 * better than your usual self, not some average stranger.
 *
 * The floors live in config so the label, colour and legend can't
 * drift apart — this enum is the only thing that reads them.
 */
enum StressBand: string
{
    case Great = 'great';
    case Normal = 'normal';
    case Attention = 'attention';
    case Overload = 'overload';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * The band a score falls in.
     *
     * Walks cases best-first, taking the first whose floor the score
     * clears, so a mis-ordered config can't silently produce a
     * surprise band — worst case it widens one and empties another.
     */
    public static function fromScore(int|float $score): self
    {
        foreach (self::cases() as $band) {
            if ($score >= $band->floor()) {
                return $band;
            }
        }

        return self::Overload;
    }

    /** Lowest score still in this band. */
    public function floor(): int
    {
        /** @var array<string, int> $floors */
        $floors = (array) config('health.stress.scale.bands', [
            'great'     => 80,
            'normal'    => 50,
            'attention' => 20,
            'overload'  => 1,
        ]);

        return (int) ($floors[$this->value] ?? 1);
    }

    /** Highest score still in this band — one below the band above it. */
    public function ceiling(): int
    {
        $cases = self::cases();
        $index = array_search($this, $cases, strict: true);

        // `$this` is always one of `cases()`, so false is unreachable — and it
        // means the same thing as 0 here: no band above this one.
        if ($index === 0 || $index === false) {
            return (int) config('health.stress.scale.max', 99);
        }

        return $cases[$index - 1]->floor() - 1;
    }

    /**
     * What the card says. Sentence case, no exclamation marks: this is a
     * measurement being reported, not a coach.
     */
    public function label(): string
    {
        return match ($this) {
            self::Great     => 'Great',
            self::Normal    => 'Normal',
            self::Attention => 'Pay attention',
            self::Overload  => 'Overload',
        };
    }

    /**
     * One sentence of what the band actually claims, in terms of what
     * was measured. Shipped to the client so the page never states a
     * band's meaning in a template where it could drift from this file.
     */
    public function meaning(): string
    {
        return match ($this) {
            self::Great     => 'HRV well above your own baseline for the time of day.',
            self::Normal    => 'HRV around your own baseline — an ordinary day.',
            self::Attention => 'HRV below your own baseline. Worth noticing, not worth panicking about.',
            self::Overload  => 'HRV far below your own baseline. Illness, alcohol, a very short night or real strain.',
        };
    }
}
