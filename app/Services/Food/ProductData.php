<?php

declare(strict_types=1);

namespace App\Services\Food;

/**
 * One Open Food Facts product, reduced to the columns `food_products` keeps.
 *
 * The full response travels alongside in `$raw`, stored verbatim: OFF
 * publishes ~250 fields per product and a later feature wanting one
 * shouldn't have to re-hit an API rate-limited to 15 requests a minute.
 *
 * The parsed columns are NOT a view over that jsonb — extracted once so a
 * wrong OFF entry can be corrected locally with an UPDATE, and so the read
 * path is an indexable column, not a jsonb traversal on every meal render.
 */
final readonly class ProductData
{
    /**
     * @param  array<string, mixed>  $raw  the whole v3 response
     */
    public function __construct(
        public string $name,
        public ?string $brand,
        public ?float $kcalPer100g,
        public ?float $proteinPer100g,
        public ?float $carbsPer100g,
        public ?float $fatPer100g,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  a decoded v3 `/product/{code}.json` body
     */
    public static function fromResponse(array $payload, Barcode $barcode): self
    {
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];

        $nutriments = new Nutriments(
            is_array($product['nutriments'] ?? null) ? $product['nutriments'] : []
        );

        return new self(
            name: self::name($product, $barcode),
            brand: self::brand($product),
            kcalPer100g: $nutriments->kcalPer100g(),
            proteinPer100g: $nutriments->proteinPer100g(),
            carbsPer100g: $nutriments->carbsPer100g(),
            fatPer100g: $nutriments->fatPer100g(),
            raw: $payload,
        );
    }

    /**
     * @return array{name: string, brand: string|null, kcal_per_100g: float|null, protein_per_100g: float|null, carbs_per_100g: float|null, fat_per_100g: float|null, raw: array<string, mixed>} in `food_products` column shape
     */
    public function toAttributes(): array
    {
        return [
            'name'             => $this->name,
            'brand'            => $this->brand,
            'kcal_per_100g'    => $this->kcalPer100g,
            'protein_per_100g' => $this->proteinPer100g,
            'carbs_per_100g'   => $this->carbsPer100g,
            'fat_per_100g'     => $this->fatPer100g,
            'raw'              => $this->raw,
        ];
    }

    /**
     * `food_products.name` is NOT NULL, and no name is a real OFF state
     * (scanned, not yet filled in). The barcode is the last fallback: at
     * least true, and what the user is looking at on the package.
     *
     * @param  array<string, mixed>  $product
     */
    private static function name(array $product, Barcode $barcode): string
    {
        $candidates = [
            'product_name',
            'product_name_en',
            'abbreviated_product_name',
            'generic_name',
            'generic_name_en',
            'brands',
        ];

        foreach ($candidates as $key) {
            $value = $product[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 191);
            }
        }

        return $barcode->value;
    }

    /**
     * `brands` is a comma-separated list ("Nutella,Ferrero"); the first
     * entry is the one on the front of the package.
     *
     * @param  array<string, mixed>  $product
     */
    private static function brand(array $product): ?string
    {
        $brands = $product['brands'] ?? null;

        if (! is_string($brands)) {
            return null;
        }

        $first = trim(explode(',', $brands)[0]);

        return $first === '' ? null : mb_substr($first, 0, 191);
    }
}
