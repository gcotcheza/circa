<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\PasswordUpdateRequest;

/**
 * Change your own password. The only account action this app has.
 *
 * SingleUserSeeder generates a random password and prints it once, which is
 * fine for first boot but leaves no way to change it afterwards short of
 * re-running the seeder with SEED_USER_PASSWORD — a deploy-time chore, not
 * something the owner can do from the phone. This is that missing in-app
 * path, and it's an authenticated CHANGE, never a reset: gated by the
 * `current_password` rule (see PasswordUpdateRequest), so the user must
 * already know the password they're replacing.
 *
 * `Auth::logoutOtherDevices()` re-hashes the CURRENT session's stored
 * password hash — stopping the change from bouncing the request that made
 * it — while invalidating every OTHER session, the right default since
 * someone else knowing the old password is often the reason for the change.
 * THAT CALL USED TO DO NOTHING: it invalidates a per-session copy of the
 * hash, and the only code comparing it against the real one,
 * `Illuminate\Session\Middleware\AuthenticateSession`, was registered in no
 * group, alias or route, so every other session sailed through untouched.
 * It's now appended to the `web` group in bootstrap/app.php; see the
 * comment there.
 *
 * The remember-me cookie has to go too: LoginController signs in with
 * `remember: true` unconditionally, so every device ever logged in holds a
 * ~400-day recaller cookie checked against `remember_token`, not the
 * password — evicting sessions while leaving those alive would change the
 * password without changing who can get in. So the token is cycled here,
 * invalidating every recaller cookie at once (including this device's), and
 * `logoutOtherDevices()` re-issues one to this device against the new
 * token, which is why the phone that made the change is the only survivor.
 *
 * No throttle, deliberately: every other cost-limited route guards money or
 * a guest-reachable secret. This one is behind `auth`, and the secret it
 * checks is the current password of a session that already proved it knows
 * one — nothing here for a rate limiter to defend.
 */
final class PasswordController extends Controller
{
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $password = (string) $request->validated('password');

        $user = $request->user();
        // The route is behind `auth`; the request cannot resolve without one.
        assert($user instanceof User);

        // The model's `hashed` cast bcrypts this on save — hashing it here
        // first would double-hash and lock the account out.
        $user->password = $password;

        // Kill every remember-me cookie ever issued: they carry the OLD
        // token and stop validating once the column changes; without this a
        // 400-day cookie on a device you no longer control walks past the
        // new password.
        $user->setRememberToken(Str::random(60));

        $user->save();

        /*
         * Keep this session, drop every other (see the class docblock).
         * Does two things: re-hashes the password on the guard's user so
         * every other session's stored copy goes stale (logging them out on
         * their next request via AuthenticateSession), and re-issues THIS
         * device's recaller cookie against the just-cycled token. Must run
         * after the save, so the cookie it queues carries the new token and
         * hash. The current session needs no repair of its own —
         * AuthenticateSession re-stores the hash on the way OUT of every
         * request, so this request's own response leaves it signed in.
         */
        Auth::logoutOtherDevices($password);

        return to_route('profile.edit')->with('success', 'Your password has been changed.');
    }
}
