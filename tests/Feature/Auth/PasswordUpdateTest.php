<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Session\Store;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Changing your own password: the one account action this app has.
 *
 * ---------------------------------------------------------------------------
 * THE CLAIMS WORTH TESTING
 *
 *   THE CURRENT PASSWORD IS THE GATE — a session cookie is not, since a
 *   borrowed, already-signed-in phone has one; a refusal leaves the old password
 *   working.
 *
 *   A CORRECT CHANGE IS ASSERTED THROUGH THE REAL LOGIN ROUTE, not the column.
 *
 *   THE SESSION SURVIVES: being logged out of a food log is how it stops being
 *   used.
 *
 *   AND EVERY OTHER SESSION DOES NOT. Silently false until now —
 *   `Auth::logoutOtherDevices()` was called, but the middleware that reads what
 *   it writes was registered nowhere, so a session on a device you no longer
 *   control survived. Two real sessions, because one shows nothing.
 *
 *   IT IS STILL NOT A RESET: behind `auth`, guest redirected, JSON caller 401
 *   rather than a followed redirect reading as success.
 * ---------------------------------------------------------------------------
 */
final class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'the-current-password';

    private const NEW = 'a-brand-new-passphrase';

    /**
     * A Have I Been Pwned range response matching neither password;
     * `uncompromised()` sends five SHA-1 chars and compares the rest locally.
     */
    private const HIBP_NO_MATCH = "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2";

    /** What the faked range endpoint answers. One test rewrites it. */
    private string $hibpRange = self::HIBP_NO_MATCH;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `uncompromised()` makes a legal-length password reach
         * api.pwnedpasswords.com. Faked, because a suite that talks to a third
         * party fails during somebody else's outage; `preventStrayRequests`
         * catches a future rule that starts calling something ELSE.
         *
         * The body comes from a property, not a closure: `Http::fake()` MERGES
         * stubs and the first match wins, so a second `fake()` would sit behind
         * this one, unreached.
         */
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->hibpRange)]);
    }

    public function test_a_guest_cannot_reach_the_route(): void
    {
        $this->put('/profile/password', [])->assertRedirect(route('login'));
        $this->putJson('/profile/password', [])->assertUnauthorized();
    }

    public function test_the_wrong_current_password_is_refused_and_nothing_changes(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password'      => 'not-my-password',
                'password'              => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('current_password');

        $user->refresh();

        self::assertTrue(Hash::check(self::OLD, $user->password));
        self::assertFalse(Hash::check(self::NEW, $user->password));
    }

    public function test_a_correct_change_updates_the_password_and_keeps_this_session(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password'      => self::OLD,
                'password'              => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $user->refresh();

        self::assertTrue(Hash::check(self::NEW, $user->password));
        self::assertFalse(Hash::check(self::OLD, $user->password));

        // The phone it was changed from is still signed in.
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_new_password_authenticates_afterwards_and_the_old_one_does_not(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put('/profile/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertSessionHasNoErrors();

        // Through the real login flow, not the column.
        $this->post('/logout');

        $this->from('/login')->post('/login', [
            'email'    => $user->email,
            'password' => self::OLD,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => self::NEW,
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_confirmation_mismatch_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password'      => self::OLD,
                'password'              => self::NEW,
                'password_confirmation' => 'something-else-entirely',
            ])
            ->assertSessionHasErrors('password');

        self::assertTrue(Hash::check(self::OLD, $user->refresh()->password));
    }

    /** Twelve, asserted from both sides: a floor nobody tests at the edge quietly becomes eight again. */
    public function test_a_new_password_under_the_length_floor_is_refused(): void
    {
        $user = $this->user();

        foreach (['short', 'elevenchars'] as $tooShort) {
            $this->actingAs($user)
                ->from('/profile')
                ->put('/profile/password', [
                    'current_password'      => self::OLD,
                    'password'              => $tooShort,
                    'password_confirmation' => $tooShort,
                ])
                ->assertSessionHasErrors('password');

            self::assertTrue(Hash::check(self::OLD, $user->refresh()->password));
        }

        // Exactly twelve is allowed: a minimum, not a preference.
        $atTheFloor = 'twelvechars!';

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password'      => self::OLD,
                'password'              => $atTheFloor,
                'password_confirmation' => $atTheFloor,
            ])
            ->assertSessionHasNoErrors();

        self::assertTrue(Hash::check($atTheFloor, $user->refresh()->password));
    }

    /** A long password already in a public breach corpus is not strong, and length cannot tell. */
    public function test_a_password_that_has_already_leaked_is_refused(): void
    {
        $leaked = 'trustno1trustno1';

        // What HIBP answers for this password: the range endpoint returns
        // `SUFFIX:COUNT` lines. Built from the value, not pasted, so the fixture
        // cannot drift from the password it describes.
        $this->hibpRange = substr(strtoupper(sha1($leaked)), 5).':84271';

        $user = $this->user();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password'      => self::OLD,
                'password'              => $leaked,
                'password_confirmation' => $leaked,
            ])
            ->assertSessionHasErrors('password');

        self::assertTrue(Hash::check(self::OLD, $user->refresh()->password));
    }

    /**
     * `Auth::logoutOtherDevices()` was called and did nothing:
     * `Illuminate\Session\Middleware\AuthenticateSession`, the only code that
     * reads what it writes, was registered in no group and on no route. Two REAL
     * sessions here, because the claim is about what a second device sees on its
     * NEXT request; see signInAsANewBrowser().
     */
    public function test_changing_the_password_ends_every_other_session_but_not_this_one(): void
    {
        $user = $this->user();
        $cookie = (string) config('session.cookie');
        $auth = $this->app->make(AuthManager::class);

        $otherDevice = $this->signInAsANewBrowser($user, $cookie);
        $thisDevice = $this->signInAsANewBrowser($user, $cookie);

        self::assertNotSame($otherDevice, $thisDevice, 'the two devices ended up sharing one session');

        $this->withCookie($cookie, $thisDevice)
            ->put('/profile/password', [
                'current_password'      => self::OLD,
                'password'              => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertSessionHasNoErrors();

        // This device is still signed in on its next request.
        $auth->forgetGuards();
        $this->withCookie($cookie, $thisDevice)->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user);

        // The other is not, the moment it asks for anything.
        $auth->forgetGuards();
        $this->withCookie($cookie, $otherDevice)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The recaller cookie is a second way in: LoginController signs in with
     * `remember: true` unconditionally, so every device that has logged in holds
     * one for roughly four hundred days. Checked against `remember_token`, not
     * the password — evicting sessions alone would change the password without
     * changing who can get in.
     */
    public function test_the_remember_token_is_cycled_so_old_recaller_cookies_die(): void
    {
        $user = $this->user();
        $before = $user->getRememberToken();

        self::assertNotEmpty($before, 'the fixture has no remember token to cycle');

        $this->actingAs($user)->put('/profile/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertSessionHasNoErrors();

        self::assertNotSame($before, $user->refresh()->getRememberToken());
    }

    public function test_a_missing_current_password_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile/password', [
                'password'              => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertSessionHasErrors('current_password');

        self::assertTrue(Hash::check(self::OLD, $user->refresh()->password));
    }

    /**
     * Sign in as a browser that has never been here; return its session id.
     *
     * Two things must be undone first, both silent:
     *
     *   `withCookie` is STICKY within a test: the previous device's cookie would
     *   still be attached, so the sign-in would be already authenticated, `guest`
     *   would bounce it to /day (which still satisfies assertRedirect('/')) and
     *   the second session would never exist.
     *
     *   `forgetGuards` drops the guard's cached user, which otherwise survives
     *   the last request in the process and lets a session prove itself with
     *   somebody else's memory.
     *
     * The page load is not decoration: the login POST is still a GUEST request
     * when AuthenticateSession runs, so this first authenticated request writes
     * the password hash into the session — one that never made a hash adopts
     * whatever it finds later.
     */
    private function signInAsANewBrowser(User $user, string $cookie): string
    {
        $this->app->make(AuthManager::class)->forgetGuards();
        $this->withCookie($cookie, Str::random(40));

        $this->post('/login', ['email' => $user->email, 'password' => self::OLD])->assertRedirect('/');

        $id = $this->app->make(Store::class)->getId();

        $this->withCookie($cookie, $id)->get('/profile')->assertOk();

        return $id;
    }

    private function user(): User
    {
        return User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make(self::OLD),
        ]);
    }
}
