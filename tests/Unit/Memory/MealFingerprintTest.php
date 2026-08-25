<?php

declare(strict_types=1);

namespace Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use App\Services\Memory\MealFingerprint;

/**
 * The identity of a meal SHAPE: "seen before", pre-filled portions and
 * times_logged are all lookups by this value, so an unstable one does not
 * degrade the feature, it silently switches it off — every dinner looks new.
 *
 * Plain PHPUnit: string arithmetic, no database, no container.
 */
final class MealFingerprintTest extends TestCase
{
    private MealFingerprint $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingerprints = new MealFingerprint;
    }

    public function test_the_same_names_always_hash_the_same(): void
    {
        $names = ['Chicken breast', 'White rice', 'Broccoli'];

        self::assertSame(
            $this->fingerprints->forNames($names),
            $this->fingerprints->forNames($names)
        );

        // sha256 hex — what the char(64) column is sized for.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fingerprints->forNames($names));
    }

    public function test_order_does_not_matter_because_a_meal_is_a_set(): void
    {
        self::assertSame(
            $this->fingerprints->forNames(['Chicken breast', 'White rice', 'Broccoli']),
            $this->fingerprints->forNames(['Broccoli', 'White rice', 'Chicken breast'])
        );
    }

    public function test_case_spacing_and_punctuation_are_typing_not_information(): void
    {
        $canonical = $this->fingerprints->forNames(['Chicken breast', 'White rice']);

        self::assertSame($canonical, $this->fingerprints->forNames(['CHICKEN BREAST', 'white  rice']));
        self::assertSame($canonical, $this->fingerprints->forNames(['  chicken   breast ', 'White-rice']));
        self::assertSame($canonical, $this->fingerprints->forNames(['chicken--breast', 'white_rice']));
    }

    /**
     * iOS substitutes a curly apostrophe as you type, so "Ol' rice" and
     * "Ol’ rice" are one food on two keyboards. An accent-sensitive
     * fingerprint would remember "Crème brûlée" and miss "Creme brulee".
     */
    public function test_unicode_folds_to_the_same_fingerprint(): void
    {
        self::assertSame(
            $this->fingerprints->forNames(["Ol' rice"]),
            $this->fingerprints->forNames(['Ol’ rice'])
        );

        self::assertSame(
            $this->fingerprints->forNames(['Creme brulee']),
            $this->fingerprints->forNames(['Crème brûlée'])
        );

        self::assertSame('cafe-au-lait', $this->fingerprints->slug('  Café  au   LAIT '));
        self::assertSame('naive-pinata', $this->fingerprints->slug('naïve piñata'));
    }

    /**
     * Str::slug transliterates to ASCII and strips the rest, so a non-Latin
     * name slugs to empty — a token that either vanishes from the set or makes
     * every such meal identical. Both silent; the second is worse.
     */
    public function test_a_name_that_ascii_slugs_to_nothing_keeps_its_own_identity(): void
    {
        self::assertNotSame('', $this->fingerprints->slug('日本語'));

        self::assertNotSame(
            $this->fingerprints->forNames(['日本語']),
            $this->fingerprints->forNames(['한국어'])
        );

        // Still under the same normalisation: case and spacing do not count.
        self::assertSame(
            $this->fingerprints->forNames(['  ЩИ  ']),
            $this->fingerprints->forNames(['щи'])
        );
    }

    public function test_a_repeated_food_is_one_token_but_a_different_food_is_not(): void
    {
        // Two slices is still a bread plate; the PORTIONS are summed when the
        // snapshot is written — ConfirmedMeal.
        self::assertSame(
            $this->fingerprints->forNames(['Rye bread', 'Rye bread', 'Cheese']),
            $this->fingerprints->forNames(['Rye bread', 'Cheese'])
        );

        self::assertNotSame(
            $this->fingerprints->forNames(['Rye bread', 'Cheese']),
            $this->fingerprints->forNames(['Rye bread', 'Cheese', 'Butter'])
        );
    }

    public function test_empty_and_whitespace_only_names_are_dropped(): void
    {
        self::assertSame(
            $this->fingerprints->forNames(['Rye bread']),
            $this->fingerprints->forNames(['Rye bread', '', '   '])
        );

        self::assertSame([], $this->fingerprints->slugs(['', '  ']));
    }

    /**
     * A STRING sort: PHP's default flag compares "100g-rice" and "2-eggs"
     * numerically, making token order — and the hash — depend on a leading digit.
     */
    public function test_numeric_looking_names_sort_as_strings(): void
    {
        self::assertSame(
            ['100g-rice', '2-eggs'],
            $this->fingerprints->slugs(['2 eggs', '100g rice'])
        );

        self::assertSame(
            $this->fingerprints->forNames(['2 eggs', '100g rice']),
            $this->fingerprints->forNames(['100g rice', '2 eggs'])
        );
    }

    public function test_hashing_from_stored_slugs_matches_hashing_from_names(): void
    {
        // meal_items.slug is stored, so hashing those rows has to agree with
        // hashing the names they came from.
        self::assertSame(
            $this->fingerprints->forNames(['White rice', 'Chicken breast']),
            $this->fingerprints->forSlugs(['chicken-breast', 'white-rice'])
        );

        // ...including slugs not normalised the way today's rule would.
        self::assertSame(
            $this->fingerprints->forNames(['White rice']),
            $this->fingerprints->forSlugs(['White  Rice'])
        );
    }
}
