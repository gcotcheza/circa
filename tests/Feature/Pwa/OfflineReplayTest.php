<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\User;
use App\Models\MealItem;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Jobs\EstimateMealNutrition;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeVisionAnalyzer;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The server-side contract the offline queue depends on.
 *
 * The queue itself is JavaScript in the page and cannot be exercised here; the
 * shape of the responses it reads can be, and is the half that would lose
 * somebody's dinner. The dangerous one is the signed-out replay: `response.ok`
 * on a 302 to /login that fetch() followed is indistinguishable from a
 * successful write, so the queue would delete the action and the meal would be
 * gone with nothing on screen to say so.
 *
 * These endpoints were made idempotent in steps 3 and 5 — the client-generated
 * uuid and the idempotency key — precisely so a queue could exist; this pins
 * that from the queue's point of view rather than the form's.
 */
final class OfflineReplayTest extends TestCase
{
    use RefreshDatabase;

    /** The exact headers resources/js/lib/queue.js sends. */
    private const QUEUE_HEADERS = [
        'Accept'           => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    public function test_replaying_a_meal_create_twice_logs_one_meal(): void
    {
        $this->actingAs(User::factory()->create());

        $uuid = (string) Str::uuid();

        $this->withHeaders(self::QUEUE_HEADERS)->post('/meals', $this->mealPayload($uuid))
            ->assertRedirect();

        // The double flush: two tabs, or `online` mid-flush. It costs a request, nothing else.
        $this->withHeaders(self::QUEUE_HEADERS)->post('/meals', $this->mealPayload($uuid))
            ->assertRedirect();

        $this->assertSame(1, Meal::query()->where('uuid', $uuid)->count());
        $this->assertSame(1, Meal::query()->count());
    }

    public function test_a_signed_out_replay_is_a_401_the_queue_can_recognise(): void
    {
        /*
         * Without JSON rendering this is a 302 to /login that fetch() follows to
         * a 200, read as success, and the meal is deleted from the store unsent.
         */
        $this->withHeaders(self::QUEUE_HEADERS)
            ->post('/meals', $this->mealPayload((string) Str::uuid()))
            ->assertUnauthorized();

        $this->assertSame(0, Meal::query()->count());
    }

    public function test_a_rejected_replay_is_a_422_carrying_the_reason(): void
    {
        $this->actingAs(User::factory()->create());

        $payload = $this->mealPayload((string) Str::uuid());
        $payload['items'][0]['kcal'] = -5;

        $this->withHeaders(self::QUEUE_HEADERS)->post('/meals', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        // Left for the user to fix or discard — never silently retried forever.
        $this->assertSame(0, Meal::query()->count());
    }

    public function test_a_replayed_quick_add_is_idempotent_on_its_uuid(): void
    {
        $this->actingAs(User::factory()->create());

        // The factory's default status is already `confirmed`.
        $source = Meal::factory()->create();
        MealItem::factory()->for($source)->create(['name' => 'Porridge']);

        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'date' => '2026-06-15', 'time' => '08:30'];

        $this->withHeaders(self::QUEUE_HEADERS)->post("/meals/{$source->uuid}/repeat", $body)->assertRedirect();
        $this->withHeaders(self::QUEUE_HEADERS)->post("/meals/{$source->uuid}/repeat", $body)->assertRedirect();

        $this->assertSame(1, Meal::query()->where('uuid', $uuid)->count());
    }

    public function test_an_ordinary_inertia_visit_still_gets_the_login_redirect(): void
    {
        /*
         * Counterweight to the 401: broader JSON rendering must not change what a
         * browser sees, nor an Inertia visit, which sends
         * `Accept: text/html, application/xhtml+xml`.
         */
        $this->withHeaders([
            'Accept'           => 'text/html, application/xhtml+xml',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia'        => 'true',
        ])->post('/meals', $this->mealPayload((string) Str::uuid()))
            ->assertRedirect(route('login'));

        $this->post('/meals', $this->mealPayload((string) Str::uuid()))
            ->assertRedirect(route('login'));
    }

    /**
     * "Save & estimate" is a queued write like any other, idempotent on BOTH keys
     * while queued: the uuid must not produce a second meal, the idempotency key
     * must not buy a second Anthropic call. A replayed upload is what
     * `vision_requests.idempotency_key` was added for; this is the text path's.
     */
    public function test_replaying_a_queued_estimate_logs_one_meal_and_buys_one_analysis(): void
    {
        Queue::fake();

        $analyzer = new FakeVisionAnalyzer;
        $this->app->instance(VisionAnalyzer::class, $analyzer);

        $this->actingAs(User::factory()->create());

        $payload = $this->estimatePayload((string) Str::uuid(), (string) Str::uuid());

        // The flush that succeeded but whose response never arrived...
        $this->withHeaders(self::QUEUE_HEADERS)
            ->postJson('/api/meals/estimate', $payload)
            ->assertStatus(202);

        // ...and the retry that follows it.
        $this->withHeaders(self::QUEUE_HEADERS)
            ->postJson('/api/meals/estimate', $payload)
            ->assertStatus(200)
            ->assertJsonPath('meal.status', 'analyzing');

        self::assertSame(1, Meal::query()->count());
        self::assertSame(1, MealItem::query()->count());
        self::assertSame(1, VisionRequest::query()->count());

        Queue::assertPushed(EstimateMealNutrition::class, 1);
    }

    public function test_a_signed_out_estimate_replay_is_a_401_the_queue_can_recognise(): void
    {
        // Not a 302: fetch() follows it to a 200 of HTML, read as sent, and the
        // queue deletes a meal the server never received.
        $this->withHeaders(self::QUEUE_HEADERS)
            ->postJson('/api/meals/estimate', $this->estimatePayload((string) Str::uuid(), (string) Str::uuid()))
            ->assertStatus(401);

        self::assertSame(0, Meal::query()->count());
    }

    public function test_a_rejected_estimate_replay_is_a_422_carrying_the_reason(): void
    {
        $this->actingAs(User::factory()->create());

        $payload = $this->estimatePayload((string) Str::uuid(), (string) Str::uuid());
        $payload['items'][0]['name'] = '';

        // 422 is permanent to the queue: identical bytes get the identical answer
        // forever, so the action is blocked with the reason rather than looping.
        $this->withHeaders(self::QUEUE_HEADERS)
            ->postJson('/api/meals/estimate', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.name');
    }

    /**
     * A meal with a blank item, as the sheet posts it to the estimate endpoint.
     *
     * @return array<string, mixed>
     */
    private function estimatePayload(string $uuid, string $key): array
    {
        return [
            'uuid'            => $uuid,
            'idempotency_key' => $key,
            'date'            => '2026-06-15',
            'time'            => '19:30',
            'meal_type'       => 'dinner',
            'notes'           => null,
            'items'           => [[
                'name'    => 'Chicken curry',
                'basis'   => 'absolute',
                'kcal'    => null,
                'protein' => null,
                'carbs'   => null,
                'fat'     => null,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mealPayload(string $uuid): array
    {
        return [
            'uuid'      => $uuid,
            'date'      => '2026-06-15',
            'time'      => '12:30',
            'meal_type' => 'lunch',
            'notes'     => null,
            'items'     => [[
                'name'    => 'Queued sandwich',
                'basis'   => 'absolute',
                'kcal'    => 420,
                'protein' => 22,
                'carbs'   => 44,
                'fat'     => 15,
            ]],
        ];
    }
}
