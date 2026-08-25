<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Support\Str;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * C0 control characters, out of every box, before anything reads one.
 *
 * They are invisible junk in this app — names, notes and numbers typed on a
 * phone — and one of them is LOSSY: a NUL in the middle of an item name
 * reached the database and came back as everything before it, so
 * "Chicken\x00 curry" was stored, without an error anywhere, as "Chicken".
 * TrimStrings is no help there — it trims the ends, and what does the damage
 * sits in the middle.
 *
 * IT ALSO CLOSES A CRASH THIS APP SHOULD NOT DEPEND ON LUCK TO AVOID.
 * `is_numeric("\x0C5")` is true while PHP's `trim()` leaves the form feed in
 * place, so Laravel hands BigNumber an unreadable string and `numeric` throws
 * a NumberFormatException instead of refusing anything — worth reporting
 * upstream, and the reason CaseGenerator excludes the value by name. No
 * request reaches it today, but only because TrimStrings trims through a `/u`
 * regex whose `\s` happens to match a form feed, and that regex falls back to
 * PHP's `trim()`, which does not, on any string that is not valid UTF-8.
 * Stripping the character is the version of that guarantee this app owns.
 *
 * FIRST IN THE GLOBAL STACK, which is what makes the pair behind it correct:
 * TrimStrings then removes whitespace the stripping exposed, and
 * ConvertEmptyStringsToNull turns a box that held nothing else into null — so
 * a box holding one form feed is exactly as empty as a box holding nothing.
 *
 * Tab, newline and carriage return stay: a note is a textarea, and its line
 * breaks are the user's. Secrets are skipped for the reason TrimStrings skips
 * them — a password is whatever was typed, and editing one silently changes
 * what it unlocks.
 */
final class StripControlCharacters extends TransformsRequest
{
    /**
     * `\t`, `\n` and `\r` cut out of the C0 range; matched byte-wise on
     * purpose, since no continuation byte of a UTF-8 sequence can land here
     * and the `u` flag would refuse the malformed input this is here to clean.
     */
    private const CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /** @var list<string> */
    private const EXCEPT = ['current_password', 'password', 'password_confirmation'];

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value) || Str::is(self::EXCEPT, $key)) {
            return $value;
        }

        $stripped = preg_replace(self::CONTROL_CHARACTERS, '', $value);

        // Null is a PCRE failure a bare character class cannot produce; keep
        // the value rather than blank a box on an impossibility.
        return is_string($stripped) ? $stripped : $value;
    }
}
