<?php

declare(strict_types=1);

namespace Tests\Feature\Food;

use Tests\TestCase;
use App\Models\FoodProduct;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET /api/products/{barcode}`.
 *
 * The load-bearing test is the cache one. Open Food Facts allows 15 requests a
 * minute per IP and asks not to be hammered, and SPEC.md's rule — "a re-log
 * never re-hits the network" — is only true if the second lookup makes NO
 * outbound request at all, hence `Http::assertNothingSent()` rather than "the
 * response was fast".
 *
 * The rest is about the shapes OFF actually returns. Its data is crowd-sourced:
 * energy arrives as kcal, as kJ, or as a bare `energy_100g` whose unit lives in
 * a sibling key, and macros are routinely absent. Each has a test because each,
 * mis-read, produces a plausible number wrong by a factor of four.
 */
final class ProductLookupTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const NUTELLA = '3017624010701';

    // --- auth + input -----------------------------------------------------

    public function test_a_guest_gets_json_not_a_redirect_to_the_login_page(): void
    {
        auth()->logout();

        // Under /api so the handler renders JSON: fetch() follows a 302 and
        // would hand the login page back as a "successful" lookup.
        $this->getJson('/api/products/'.self::NUTELLA)->assertStatus(401);
    }

    public function test_a_barcode_that_is_not_a_barcode_is_rejected_before_any_network_call(): void
    {
        Http::fake();

        $this->getJson('/api/products/3017624010702')  // one digit changed
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid_barcode');

        Http::assertNothingSent();
    }

    public function test_a_upc_e_is_expanded_to_the_upc_a_it_stands_for(): void
    {
        Http::fake(['*' => Http::response($this->offPayload())]);

        $this->getJson('/api/products/04252614')->assertOk();

        // Requested and stored expanded: the small barcode on a can and the big
        // one on the multipack are the same product.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '042100005264.json'));

        self::assertNotNull(FoodProduct::query()->find('042100005264'));
    }

    // --- cache ------------------------------------------------------------

    public function test_a_cached_product_is_served_without_touching_the_network(): void
    {
        Http::fake();

        FoodProduct::factory()->create([
            'barcode'       => self::NUTELLA,
            'name'          => 'Nutella',
            'kcal_per_100g' => 539,
        ]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('source', 'cache')
            ->assertJsonPath('product.name', 'Nutella')
            ->assertJsonPath('product.kcalPer100g', 539);

        Http::assertNothingSent();
    }

    public function test_a_miss_fetches_from_open_food_facts_and_persists_it(): void
    {
        // isToday() below compares against now(); freeze it so a run that
        // crosses midnight between the write and the assert cannot flake.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00', (string) config('health.timezone')));

        Http::fake(['*' => Http::response($this->offPayload())]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertOk()
            ->assertJsonPath('source', 'openfoodfacts')
            ->assertJsonPath('product.name', 'Nutella')
            ->assertJsonPath('product.brand', 'Ferrero')
            ->assertJsonPath('product.kcalPer100g', 539)
            ->assertJsonPath('product.proteinPer100g', 6.3);

        $product = FoodProduct::query()->findOrFail(self::NUTELLA);

        self::assertSame('Nutella', $product->name);
        self::assertSame('Ferrero', $product->brand);
        self::assertSame(539.0, (float) $product->kcal_per_100g);
        self::assertSame(57.5, (float) $product->carbs_per_100g);
        self::assertSame(30.9, (float) $product->fat_per_100g);

        // The whole document, not just the columns: a later feature wanting
        // fibre or serving size must not re-hit a rate-limited API.
        $raw = $product->raw;
        self::assertNotNull($raw);
        self::assertSame('success', $raw['status']);
        self::assertSame('Nutella', $raw['product']['product_name']);

        // fetched_at is NOT NULL in the schema — this confirms the write
        // actually happened just now, not merely that the column is typed.
        self::assertTrue($product->fetched_at->isToday());
    }

    public function test_the_mandatory_user_agent_is_sent(): void
    {
        Http::fake(['*' => Http::response($this->offPayload())]);

        $this->getJson('/api/products/'.self::NUTELLA);

        // OFF blocks generic user agents. This string is pinned in SPEC.md.
        Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0]
            === 'HealthTracker/1.0 (user@example.com)');
    }

    // --- parsing ----------------------------------------------------------

    public function test_kcal_is_preferred_over_the_kilojoule_figure(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy-kcal_100g' => 539,
            'energy-kj_100g'   => 2227.9,
            'energy_100g'      => 2227.9,
            'energy_unit'      => 'kJ',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', 539);
    }

    public function test_kilojoules_are_converted_when_that_is_all_there_is(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy-kj_100g' => 2227.9,
        ]))]);

        // 2227.9 / 4.184 = 532.48…
        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', 532.481);
    }

    public function test_the_legacy_energy_field_is_read_with_its_sibling_unit(): void
    {
        // `energy_100g` is kJ far more often than kcal and its unit lives in
        // another key; trusting it blindly inflates this jar fourfold.
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy_100g' => 2227.9,
            'energy_unit' => 'kJ',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', 532.481);
    }

    public function test_the_legacy_energy_field_is_taken_as_is_when_it_says_kcal(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy_100g' => 539,
            'energy_unit' => 'kcal',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', 539);
    }

    public function test_a_missing_macro_is_null_and_never_zero(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy-kcal_100g' => 539,
            'proteins_100g'    => 6.3,
            // No carbohydrates_100g, fat an empty string — both real OFF
            // states, both meaning "nobody filled this in".
            'fat_100g' => '',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.proteinPer100g', 6.3)
            ->assertJsonPath('product.carbsPer100g', null)
            ->assertJsonPath('product.fatPer100g', null);

        $product = FoodProduct::query()->findOrFail(self::NUTELLA);

        self::assertNull($product->carbs_per_100g);
        self::assertNull($product->fat_per_100g);
    }

    public function test_numeric_strings_are_accepted_because_off_sends_them(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy-kcal_100g' => '539',
            'proteins_100g'    => '6.3',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', 539)
            ->assertJsonPath('product.proteinPer100g', 6.3);
    }

    public function test_a_physically_impossible_figure_is_stored_as_null(): void
    {
        // 5390 kcal/100 g is a contributor typing kJ into the kcal box; nothing
        // edible is denser than pure fat at 900. A wrong number silently
        // doubles a day, a null one is visibly missing.
        Http::fake(['*' => Http::response($this->offPayload(nutriments: [
            'energy-kcal_100g'   => 5390,
            'proteins_100g'      => 6.3,
            'carbohydrates_100g' => 400,
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.kcalPer100g', null)
            ->assertJsonPath('product.carbsPer100g', null)
            ->assertJsonPath('product.proteinPer100g', 6.3);
    }

    public function test_a_product_with_no_name_falls_back_to_the_barcode(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(product: [
            'product_name' => '',
            'brands'       => '',
        ]))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.name', self::NUTELLA)
            ->assertJsonPath('product.brand', null);
    }

    public function test_only_the_first_brand_is_kept(): void
    {
        Http::fake(['*' => Http::response($this->offPayload(product: ['brands' => 'Nutella,Ferrero']))]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertJsonPath('product.brand', 'Nutella');
    }

    // --- failure modes ----------------------------------------------------

    public function test_a_product_open_food_facts_has_never_heard_of_is_a_404_and_is_not_cached(): void
    {
        Http::fake(['*' => Http::response([
            'code'   => self::NUTELLA,
            'status' => 'failure',
            'result' => ['id' => 'product_not_found'],
        ], 404)]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertStatus(404)
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('barcode', self::NUTELLA);

        self::assertSame(0, FoodProduct::query()->count());
    }

    public function test_an_open_food_facts_outage_is_a_503_and_is_never_written_to_the_cache(): void
    {
        Http::fake(['*' => Http::response('upstream is down', 503)]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertHeader('Retry-After', '30');

        // A row here poisons the cache with an outage permanently: nothing
        // expires.
        self::assertSame(0, FoodProduct::query()->count());
    }

    public function test_being_rate_limited_by_open_food_facts_reads_as_an_outage_not_as_a_missing_product(): void
    {
        // 429 means "come back in a moment", which is what 503 tells the UI.
        // not_found would send the user off to hand-type nutrition for a
        // product the cache would have had seconds later.
        Http::fake(['*' => Http::response('slow down', 429)]);

        $this->getJson('/api/products/'.self::NUTELLA)
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_a_200_that_says_failure_in_its_body_is_still_a_miss(): void
    {
        Http::fake(['*' => Http::response(['status' => 'failure', 'result' => ['id' => 'product_not_found']])]);

        $this->getJson('/api/products/'.self::NUTELLA)->assertStatus(404);
    }

    // --- refresh ----------------------------------------------------------

    public function test_refresh_bypasses_the_cache_and_rewrites_the_row(): void
    {
        // isToday() below compares against now(); freeze it so a run that
        // crosses midnight between the write and the assert cannot flake.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00', (string) config('health.timezone')));

        FoodProduct::factory()->create([
            'barcode'       => self::NUTELLA,
            'name'          => 'Stale name',
            'kcal_per_100g' => 1,
            'fetched_at'    => CarbonImmutable::now()->subYear(),
        ]);

        Http::fake(['*' => Http::response($this->offPayload())]);

        $this->getJson('/api/products/'.self::NUTELLA.'?refresh=1')
            ->assertOk()
            ->assertJsonPath('source', 'openfoodfacts')
            ->assertJsonPath('product.name', 'Nutella');

        Http::assertSentCount(1);

        $product = FoodProduct::query()->findOrFail(self::NUTELLA);

        self::assertSame('Nutella', $product->name);
        self::assertSame(539.0, (float) $product->kcal_per_100g);
        self::assertTrue($product->fetched_at->isToday());

        // Still one row: refresh updates, it does not accumulate.
        self::assertSame(1, FoodProduct::query()->count());
    }

    public function test_a_refresh_that_cannot_reach_open_food_facts_returns_the_cached_row(): void
    {
        FoodProduct::factory()->create(['barcode' => self::NUTELLA, 'name' => 'Nutella']);

        Http::fake(['*' => Http::response('down', 503)]);

        // They asked for a newer answer, not for the old one to be taken away —
        // and this row is what they are about to log.
        $this->getJson('/api/products/'.self::NUTELLA.'?refresh=1')
            ->assertOk()
            ->assertJsonPath('source', 'cache')
            ->assertJsonPath('product.name', 'Nutella');
    }

    // --- throttle ---------------------------------------------------------

    public function test_lookups_are_rate_limited(): void
    {
        Http::fake();

        FoodProduct::factory()->create(['barcode' => self::NUTELLA]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/products/'.self::NUTELLA)->assertOk();
        }

        // A stuck scan loop must not spend the app's whole OFF allowance.
        $this->getJson('/api/products/'.self::NUTELLA)->assertStatus(429);
    }

    /**
     * A v3 `/product/{code}.json` body, shaped like the real one. The defaults
     * are Nutella's actual published figures, verified live.
     *
     * @param  array<string, mixed>|null  $nutriments
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function offPayload(?array $nutriments = null, array $product = []): array
    {
        return [
            'code'    => self::NUTELLA,
            'errors'  => [],
            'status'  => 'success',
            'result'  => ['id' => 'product_found', 'name' => 'Product found'],
            'product' => [
                'product_name' => 'Nutella',
                'brands'       => 'Ferrero',
                'quantity'     => '400.0 g',
                'nutriments'   => $nutriments ?? [
                    'energy-kcal_100g'   => 539,
                    'energy-kj_100g'     => 2227.9,
                    'energy_100g'        => 2227.9,
                    'energy_unit'        => 'kJ',
                    'proteins_100g'      => 6.3,
                    'carbohydrates_100g' => 57.5,
                    'fat_100g'           => 30.9,
                ],
                ...$product,
            ],
        ];
    }
}
