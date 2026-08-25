<?php

declare(strict_types=1);

namespace Tests\Feature\Meals;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use Illuminate\Http\Request;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * The confirm, as the phone actually sends it.
 *
 * A test that writes its own request cannot catch the client drifting, so this
 * file pins both sides: the verb and URI are read out of the component source,
 * the response must be a 303 (a 302 makes fetch re-issue the PUT against the
 * GET-only day route — the 405 seen in production while 451 tests were green),
 * the wrong verb must 405, and `PUT /` must stay a 405 rather than be "fixed"
 * by teaching the day route to accept writes.
 *
 * See docs/rationale-frontend.md § "451 green tests and a 405 in production"
 */
final class ConfirmRequestShapeTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const SHEET = 'resources/js/Components/ProposalReview.vue';

    private const DATE = '2026-08-07';

    public function test_the_review_sheet_sends_the_verb_and_uri_the_router_registers(): void
    {
        $action = $this->queuedConfirm();

        self::assertSame('meal.confirm', $action['kind']);
        self::assertSame('PUT', $action['method']);

        // The template literal with the uuid substituted: the exact path the
        // component builds at runtime.
        $uri = str_replace('${props.meal.uuid}', 'e1a3c0de-0000-4000-8000-000000000000', $action['url']);

        self::assertSame('/meals/e1a3c0de-0000-4000-8000-000000000000/proposal', $uri);

        $route = Route::getRoutes()->match(Request::create($uri, $action['method']));

        self::assertSame('meals.proposal.update', $route->getName());
    }

    public function test_the_confirm_the_client_sends_answers_303_not_302(): void
    {
        $meal = $this->proposal();

        $response = $this->withHeaders([
            // Exactly what resources/js/lib/queue.js sends: JSON, XHR, no
            // X-Inertia — a replayed write is byte-identical to the original.
            'Accept'           => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->put('/meals/'.$meal->uuid.'/proposal', $this->confirmation());

        // 303 is the whole fix: a 302 says nothing about the method, so fetch
        // re-issues the PUT against the day route.
        $response->assertStatus(303);
        $response->assertRedirect('/?date='.self::DATE);

        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);

        // And the target answers a GET, which is what a 303 makes the follow-up.
        $this->get('/?date='.self::DATE)->assertOk();
    }

    /**
     * The 422 the user hit on a real dinner, as the phone actually sent it: a
     * half-seen drink renamed to the alcohol-free beer it was, with the four
     * numbers they did not know cleared. Confirm answered "The server would not
     * accept this." — `lib/queue.js`'s 422 branch — blocking the whole meal.
     *
     * An empty `<input type="number">` posts `""`; `null` is the other
     * spelling, from `blankItem()` behind "+ Add an item vision missed", and
     * both have to mean the same thing.
     */
    public function test_an_item_with_the_numbers_left_blank_is_confirmable(): void
    {
        $meal = $this->proposal();

        $payload = $this->confirmation();

        // What the sheet posts for a cleared row: the typed name, the model's
        // confidence, empty strings.
        $payload['items'][] = [
            'name'          => 'Hertog Jan alcohol free beer bottle',
            'confidence'    => 'low',
            'portion_g_min' => '',
            'portion_g_max' => '',
            'kcal_min'      => '',
            'kcal_max'      => '',
            'protein_g_min' => '',
            'protein_g_max' => '',
            'carbs_g_min'   => '',
            'carbs_g_max'   => '',
            'fat_g_min'     => '',
            'fat_g_max'     => '',
        ];

        // And the same row as "+ Add an item vision missed" builds it: nulls.
        $payload['items'][] = [
            'name'          => 'Something I forgot',
            'portion_g_min' => null,
            'portion_g_max' => null,
            'kcal_min'      => null,
            'kcal_max'      => null,
            'protein_g_min' => null,
            'protein_g_max' => null,
            'carbs_g_min'   => null,
            'carbs_g_max'   => null,
            'fat_g_min'     => null,
            'fat_g_max'     => null,
        ];

        $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->put('/meals/'.$meal->uuid.'/proposal', $payload)
            ->assertStatus(303);

        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);

        $blank = $meal->items()->where('name', 'Hertog Jan alcohol free beer bottle')->sole();

        // 100 g at zero density: the SAME row the manual sheet writes for a
        // name with no numbers, so the paths cannot drift.
        self::assertSame('100.000', $blank->portion_g_min);
        self::assertSame('100.000', $blank->portion_g_max);
        self::assertSame(0.0, $blank->kcalRange()['max']);
        self::assertNotNull($blank->confirmed_at);

        // It counts for nothing, and the rest of the meal is not held hostage.
        self::assertSame(0.0, $meal->items()->where('name', 'Something I forgot')->sole()->kcalRange()['max']);
        self::assertSame(3, $meal->items()->count());
    }

    /**
     * One end filled, the other cleared, is a zero-width claim — not a range
     * against a default the user never typed, and not a `gte` failure.
     */
    public function test_half_a_range_mirrors_rather_than_inverting(): void
    {
        $meal = $this->proposal();

        $payload = $this->confirmation();
        $payload['items'][0]['portion_g_max'] = '';
        $payload['items'][0]['kcal_min'] = '';

        $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->put('/meals/'.$meal->uuid.'/proposal', $payload)
            ->assertStatus(303);

        $item = $meal->items()->sole();

        // "It is 120 g" — mirrored, not widened to 120–100 or 120–10000.
        self::assertSame('120.000', $item->portion_g_min);
        self::assertSame('120.000', $item->portion_g_max);

        // "It is at most 260 kcal" — mirrored the other way.
        self::assertEqualsWithDelta(260.0, $item->kcalRange()['min'], 0.5);
        self::assertEqualsWithDelta(260.0, $item->kcalRange()['max'], 0.5);
    }

    public function test_the_confirm_uri_refuses_the_wrong_verb(): void
    {
        $meal = $this->proposal();

        // Drift the other way: the component starts POSTing, or the route
        // quietly gains a second method.
        $this->post('/meals/'.$meal->uuid.'/proposal', $this->confirmation())
            ->assertStatus(405);

        $this->patch('/meals/'.$meal->uuid.'/proposal', $this->confirmation())
            ->assertStatus(405);

        self::assertSame(MealStatus::Proposed, $meal->refresh()->status);
    }

    public function test_the_day_route_does_not_accept_the_redirected_write(): void
    {
        /*
         * The 405 the user saw, as a fact about the router — deliberately not
         * to be fixed by widening the day route: a PUT arriving at a page means
         * an upstream redirect failed to say "now GET this", and a 200 would
         * turn a loud bug into a silent one.
         */
        $this->expectException(MethodNotAllowedHttpException::class);

        $this->withoutExceptionHandling()->put('/?date='.self::DATE);
    }

    /**
     * The queued action the sheet's `confirm()` builds, read out of the source.
     *
     * @return array{kind: string, url: string, method: string}
     */
    private function queuedConfirm(): array
    {
        $source = (string) file_get_contents(base_path(self::SHEET));

        self::assertMatchesRegularExpression('/kind:\s*KIND\.mealConfirm/', $source, 'The review sheet no longer queues a confirm.');

        if (preg_match('/kind:\s*KIND\.(\w+),\s*url:\s*`([^`]+)`,\s*method:\s*\'(\w+)\'/', $source, $matches) !== 1) {
            self::fail('Could not read the confirm action out of '.self::SHEET);
        }
        self::assertCount(4, $matches);

        // KIND.mealConfirm's value, kept in one place rather than retyped here.
        $kinds = (string) file_get_contents(base_path('resources/js/lib/queue.js'));

        if (preg_match('/'.$matches[1].':\s*\'([^\']+)\'/', $kinds, $kind) !== 1) {
            self::fail('KIND.'.$matches[1].' is not defined in lib/queue.js');
        }
        self::assertCount(2, $kind);

        return ['kind' => $kind[1], 'url' => $matches[2], 'method' => $matches[3]];
    }

    private function proposal(): Meal
    {
        $meal = Meal::factory()->create([
            'status'   => MealStatus::Proposed,
            'eaten_at' => self::DATE.' 11:20:00',
        ]);

        $meal->items()->create([
            'name'                 => 'White rice',
            'slug'                 => 'white-rice',
            'portion_g_min'        => 120,
            'portion_g_max'        => 200,
            'kcal_per_100g_min'    => 130,
            'kcal_per_100g_max'    => 130,
            'protein_per_100g_min' => 2.7,
            'protein_per_100g_max' => 2.7,
            'carbs_per_100g_min'   => 28,
            'carbs_per_100g_max'   => 28,
            'fat_per_100g_min'     => 0.3,
            'fat_per_100g_max'     => 0.3,
            'confirmed_at'         => null,
        ]);

        return $meal;
    }

    /** @return array<string, mixed> */
    private function confirmation(): array
    {
        return [
            'date'      => self::DATE,
            'time'      => '11:20',
            'meal_type' => 'lunch',
            'notes'     => null,
            'items'     => [[
                'name'          => 'White rice',
                'portion_g_min' => 120,
                'portion_g_max' => 200,
                'kcal_min'      => 156,
                'kcal_max'      => 260,
                'protein_g_min' => 3.24,
                'protein_g_max' => 5.4,
                'carbs_g_min'   => 33.6,
                'carbs_g_max'   => 56,
                'fat_g_min'     => 0.36,
                'fat_g_max'     => 0.6,
            ]],
        ];
    }
}
