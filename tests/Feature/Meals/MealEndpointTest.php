<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use App\Models\DailySummary;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Manual meal entry. The idempotency tests are the load-bearing ones: iOS
 * WebKit has no Background Sync API, so offline logging is an in-page queue
 * flushed on foreground, and a retried flush must not produce a second dinner.
 * The client generates the uuid before the row exists; this proves the server
 * honours it.
 */
final class MealEndpointTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    public function test_a_meal_is_created_with_its_items(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', $this->payload($uuid))
            ->assertRedirect('/?date='.self::DATE)
            ->assertSessionHas('success');

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertSame(MealSource::Manual, $meal->source);
        self::assertSame(self::DATE, $meal->local_date->toDateString());
        self::assertSame('13:20', $meal->eaten_at->setTimezone('Europe/Amsterdam')->format('H:i'));
        self::assertCount(2, $meal->items);

        // Typed by a human, so confirmed on arrival: no tap left to wait for.
        $firstItem = $meal->items->first();
        self::assertNotNull($firstItem);
        self::assertNotNull($firstItem->confirmed_at);
    }

    public function test_posting_the_same_uuid_twice_does_not_duplicate_the_meal(): void
    {
        $uuid = (string) Str::uuid();
        $payload = $this->payload($uuid);

        $this->post('/meals', $payload)->assertRedirect();
        $this->post('/meals', $payload)->assertRedirect();

        self::assertSame(1, Meal::query()->count());

        // And the items were not appended a second time either — a retry
        // carries the same payload by definition, so it has nothing to add.
        self::assertSame(2, MealItem::query()->count());
    }

    public function test_a_retry_is_reported_as_a_success_not_an_error(): void
    {
        $payload = $this->payload((string) Str::uuid());

        $this->post('/meals', $payload);

        // The meal IS logged; failing the retry makes a working flush look broken.
        $this->post('/meals', $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Meal was already logged.');
    }

    public function test_a_quick_entry_is_stored_as_a_zero_width_range_on_a_100g_basis(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', [
            'uuid'  => $uuid,
            'date'  => self::DATE,
            'time'  => '08:00',
            'items' => [
                ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => 120, 'protein' => 6.5],
            ],
        ])->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        // Nobody weighs a cappuccino. 100 g is the BASIS, not a weight claim: at
        // 100 g the density IS the absolute, so both entry modes store one shape.
        self::assertSame('100.000', $item->portion_g_min);
        self::assertSame('100.000', $item->portion_g_max);
        self::assertSame('120.000', $item->kcal_per_100g_min);
        self::assertSame('120.000', $item->kcal_per_100g_max);
        self::assertSame('6.500', $item->protein_per_100g_min);

        // Derived absolutes come back out unchanged.
        self::assertSame(['min' => 120.0, 'max' => 120.0], $item->kcalRange());
    }

    public function test_a_per_100g_entry_derives_its_absolutes_from_the_portion(): void
    {
        $this->post('/meals', [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '19:00',
            'items' => [[
                'name'             => 'Chicken breast',
                'basis'            => 'per_100g',
                'grams'            => 150,
                'kcal_per_100g'    => 165,
                'protein_per_100g' => 31,
            ]],
        ])->assertRedirect();

        $item = MealItem::query()->firstOrFail();

        self::assertSame('150.000', $item->portion_g_min);
        self::assertSame('165.000', $item->kcal_per_100g_min);

        // 150 g x 1.65 kcal/g = 247.5.
        self::assertSame(['min' => 247.5, 'max' => 247.5], $item->kcalRange());
    }

    public function test_the_days_summary_is_rebuilt_before_the_redirect_lands(): void
    {
        $this->post('/meals', $this->payload((string) Str::uuid()))->assertRedirect();

        $summary = DailySummary::query()->findOrFail(self::DATE);

        // 240 + 480. The 30-second coalescing delay suits an unattended export,
        // not a page the user is about to look at.
        self::assertSame(720.0, (float) $summary->kcal_in_mid);
        self::assertNotNull($summary->rebuilt_at);
    }

    public function test_a_meal_can_be_edited_and_its_items_replaced(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', $this->payload($uuid));

        $this->put("/meals/{$uuid}", [
            'uuid'      => $uuid,
            'date'      => self::DATE,
            'time'      => '14:00',
            'meal_type' => 'lunch',
            'notes'     => 'second helping',
            'items'     => [
                ['name' => 'Rice', 'basis' => 'per_100g', 'grams' => 200, 'kcal_per_100g' => 130],
            ],
        ])->assertRedirect('/?date='.self::DATE);

        $meal = Meal::query()->where('uuid', $uuid)->firstOrFail();

        self::assertSame('second helping', $meal->notes);
        self::assertSame('14:00', $meal->eaten_at->setTimezone('Europe/Amsterdam')->format('H:i'));

        // Replaced, not merged: items have no client-side identity, and matching
        // by name would silently combine two portions.
        self::assertCount(1, $meal->items()->get());
        self::assertSame(1, MealItem::query()->count());
        self::assertSame(260.0, (float) DailySummary::query()->findOrFail(self::DATE)->kcal_in_mid);
    }

    public function test_moving_a_meal_to_another_day_rebuilds_both_days(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', $this->payload($uuid));

        $this->put("/meals/{$uuid}", [
            ...$this->payload($uuid),
            'date' => '2026-06-17',
        ])->assertRedirect('/?date=2026-06-17');

        self::assertNull(DailySummary::query()->findOrFail(self::DATE)->kcal_in_mid);
        self::assertSame(720.0, (float) DailySummary::query()->findOrFail('2026-06-17')->kcal_in_mid);
    }

    public function test_a_meal_can_be_deleted_and_its_calories_go_with_it(): void
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', $this->payload($uuid));
        $this->delete("/meals/{$uuid}")->assertRedirect('/?date='.self::DATE);

        self::assertSame(0, Meal::query()->count());
        // Cascade, at the database level.
        self::assertSame(0, MealItem::query()->count());
        self::assertNull(DailySummary::query()->findOrFail(self::DATE)->kcal_in_mid);
    }

    public function test_a_previous_meal_can_be_relogged_onto_another_day(): void
    {
        $original = (string) Str::uuid();
        $repeat = (string) Str::uuid();

        $this->post('/meals', $this->payload($original));

        $this->post("/meals/{$original}/repeat", [
            'uuid' => $repeat,
            'date' => '2026-06-16',
            'time' => '12:30',
        ])->assertRedirect('/?date=2026-06-16');

        self::assertSame(2, Meal::query()->count());

        $copy = Meal::query()->where('uuid', $repeat)->firstOrFail();

        self::assertSame('2026-06-16', $copy->local_date->toDateString());
        self::assertCount(2, $copy->items);

        // The re-log is idempotent for the same reason the original was.
        $this->post("/meals/{$original}/repeat", [
            'uuid' => $repeat,
            'date' => '2026-06-16',
            'time' => '12:30',
        ]);

        self::assertSame(2, Meal::query()->count());
    }

    public function test_validation_rejects_a_meal_with_no_items(): void
    {
        $this->from('/')->post('/meals', [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '12:00',
            'items' => [],
        ])->assertSessionHasErrors('items');

        self::assertSame(0, Meal::query()->count());
    }

    public function test_validation_rejects_nonsense(): void
    {
        $this->from('/')->post('/meals', [
            'uuid'  => 'not-a-uuid',
            'date'  => '15-06-2026',
            'time'  => 'lunchtime',
            'items' => [
                // Nameless, plus a density past the 900 kcal/100 g ceiling
                // (pure fat plus a margin).
                ['name' => '', 'basis' => 'absolute'],
                ['name' => 'x', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => 5000],
            ],
        ])->assertSessionHasErrors([
            'uuid',
            'date',
            'time',
            'items.0.name',
            'items.1.kcal_per_100g',
        ]);

        /*
         * Deliberately NOT an error any more: missing kcal. Requiring it left
         * inventing a number as the only way to log a chicken curry. Name and
         * plausibility ceilings still hold — a wrong number is worth preventing,
         * a blank one is not.
         */
        $this->from('/')->post('/meals', [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '12:00',
            'items' => [['name' => 'Chicken curry', 'basis' => 'absolute']],
        ])->assertSessionHasNoErrors();
    }

    public function test_every_meal_route_requires_authentication(): void
    {
        $meal = Meal::factory()->create();

        auth()->logout();

        $this->post('/meals', $this->payload((string) Str::uuid()))->assertRedirect('/login');
        $this->put("/meals/{$meal->uuid}", [])->assertRedirect('/login');
        $this->delete("/meals/{$meal->uuid}")->assertRedirect('/login');
        $this->post("/meals/{$meal->uuid}/repeat", [])->assertRedirect('/login');

        self::assertSame(1, Meal::query()->count());
    }

    /**
     * Two items, 240 + 480 kcal, both zero-width.
     *
     * @return array<string, mixed>
     */
    private function payload(string $uuid): array
    {
        return [
            'uuid'      => $uuid,
            'date'      => self::DATE,
            'time'      => '13:20',
            'meal_type' => 'lunch',
            'notes'     => null,
            'items'     => [
                ['name' => 'Greek yoghurt', 'basis' => 'absolute', 'kcal' => 240, 'protein' => 20],
                ['name' => 'Granola', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => 480],
            ],
        ];
    }
}
