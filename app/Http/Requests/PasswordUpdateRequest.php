<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The change-password form.
 *
 * The current password is the gate, not the session. The route is already
 * behind `auth`, so there's always a signed-in user here — but that isn't
 * what makes this safe to expose: a stolen or borrowed phone is signed in
 * too. `current_password` is the actual security check — whoever is
 * typing has to know the password they're replacing, which a session
 * cookie doesn't prove. It validates against the default (web) guard's
 * user.
 *
 * It's a change, not a reset. There is deliberately no unauthenticated
 * reset, no email flow and no way in from the login page (SPEC.md;
 * AuthenticationTest asserts /register, /forgot-password and
 * /reset-password all 404). This request doesn't relax that: it can't be
 * reached without knowing the current password, so it's a rotation the
 * owner performs, not a recovery an attacker triggers.
 *
 * The strength floor is twelve characters plus a check against a public
 * breach corpus, still with NO composition policy: a single owner picking
 * their own password from an iPhone keychain doesn't need mixed case and
 * a symbol argued at them, and rules of that shape push people towards
 * shorter passwords they can type. Length and "is it already leaked" are
 * the two that actually predict whether a password survives guessing.
 *
 * `uncompromised()` is the k-anonymity check against Have I Been Pwned:
 * the first five characters of the SHA-1 are sent, the rest compared
 * locally, so the password itself never leaves the box. It costs one
 * outbound HTTPS request on a route used a handful of times in a
 * lifetime, and fails OPEN — Laravel's verifier treats an unreachable API
 * as "not compromised" — so a network problem can't lock the account out
 * of changing its password.
 *
 * `confirmed` is unchanged and still catches the typo that would
 * otherwise lock the account out.
 */
final class PasswordUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password'         => ['required', 'string', 'confirmed', Password::min(12)->uncompromised()],
        ];
    }

    /**
     * Sentences, because that is what appears under the box.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required'         => 'Enter your current password.',
            'current_password.current_password' => 'That is not your current password.',
            'password.required'                 => 'Choose a new password.',
            'password.confirmed'                => 'The new password and its confirmation do not match.',
        ];
    }
}
