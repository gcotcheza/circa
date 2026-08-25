<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Session login — the whole auth surface. No registration, password
 * reset, email verification, or "remember me" checkbox as a second
 * session policy: this app has exactly one user (SPEC.md non-goals:
 * "Multi-user/public signup"), so every route that doesn't exist can't be
 * misconfigured. The user is created by `db:seed --class=SingleUserSeeder`,
 * which prints the generated password once and stores it nowhere.
 * `POST /api/ingest` is unaffected: api routes are their own group with no
 * session/CSRF, authenticating via the `X-API-Key` shared secret instead,
 * since the phone posts unattended and can't hold a session.
 */
final class LoginController extends Controller
{
    /**
     * Bcrypt of a random string that was thrown away, at the same cost factor
     * the app hashes with. See equaliseUnknownEmailTiming() below.
     */
    private const DUMMY_HASH = '$2y$12$3dI1ta72HQ6lP8wpM5mkBezswDRjq4fl6dsun6wiubo8Nxi.X2P0.';

    /**
     * Named, not inline, so App\Services\Validation\RuleExporter can mirror
     * them into the sign-in boxes. Not a FormRequest: that would change where
     * a refused login lands, for two lines' worth of rules.
     *
     * @var array<string, list<string>>
     */
    public const RULES = [
        'email'    => ['required', 'string', 'email'],
        'password' => ['required', 'string'],
    ];

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(self::RULES);

        // `remember` is on by default and not user-selectable: this is a
        // home-screen PWA on one phone, and being logged out every two hours is
        // how a food log stops being used.
        if (! Auth::attempt($credentials, remember: true)) {
            $this->equaliseUnknownEmailTiming((string) $credentials['email'], (string) $credentials['password']);

            throw ValidationException::withMessages([
                // One message for both wrong-email and wrong-password: with a
                // single account, "no such user" would confirm the address.
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('day'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Make a wrong email cost the same as a wrong password. The message is
     * already identical for both, but the CLOCK was not: `Auth::attempt()`
     * only reaches bcrypt when it found a user, and bcrypt at cost 12 is a
     * couple hundred milliseconds against a failed index lookup's fraction
     * of one — a gap measurable over a handful of requests that answers
     * exactly what the shared message refuses to: is this the account's
     * address? On a single-user app that's the whole enumeration surface.
     *
     * So the unknown-email path pays for a bcrypt too, against a hash of a
     * value nobody knows. The constant authenticates nothing (no row
     * carries it, it's a hash not a password), and its embedded cost of 12
     * is the app's own default (no config/hashing.php), making the two
     * paths comparable rather than merely both slow.
     *
     * Deliberately NOT asserted by a test: a timing assertion on a shared
     * VPS measures the neighbours, and a flaky test teaches people to
     * re-run the suite until it passes.
     */
    private function equaliseUnknownEmailTiming(string $email, string $password): void
    {
        if (User::where('email', $email)->exists()) {
            // The address exists, so Auth::attempt already spent a real bcrypt
            // rejecting the password. Spending a second one here would just
            // move the tell to the other side.
            return;
        }

        Hash::check($password, self::DUMMY_HASH);
    }
}
