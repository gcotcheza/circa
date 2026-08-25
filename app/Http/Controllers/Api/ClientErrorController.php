<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ClientError;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

/**
 * `POST /api/client-errors` — the phone telling the server that it broke.
 *
 * There's no server-side symptom of a client-side crash: the HTML and
 * bundle were 200s, nothing was logged, and the only witness is a blank
 * screen on a phone with no console attached. So this endpoint takes the
 * few milliseconds' worth of facts the browser has and writes them down —
 * the whole client-side observability story, deliberately the size of a
 * log line.
 *
 * Three rules it's built to obey: it NEVER MAKES THINGS WORSE — the client
 * calls it fire-and-forget and ignores the answer
 * (resources/js/lib/report.js), so this does the least possible work (no
 * queue, no notification, one INSERT). It CANNOT BE A WRITE PRIMITIVE —
 * behind `auth` and `throttle:client-errors` (10/minute), so a crash loop
 * costs ten rows a minute and then nothing. It TRUNCATES RATHER THAN
 * REFUSING — every field is cut to its column length in PHP before the
 * insert, since a 422 on a 40 KB stack trace throws away the only copy of
 * the evidence; only `message` is required, since a report with no message
 * isn't one.
 *
 * Behind auth because an unauthenticated error sink on a public origin is
 * a free, unattributed, write-shaped endpoint — this app has exactly one
 * user, always signed in when the app is on screen, so the cost is a
 * crash before sign-in reporting nothing (401), a known and accepted gap.
 * Lives under `/api/` so an expired session answers 401 JSON rather than a
 * 302 to the login page (see bootstrap/app.php) — a reporter that followed
 * the redirect and read the login HTML as success would silently stop
 * reporting exactly when the app started failing.
 */
final class ClientErrorController extends Controller
{
    /**
     * Column ceilings, mirrored from the migration. A constant rather than
     * inferred, since truncation must happen BEFORE the insert — an
     * out-of-date number would 500 the one path whose job is never to 500.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        'kind'       => 32,
        'message'    => 1000,
        'source'     => 1000,
        'stack'      => 4000,
        'url'        => 2000,
        'user_agent' => 500,
        'build'      => 64,
        'component'  => 64,
        'context'    => 1000,
    ];

    /**
     * The handlers wired up in the front end. Anything else is `error`.
     *
     * `error`, `rejection` and `vue` are the three global handlers in
     * resources/js/lib/report.js. `inertia` is narrower and more useful: a
     * visit that failed inside @inertiajs/core, caught by
     * resources/js/lib/inertia-guard.js while the response is still in
     * scope — those arrive with a `context` and the others largely don't.
     */
    private const KINDS = ['error', 'rejection', 'vue', 'inertia'];

    public function __invoke(Request $request): JsonResponse
    {
        /*
         * Loose on purpose: `message` must be a non-empty string; everything
         * else is best-effort, since the browser genuinely hands over
         * `undefined` for most of it most of the time (an
         * unhandledrejection has no source, line, column or stack).
         * `nullable` precedes every optional type so an explicit null —
         * what JSON.stringify makes of `undefined` — is accepted rather
         * than read as a type failure.
         */
        $data = $request->validate([
            'message'   => ['required', 'string'],
            'kind'      => ['nullable', 'string'],
            'source'    => ['nullable', 'string'],
            'line'      => ['nullable', 'integer'],
            'col'       => ['nullable', 'integer'],
            'stack'     => ['nullable', 'string'],
            'url'       => ['nullable', 'string'],
            'build'     => ['nullable', 'string'],
            'component' => ['nullable', 'string'],

            /*
             * A JSON object as a string, validated as a plain string on
             * purpose: the client already serialised and truncated it to
             * the column, so what arrives may be JSON with its tail cut
             * off. Rejecting that would throw away the only two facts
             * worth having (status code, byte count) to enforce a syntax
             * nothing here parses.
             */
            'context' => ['nullable', 'string'],
        ]);

        $kind = (string) ($data['kind'] ?? 'error');

        ClientError::query()->create([
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'error',

            'message' => $this->cut($data['message'], 'message'),
            'source'  => $this->cut($data['source'] ?? null, 'source'),

            // Cast through int rather than trusting the validator's shape: a
            // line number is a small integer and a bigint here would be a
            // Postgres error, not a report.
            'line' => $this->smallInt($data['line'] ?? null),
            'col'  => $this->smallInt($data['col'] ?? null),

            'stack' => $this->cut($data['stack'] ?? null, 'stack'),
            'url'   => $this->cut($data['url'] ?? null, 'url'),

            /*
             * Read FROM THE HEADER, not the body: the client could send
             * anything, and the header is what actually made the request —
             * the thing worth recording.
             */
            'user_agent' => $this->cut($request->userAgent(), 'user_agent'),

            'build' => $this->cut($data['build'] ?? null, 'build'),

            // Which screen was on show, and facts the message can't carry.
            // See the migration for why `context` is text, not a `json` column.
            'component' => $this->cut($data['component'] ?? null, 'component'),
            'context'   => $this->cut($data['context'] ?? null, 'context'),
        ]);

        // 204: there is nothing to say back, and a body would only be parsed by
        // a client that has already been told to ignore the response.
        return response()->json(null, 204);
    }

    private function cut(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, self::LIMITS[$field]);
    }

    /**
     * A line or column number, or null — anything outside a signed 32-bit
     * range isn't a source position, it's a bug or a probe, not worth an
     * exception.
     */
    private function smallInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $int = (int) $value;

        return ($int >= 0 && $int <= 2_147_483_647) ? $int : null;
    }
}
