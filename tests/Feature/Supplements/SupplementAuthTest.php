<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\User;
use App\Models\Supplement;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Every new route, from a guest.
 *
 * The split is not cosmetic: an `/api/*` route must answer a guest with 401
 * JSON, because `fetch()` would follow a 302 to the login page and hand the 200
 * back as though the write had landed — which for a queued tick deletes the
 * action from IndexedDB unsent. The Inertia routes get the login redirect,
 * because that is what an Inertia visit is.
 */
final class SupplementAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_json_routes_answer_a_guest_with_401(): void
    {
        $supplement = Supplement::factory()->create();

        $this->postJson('/api/supplements/label', [])->assertUnauthorized();
        $this->getJson('/api/supplements/label/'.Str::uuid())->assertUnauthorized();
        $this->getJson('/api/supplements/'.$supplement->id.'/photo')->assertUnauthorized();
        $this->putJson('/api/supplements/'.$supplement->id.'/intake', [])->assertUnauthorized();
    }

    public function test_the_inertia_routes_send_a_guest_to_the_login_page(): void
    {
        $supplement = Supplement::factory()->create();

        $this->get('/supplements')->assertRedirect(route('login'));
        $this->post('/supplements', [])->assertRedirect(route('login'));
        $this->put('/supplements/'.$supplement->id, [])->assertRedirect(route('login'));
        $this->delete('/supplements/'.$supplement->id)->assertRedirect(route('login'));
    }

    /**
     * An explicit JSON caller gets 401 too: the offline queue replays with
     * `Accept: application/json`, and a followed redirect would read as success.
     */
    public function test_a_json_caller_on_an_inertia_route_still_gets_401(): void
    {
        $supplement = Supplement::factory()->create();

        $this->postJson('/supplements', [])->assertUnauthorized();
        $this->putJson('/supplements/'.$supplement->id, [])->assertUnauthorized();
        $this->deleteJson('/supplements/'.$supplement->id)->assertUnauthorized();
    }

    public function test_a_signed_in_user_reaches_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create());

        Supplement::factory()->create(['name' => 'Magnesium']);

        $this->get('/supplements')->assertOk()->assertInertia(
            fn ($page) => $page->component('Supplements')->where('supplements.0.name', 'Magnesium')->etc()
        );
    }

    public function test_a_supplement_with_no_photograph_has_nothing_to_serve(): void
    {
        $this->actingAs(User::factory()->create());

        $supplement = Supplement::factory()->bare()->create();

        $this->get('/api/supplements/'.$supplement->id.'/photo')->assertNotFound();
    }
}
