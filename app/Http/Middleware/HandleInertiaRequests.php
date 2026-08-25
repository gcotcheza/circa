<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Inertia\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Props every page gets.
 *
 * Kept deliberately small. Inertia ships shared props with EVERY response,
 * including the partial ones a swipe between days fires, so anything expensive
 * put here is paid for on every interaction. The user is two strings; the flash
 * message is how a redirect-after-POST says what happened.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Why a redirect after `PUT` must be 303 for every caller, not just Inertia.
     *
     * A 302 doesn't tell the client to change method — per the Fetch standard,
     * browsers downgrade only a redirected POST to GET, so a redirected PUT
     * re-issues as a PUT. Our write endpoints redirect to the day (`GET /`),
     * registered GET-only, so that second request 405s.
     *
     * Inertia's middleware already rewrites 302 to 303 for PUT/PATCH/DELETE,
     * but only when the request carries `X-Inertia`. The offline queue
     * (`resources/js/lib/queue.js`) replays these endpoints with a hand-rolled
     * `fetch()` and no such header, deliberately — one code path means replay
     * bytes match live ones. That call got the bare 302, followed it as a PUT,
     * and the user saw
     *
     *     "The server refused this (405)."
     *
     * on Confirm, while the confirm had already succeeded — a red banner over
     * a completed write.
     *
     * 303 says "your write is done, now GET this" — correct for every client,
     * so applied to all of them, not just the ones that announce themselves.
     * The parent runs first, so an Inertia request is already converted and
     * this is a no-op on it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        if ($response->getStatusCode() === 302 && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            $response->setStatusCode(303);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'appName' => config('app.name'),

            'auth' => [
                // Single user — presence + a name to greet, not an authorisation surface.
                // No id, no email: nothing on screen needs them.
                'user' => $request->user() === null ? null : [
                    'name' => $request->user()->name,
                ],
            ],

            // Closures are evaluated per request, so an unused flash costs only a session read.
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error'   => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }
}
