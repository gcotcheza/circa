<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Models\DailySummary;
use App\Models\MealMemoryItem;
use Tests\Concerns\ActsAsFreshUser;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The everyday surface: what the picker shows, in what order, and what one tap
 * on it does.
 *
 * The search tests are why this suite runs against a real Postgres: no portable
 * double stands in for `pg_trgm` similarity, and the claim is "a typo still
 * finds the meal", which only the extension can settle.
 */
final class MemoryPickerTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    public function test_the_endpoint_is_behind_the_session(): void
    {
        $memory = $this->remember('Porridge', ['Porridge']);

        auth()->logout();

        // /api/* renders JSON errors, so fetch() gets a 401 rather than the login
        // page's HTML with a 200 on it.
        $this->getJson('/api/meal-memory')->assertUnauthorized();
        $this->post("/meal-memory/{$memory->id}/log", [])->assertRedirect(route('login'));
    }

    /**
     * Frecency: ln(1 + times_logged) * 0.5 ^ (days_since / half_life).
     *
     * The case that matters is where the halves disagree — a meal eaten far more
     * often but months ago must lose to one eaten twice this week. Ranking by
     * times_logged alone is how a picker ossifies around what somebody used to
     * eat.
     */
    public function test_frequent_and_recent_beats_frequent_and_stale(): void
    {
        $this->remember('Winter stew', ['Beef stew'], timesLogged: 20, daysAgo: 60);
        $this->remember('Summer bowl', ['Poke bowl'], timesLogged: 2, daysAgo: 0);
        $this->remember('Forgotten thing', ['Fried egg'], timesLogged: 1, daysAgo: 200);

        $memories = $this->getJson('/api/meal-memory')->assertOk()->json('memories');
        self::assertIsArray($memories);

        $labels = collect($memories)->pluck('label')->all();

        self::assertSame(['Summer bowl', 'Winter stew', 'Forgotten thing'], $labels);
    }

    public function test_ties_on_recency_are_broken_by_how_often_it_is_eaten(): void
    {
        $this->remember('Twice', ['Poached egg'], timesLogged: 2, daysAgo: 3);
        $this->remember('Nine times', ['Scrambled egg'], timesLogged: 9, daysAgo: 3);

        $memories = $this->getJson('/api/meal-memory')->json('memories');
        self::assertIsArray($memories);

        $labels = collect($memories)->pluck('label')->all();

        self::assertSame(['Nine times', 'Twice'], $labels);
    }

    public function test_a_memory_with_no_items_is_not_offered(): void
    {
        MealMemory::query()->create([
            'fingerprint'    => str_repeat('a', 64),
            'canonical_name' => 'Empty',
            'times_logged'   => 99,
            'last_logged_at' => now(),
        ]);

        $this->getJson('/api/meal-memory')->assertOk()->assertJsonCount(0, 'memories');
    }

    public function test_search_survives_a_typo(): void
    {
        $this->remember('Chicken breast, White rice', ['Chicken breast', 'White rice']);
        $this->remember('Porridge with banana', ['Porridge', 'Banana']);

        $this->getJson('/api/meal-memory?q='.urlencode('chicken brest'))
            ->assertOk()
            ->assertJsonCount(1, 'memories')
            ->assertJsonPath('memories.0.label', 'Chicken breast, White rice');
    }

    /** Nobody remembers what the app called their lunch, only that it had salmon in it. */
    public function test_search_matches_the_foods_inside_a_meal(): void
    {
        $this->remember('Tuesday bowl', ['Salmon fillet', 'Quinoa']);
        $this->remember('Porridge with banana', ['Porridge', 'Banana']);

        $this->getJson('/api/meal-memory?q=salmon')
            ->assertOk()
            ->assertJsonCount(1, 'memories')
            ->assertJsonPath('memories.0.label', 'Tuesday bowl');
    }

    public function test_a_search_that_matches_nothing_returns_nothing(): void
    {
        $this->remember('Porridge with banana', ['Porridge', 'Banana']);

        $this->getJson('/api/meal-memory?q=lasagne')
            ->assertOk()
            ->assertJsonCount(0, 'memories');
    }

    public function test_an_empty_query_falls_back_to_the_ranked_list(): void
    {
        $this->remember('Porridge with banana', ['Porridge', 'Banana']);

        $this->getJson('/api/meal-memory?q=%20%20')
            ->assertOk()
            ->assertJsonCount(1, 'memories');
    }

    /**
     * One tap produces an ORDINARY confirmed meal — same writer, same rollup,
     * editable and deletable. Anything else is a second kind of meal every later
     * feature has to learn about.
     */
    public function test_one_tap_logs_the_remembered_meal_onto_the_day(): void
    {
        $memory = $this->remember('Chicken breast, White rice', ['Chicken breast', 'White rice']);

        $uuid = (string) Str::uuid();

        $this->post("/meal-memory/{$memory->id}/log", [
            'uuid' => $uuid,
            'date' => '2026-06-15',
            'time' => '12:30',
        ])->assertRedirect('/?date=2026-06-15')->assertSessionHas('success');

        $meal = Meal::query()->where('uuid', $uuid)->with('items')->firstOrFail();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame(MealSource::Manual, $meal->source);
        self::assertSame('2026-06-15', $meal->local_date->toDateString());
        self::assertCount(2, $meal->items);
        self::assertNotNull($this->notNull($meal->items->first())->confirmed_at);

        // A single number for the portion; the density band survives the trip.
        $rice = $this->notNull($meal->items->firstWhere('slug', 'white-rice'));

        self::assertSame(200.0, (float) $rice->portion_g_min);
        self::assertSame(200.0, (float) $rice->portion_g_max);
        self::assertSame(120.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(140.0, (float) $rice->kcal_per_100g_max);

        // The day was rebuilt inline, because the user is about to look at it.
        $summary = DailySummary::query()->find('2026-06-15');

        self::assertNotNull($summary);
        self::assertGreaterThan(0, (float) $summary->kcal_in_mid);
    }

    /**
     * Logging something again is logging it: the re-log goes through the same
     * writer, so the same observer records it and the ranking it came from moves.
     */
    public function test_logging_from_the_picker_teaches_the_picker(): void
    {
        $memory = $this->remember('Chicken breast, White rice', ['Chicken breast', 'White rice'], timesLogged: 4);

        $this->post("/meal-memory/{$memory->id}/log", [
            'uuid' => (string) Str::uuid(),
            'date' => '2026-06-15',
            'time' => '12:30',
        ])->assertRedirect();

        self::assertSame(1, MealMemory::query()->count(), 'the same shape, not a new one');
        self::assertSame(5, $memory->refresh()->times_logged);
    }

    public function test_a_retried_tap_does_not_log_the_meal_twice(): void
    {
        $memory = $this->remember('Porridge', ['Porridge']);

        $payload = ['uuid' => (string) Str::uuid(), 'date' => '2026-06-15', 'time' => '08:00'];

        $this->post("/meal-memory/{$memory->id}/log", $payload)->assertRedirect();
        $this->post("/meal-memory/{$memory->id}/log", $payload)->assertRedirect();

        self::assertSame(1, Meal::query()->count());

        // The duplicate returns the existing meal and saves nothing, so the
        // observer never fires and the count moves once.
        self::assertSame(4, $memory->refresh()->times_logged);
    }

    public function test_the_daily_view_ships_the_ranked_picker(): void
    {
        $this->remember('Chicken breast, White rice', ['Chicken breast', 'White rice'], timesLogged: 6, daysAgo: 1);

        $this->get('/?date=2026-06-15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('frequentMeals', 1)
                ->where('frequentMeals.0.label', 'Chicken breast, White rice')
                ->where('frequentMeals.0.timesLogged', 6)
                ->where('frequentMeals.0.itemCount', 2)
                ->etc()
            );
    }

    /**
     * The card shows the quadrature band, the same arithmetic the day uses, so
     * the number on the button is the number the day will move by.
     */
    public function test_the_picker_card_carries_a_kcal_band(): void
    {
        $this->remember('Chicken breast, White rice', ['Chicken breast', 'White rice']);

        $card = $this->getJson('/api/meal-memory')->json('memories.0');

        self::assertSame(2, $card['itemCount']);
        self::assertGreaterThan($card['kcal']['min'], $card['kcal']['max']);
        self::assertGreaterThan(0, $card['kcal']['mid']);
    }

    /**
     * A memory row by hand, in the shape the recorder writes.
     *
     * @param  list<string>  $names
     */
    private function remember(string $label, array $names, int $timesLogged = 3, int $daysAgo = 1): MealMemory
    {
        $memory = MealMemory::factory()->forItems($names)->create([
            'canonical_name' => $label,
            'times_logged'   => $timesLogged,
            'last_logged_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);

        foreach ($names as $name) {
            MealMemoryItem::factory()->named($name)->create([
                'meal_memory_id'    => $memory->id,
                'typical_portion_g' => 200,
                'kcal_per_100g_min' => 120,
                'kcal_per_100g_max' => 140,
            ]);
        }

        return $memory;
    }
}
