<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FoodProduct;
use Illuminate\Http\Request;
use App\Services\Food\Barcode;
use Illuminate\Http\JsonResponse;
use App\Services\Food\LookupStatus;
use App\Services\Food\ProductLookup;

/**
 * `GET /api/products/{barcode}` — what is this thing I just scanned?
 *
 * It lives in routes/web.php despite the /api prefix: the signed-in PWA calls
 * it with the session cookie it already has, so it belongs in the web
 * middleware group. The `api` group is deliberately session-less and CSRF-less
 * because Health Auto Export posts to it unattended with a shared secret, and a
 * user-facing endpoint there would mean hand-rolling auth or widening that
 * group. The path prefix is kept because bootstrap/app.php renders exceptions
 * as JSON for `api/*`, so a guest gets `401 {"message": ...}` rather than a 302
 * to the login page that fetch() would follow and hand back as HTML.
 *
 * THREE FAILURES, THREE STATUS CODES, because the front end acts differently
 * on each:
 *
 *   422 invalid_barcode  the digits are not a barcode. Fix the typing.
 *   404 not_found        OFF has never heard of it. Offer manual entry.
 *   503 unavailable      OFF is down or rate-limiting. Offer retry.
 *
 * What this must never do is answer "no" in a way that makes "not in the
 * database" look like "the network is broken".
 */
final class ProductLookupController extends Controller
{
    public function __construct(private readonly ProductLookup $lookup) {}

    public function __invoke(Request $request, string $barcode): JsonResponse
    {
        $parsed = Barcode::tryFrom($barcode);

        if ($parsed === null) {
            return response()->json([
                'status'  => 'invalid_barcode',
                'message' => 'That is not an EAN-8, EAN-13, UPC-A or UPC-E barcode.',
            ], 422);
        }

        $result = $this->lookup->find(
            $parsed,
            // Anything truthy: this is a URL a human may type. `boolean()`
            // accepts 1/true/on/yes and treats everything else as false.
            $request->boolean('refresh'),
        );

        // Answered before the match rather than inside it: `find()` only
        // reports Found with a row behind it, and reading the product off
        // that answer is what makes the null check below unnecessary rather
        // than merely absent.
        if ($result['status'] === LookupStatus::Found) {
            return response()->json([
                'status'  => 'found',
                'source'  => $result['source'],
                'product' => $this->productProps($result['product']),
            ]);
        }

        return match ($result['status']) {
            LookupStatus::NotFound => response()->json([
                'status'  => 'not_found',
                'barcode' => $parsed->value,
                'message' => 'Open Food Facts has no entry for this barcode.',
            ], 404),

            LookupStatus::Unavailable => response()->json([
                'status'  => 'unavailable',
                'barcode' => $parsed->value,
                'message' => 'Could not reach Open Food Facts. Try again in a moment.',
            ], 503, ['Retry-After' => '30']),
        };
    }

    /**
     * camelCase to match the Inertia props the front end reads, and floats
     * rather than the numeric strings Postgres hands back for decimals. `null`
     * means "OFF has no figure", shown as a dash, never a zero.
     *
     * `raw` is deliberately NOT exposed: ~250 fields and 40 KB per product,
     * none of it rendered here, stored for later features.
     *
     * @return array<string, mixed>
     */
    private function productProps(FoodProduct $product): array
    {
        return [
            'barcode'        => $product->barcode,
            'name'           => $product->name,
            'brand'          => $product->brand,
            'kcalPer100g'    => $this->number($product->kcal_per_100g),
            'proteinPer100g' => $this->number($product->protein_per_100g),
            'carbsPer100g'   => $this->number($product->carbs_per_100g),
            'fatPer100g'     => $this->number($product->fat_per_100g),
            'fetchedAt'      => $product->fetched_at->toIso8601String(),
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
