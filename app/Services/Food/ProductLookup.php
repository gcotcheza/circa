<?php

declare(strict_types=1);

namespace App\Services\Food;

use App\Models\FoodProduct;
use Carbon\CarbonImmutable;

/**
 * Cache-first product lookup: `food_products` before the network, always.
 *
 * The cache is the FEATURE, not an optimisation: OFF allows 15
 * requests/minute per IP, and SPEC.md's rule is "a re-log never re-hits
 * the network" — the same yoghurt scanned four mornings a week must work
 * with the phone in a lift, so a cache miss is the exception path.
 *
 * NOTHING EXPIRES: `fetched_at` is recorded and indexed so staleness is
 * *visible*, but no TTL evicts a row — a six-month-old product is still
 * what was eaten, and silently re-fetching would overwrite a hand-made
 * correction. `?refresh=1` is the deliberate re-read, a tap not a timer,
 * and a refresh that can't reach OFF returns the CACHED row rather than an
 * error, since the user asked for a newer answer, not for the old one to
 * be taken away.
 */
final class ProductLookup
{
    public function __construct(private readonly OpenFoodFacts $off = new OpenFoodFacts) {}

    /**
     * Found ALWAYS carries a row — the two empty answers are the only ones
     * without one, which is what lets a caller read the product off a
     * successful lookup without a second null check.
     *
     * @return array{status: LookupStatus::Found, source: string, product: FoodProduct}|array{status: LookupStatus::NotFound|LookupStatus::Unavailable, source: null, product: null}
     */
    public function find(Barcode $barcode, bool $refresh = false): array
    {
        $cached = FoodProduct::query()->find($barcode->value);

        if ($cached !== null && ! $refresh) {
            return ['status' => LookupStatus::Found, 'source' => 'cache', 'product' => $cached];
        }

        $fetched = $this->off->fetch($barcode);

        if ($fetched['status'] !== LookupStatus::Found) {
            // A failed refresh must not lose the row we already had, and an
            // outage must never be written to the cache as a fact.
            return $cached !== null
                ? ['status' => LookupStatus::Found, 'source' => 'cache', 'product' => $cached]
                : ['status' => $fetched['status'], 'source' => null, 'product' => null];
        }

        return [
            'status'  => LookupStatus::Found,
            'source'  => 'openfoodfacts',
            'product' => $this->store($barcode, $fetched['product']),
        ];
    }

    /**
     * Upsert, keyed on the barcode — updateOrCreate rather than
     * insert-or-ignore, since a refresh exists to REPLACE the parsed
     * columns, and the raw document must move with them or the two would
     * describe different fetches.
     */
    private function store(Barcode $barcode, ProductData $data): FoodProduct
    {
        return FoodProduct::query()->updateOrCreate(
            ['barcode' => $barcode->value],
            [...$data->toAttributes(), 'fetched_at' => CarbonImmutable::now()],
        );
    }
}
