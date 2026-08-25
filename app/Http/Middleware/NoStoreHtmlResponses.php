<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The document that boots the app is never stored. Anywhere.
 *
 * `vite build` empties `public/build` on every deploy, so
 * `/build/assets/app-*.js` 404s after one — and since the app renders
 * client-side from an empty `#app`, a stale HTML document pointing at a
 * deleted bundle is a blank white page with no error and no server-side
 * symptom. `php artisan build:retain` keeps old builds on disk so a
 * briefly-stale document still boots; this middleware makes the document
 * itself unstorable so there's nothing stale to boot from.
 *
 * It was already `no-cache, private` by accident — Symfony's
 * ResponseHeaderBag default for any response nobody set a policy on — but
 * a default this load-bearing needs to be stated and impossible to lose
 * when some future controller or package sets its own Cache-Control.
 * `no-store` is stronger than `no-cache` in the way that matters here:
 * `no-cache` still lets a shared cache (Cloudflare sits in front of this
 * app) KEEP a copy and serve it under `stale-if-error`; `no-store` leaves
 * no copy to reach for. The rest of the line is belt and braces for
 * intermediaries that predate `no-store` and for the ones that only
 * understand `max-age`.
 *
 * The cost: Chromium won't bfcache a `no-store` page, so a back gesture is
 * a real navigation, not an instant restore — a deliberate trade, since
 * this app's own rule (top of resources/js/service-worker.js) is that
 * nothing authenticated is ever served from a cache, and a bfcache
 * restore is exactly a frozen DOM showing yesterday's numbers as today's.
 *
 * Applies only to HTML documents and Inertia's JSON. NOT /offline (public,
 * max-age=86400, precached, no data), NOT /build/* or /icons/* (nginx,
 * immutable by construction), and NOT photo thumbnails
 * (`GET /api/meals/{meal}/photos/{photo}/thumb`, cached a year since
 * written once and never rewritten) — an image/jpeg response passes the
 * content-type test below untouched, which is why the test is on content
 * type rather than route.
 */
final class NoStoreHtmlResponses
{
    /**
     * The four directives, in the order a human reads them: don't keep it,
     * don't reuse it, don't consider it fresh for any length of time, and
     * it belongs to one person.
     */
    public const POLICY = 'no-store, no-cache, must-revalidate, max-age=0, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isAppDocument($response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::POLICY);

        // For the HTTP/1.0 proxies that do not exist any more and the corporate
        // middlebox that does. One header, no downside.
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * An HTML document, or the JSON Inertia swaps into one — the Inertia
     * case matters as much as HTML, since a partial visit's props ARE the
     * day's calories, and a cached one is the stale-numbers bug in its
     * purest form.
     */
    private function isAppDocument(Response $response): bool
    {
        if ($response->headers->has('X-Inertia')) {
            return true;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
