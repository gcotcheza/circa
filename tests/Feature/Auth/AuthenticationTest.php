<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Tests\Support\PayloadBuilder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Session auth for one user, and the routes that deliberately do not exist.
 *
 * The last two tests matter most: putting the UI behind a session must not put
 * the PHONE behind one. Health Auto Export posts unattended every hour with an
 * API key and no cookie jar; were `/api/ingest` to start redirecting to /login,
 * ingestion would stop silently and surface days later as a gap in the history.
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/trends')->assertRedirect('/login');
    }

    public function test_the_login_page_renders(): void
    {
        // An Inertia component, not server-rendered markup, so the assertion is
        // on the component that will mount rather than on text.
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = $this->user();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_sign_anyone_in(): void
    {
        $user = $this->user();

        $this->from('/login')->post('/login', [
            'email'    => $user->email,
            'password' => 'not-the-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $user = $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // One account exists, so this form is the entire password-guessing
        // surface; five a minute makes an online attack pointless.
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_a_user_can_sign_out(): void
    {
        $this->actingAs($this->user())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_there_is_no_registration_or_password_reset(): void
    {
        // SPEC.md non-goals open with "Multi-user/public signup", and a route
        // that does not exist cannot be re-enabled by a careless refactor.
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password')->assertNotFound();
        $this->get('/reset-password')->assertNotFound();
    }

    public function test_the_ingest_endpoint_is_untouched_by_session_auth(): void
    {
        config(['services.ingest.api_key' => 'test-key']);

        $body = PayloadBuilder::make()
            ->quantity('step_count', 'count', PayloadBuilder::at('2026-06-15 09:00:00'), 512)
            ->body();

        // No session, no CSRF token, no cookie — exactly how the phone posts.
        $this->postJson('/api/ingest', $body, ['X-API-Key' => 'test-key'])
            ->assertAccepted();

        // The key is still the gate: auth middleware has not replaced it.
        $this->postJson('/api/ingest', $body)->assertUnauthorized();
    }

    public function test_the_health_endpoint_stays_public(): void
    {
        // An uptime check must reach it, and it exposes counts and timestamps
        // rather than measurements.
        $this->getJson('/api/health')->assertOk();
    }

    private function user(): User
    {
        return User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }
}
