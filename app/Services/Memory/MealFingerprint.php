<?php

declare(strict_types=1);

namespace App\Services\Memory;

use Illuminate\Support\Str;

/**
 * The identity of a meal SHAPE: a hash of its sorted, slugified
 * item-name set.
 *
 * WHY NAMES, NOT PIXELS (SPEC.md, "Meal memory — redefined"): hashing
 * the photo doesn't work — the same dinner shot twice (angle, light,
 * plate) produces unrelated bytes and hashes, while different dinners
 * on the same white plate look similar. What's stable is what's IN the
 * meal, already text by confirmation time.
 *
 * So the fingerprint is `sha256("broccoli|chicken-breast|rice-white")`:
 *
 *   sorted   — a meal is a set, not a sequence, and vision doesn't
 *              return items in stable order: "rice, chicken" and
 *              "chicken, rice" must be the same meal.
 *   slugged  — "Chicken Breast", "chicken breast" and "Chicken  breast"
 *              are one food; casing/spacing is typing, not information.
 *   de-duped — two slices of the same bread is still a
 *              bread-and-cheese plate (portions are summed when the
 *              snapshot is written; see MemoryRecorder).
 *
 * Deliberately NOT a similarity measure: two meals share an item set or
 * they don't. "Close" is MemoryMatcher's job, on the slug sets this
 * class produces rather than the hashes.
 *
 * Photo similarity via pgvector is deferred on purpose: Laravel 13
 * supports it natively and it'll be worth it one day, but it isn't
 * what makes a repeat dinner pre-fill correctly — building it now
 * would rebuild the thing already established not to work.
 */
final class MealFingerprint
{
    /**
     * Between slugs. A hyphen is already inside them, so a pipe cannot be
     * produced by any single name and "a|b" is unambiguously two items.
     */
    public const SEPARATOR = '|';

    /**
     * sha256, NOT sha1.
     *
     * `meal_memory.fingerprint` is `char(64)` — the width step 1 chose,
     * matching a sha256 hex digest. A 40-character sha1 there would be
     * silently space-padded by Postgres, turning every lookup into a
     * whitespace question. The column, its migration docblock and the
     * step-1 schema tests all say sha256; this follows them.
     */
    private const ALGORITHM = 'sha256';

    /**
     * One item name -> the token that goes into the fingerprint.
     *
     * `Str::slug` does the ASCII fold (curly apostrophes, accents,
     * en-dashes), lowercasing, whitespace and dash collapse in one
     * pass — "Ol’ Rice (white)" and "ol  rice   white" both become
     * `ol-rice-white`.
     *
     * The fallback matters more than it looks: `Str::slug` transliterates
     * to ASCII then strips what's left, so a name written entirely in a
     * non-Latin script slugs to the EMPTY STRING — vanishing from the
     * set or, worse, making every such meal identical. Falling back to
     * a normalised form of the original keeps those names distinct and
     * the function total.
     */
    public function slug(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug !== '') {
            return $slug;
        }

        $normalised = preg_replace('/\s+/u', '-', mb_strtolower(trim($name)));

        return trim((string) $normalised, '-');
    }

    /**
     * The canonical token set for a list of names: slugged, non-empty, unique,
     * sorted.
     *
     * `sort` with SORT_STRING rather than the default: the default
     * would compare "100g-rice" and "2-eggs" numerically, and a
     * fingerprint whose order depends on PHP's opinion of a leading
     * digit isn't a fingerprint.
     *
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public function slugs(iterable $names): array
    {
        $slugs = [];

        foreach ($names as $name) {
            $slug = $this->slug((string) $name);

            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        $slugs = array_keys($slugs);

        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @param  iterable<string>  $names
     */
    public function forNames(iterable $names): string
    {
        return $this->forSlugs($this->slugs($names));
    }

    /**
     * The same hash from tokens that are already slugs.
     *
     * Re-normalises rather than trusting the caller: `meal_items.slug`
     * is stored, and a row written before a slug rule changed would
     * otherwise fingerprint differently from the same meal typed today.
     *
     * @param  list<string>  $slugs
     */
    public function forSlugs(array $slugs): string
    {
        return hash(self::ALGORITHM, implode(self::SEPARATOR, $this->slugs($slugs)));
    }
}
