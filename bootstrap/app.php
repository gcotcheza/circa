<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Http\Middleware\NoStoreHtmlResponses;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\StripControlCharacters;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Health Auto Export posts here; see routes/api.php. Registered under
        // the default `api` prefix, so the phone's URL is /api/ingest.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        /*
         * The PWA shell (step 8), registered with NO middleware group.
         *
         * `Route::group([], ...)` is the whole point: the manifest, the service
         * worker and the offline page read no session, no CSRF token and no
         * Inertia prop, and a browser revalidates /sw.js on every navigation.
         * Inside the `web` group each of those checks would write a `sessions`
         * row for a visitor who is not one. Global middleware still applies.
         */
        then: function (): void {
            Route::group([], __DIR__.'/../routes/pwa.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Control characters out of every box, ahead of TrimStrings and
         * ConvertEmptyStringsToNull so a box holding nothing else reads as
         * empty. They are invisible junk in this app, and a NUL in the middle
         * of a name was silently storing half of it — trimming only ever
         * touched the ends. See the class.
         */
        $middleware->prepend(StripControlCharacters::class);

        /*
         * TRUSTED PROXIES — a list, no longer `at: '*'`.
         *
         * The request arrives having crossed two proxies: Cloudflare, then the
         * host's nginx, which proxy_passes to 127.0.0.1:3083 — the compose
         * stack's nginx sidecar, which speaks FastCGI to php-fpm. PHP therefore
         * sees a private container IP as REMOTE_ADDR and plain http as the
         * scheme, and without this every generated URL would be http:// and
         * every `secure` cookie would be dropped by the browser. That is what
         * trusting a proxy is FOR here, and it has to keep working.
         *
         * WHY THE `*` WENT. Trusting every proxy also means trusting whatever
         * X-Forwarded-For the request happens to carry, and Symfony then
         * answers `$request->ip()` with the LEFTMOST entry of that header —
         * a value the client wrote. The login throttle is keyed `email|ip`
         * (AppServiceProvider), so a guesser who varied the header per attempt
         * got a fresh five-attempt bucket every time and the limiter counted
         * nothing. The same over-trust let X-Forwarded-Host decide getHost(),
         * i.e. the host in every URL this app generates. The old reasoning —
         * "the port is loopback-only, so nobody can forge these" — was wrong in
         * one specific way: the headers do not have to reach the port from
         * outside, they only have to be COPIED there, and the host vhost was
         * appending the client's X-Forwarded-For to its own.
         *
         * The fix is in two halves and needs both:
         *
         *   1. the host vhost now RESETS the forwarding headers instead of
         *      appending to them: X-Forwarded-For is set to $remote_addr, which
         *      the realip module has already rewritten to the true client from
         *      CF-Connecting-IP. Nothing a client sends survives that hop.
         *   2. this list, which says only a PRIVATE address is allowed to speak
         *      for somebody else. php-fpm is reachable over the compose bridge
         *      and nothing else — the sidecar's FastCGI requests arrive from the
         *      bridge gateway (172.x.0.1) — so RFC1918 covers the real hop.
         *
         * RFC1918 RATHER THAN THE CURRENT /16, deliberately. Compose assigns
         * the bridge subnet from Docker's default pool at network-create time,
         * so the exact range changes on any `docker compose down && up`.
         * Pinning today's value would be a config that silently breaks HTTPS
         * and secure cookies the day the network is recreated; the /12 covers
         * the whole pool and is still not the internet.
         *
         * LOOPBACK IS ABSENT ON PURPOSE. Nothing reaches php-fpm over
         * 127.0.0.1: the published port belongs to the SIDECAR, and the sidecar
         * talks to app:9000 across the bridge. Leaving it out means a request
         * that somehow originates on the host itself cannot dictate its own
         * client IP either.
         */
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ]);

        /*
         * TRUSTED HOSTS. There was no allowlist at all, which is the other half
         * of the same finding: with a trusted proxy in front, `Host` (or
         * `X-Forwarded-Host`) decided what `URL::to()` produced, so a single
         * request could plant an attacker's origin in anything built from it.
         *
         * EACH ENTRY IS A REGEX, AND AN UNANCHORED ONE. Symfony wraps every
         * pattern as `{...}i` and runs preg_match against the host, so the bare
         * string `health.example.com` would also match
         * `health-example.com.attacker.example` — the dots are wildcards and
         * there is no ^ or $. Hence the escaping and the anchors, which is
         * exactly what Laravel's own default pattern does.
         *
         * `subdomains: false` because there are none: this app answers to one
         * production name and one staging name, both single labels under the
         * apex — staging is `health-staging` and not `staging.health` so it
         * stays a single label. Anything else asking
         * for a URL from this app is asking under a name we do not serve, and
         * gets a 400 rather than a poisoned link.
         *
         * The middleware is inert under `local` and under the test runner —
         * Laravel's own guard, so a feature test still reaches the app on
         * `localhost`.
         */
        $middleware->trustHosts(at: [
            '^health\.example\.com$',
            '^health-staging\.example\.com$',
        ], subdomains: false);

        /*
         * Inertia's shared props, on the web group only. The api group has no
         * session and must stay that way.
         *
         * AuthenticateSession GOES FIRST, and until now it went nowhere at all.
         * `PasswordController::update()` has always called
         * `Auth::logoutOtherDevices()`, which re-hashes the stored password so
         * that every OTHER session's copy goes stale — but the only thing that
         * ever LOOKS at that copy is this middleware, and it was registered in
         * no group, under no alias and on no route. So the call did nothing:
         * a session opened on a device you no longer control stayed valid
         * across a password change, which is the one moment a password change
         * exists to survive. Registering it here is what makes that call true.
         *
         * It is safe on the whole group rather than only the authenticated
         * half: its first line returns early when the request has no user, so
         * /login and the guest pages pay a null check and nothing else. Ahead
         * of HandleInertiaRequests on purpose — a session it decides to kill
         * must be dead BEFORE Inertia shares `auth` props built from it.
         *
         * NoStoreHtmlResponses stays last, and the ORDER IS DELIBERATE: it is
         * appended last, so it runs last on the way out and has the final word
         * on the Cache-Control of the document that boots the app. A stored
         * copy of that document outlives the build it names — `vite build`
         * deletes the previous build's files — and a script tag pointing at a
         * deleted file renders a blank white page with no server-side symptom.
         * See the class for the whole argument, including what it costs.
         */
        $middleware->web(append: [
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            NoStoreHtmlResponses::class,
        ]);

        // Guests get the login page, not a 403 or a JSON auth error.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('day'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * WHEN AN EXCEPTION IS JSON RATHER THAN A REDIRECT.
         *
         * `api/*` was step 4's rule and is still here: the barcode, vision and
         * memory endpoints are called with fetch() from a page that must not
         * navigate, and a guest hitting one has to get 401 with a body rather
         * than a 302 that fetch() follows and hands back as the login page's
         * HTML — i.e. as a 200 that looks like success.
         *
         * `expectsJson()` is step 8's addition, and it exists for exactly the
         * same failure one level worse. The offline queue replays the ORDINARY
         * Inertia endpoints (POST /meals, PUT /meals/{uuid}/proposal, …) with
         * fetch. Without this clause a validation failure on one of those comes
         * back as `redirect()->back()->withErrors()` — a 302 the queue follows
         * to a 200 — so a rejected meal would be read as sent, deleted from the
         * queue, and lost, with the error visible nowhere.
         *
         * It does NOT change Inertia's own requests. Inertia sends
         * `Accept: text/html, application/xhtml+xml`, so `wantsJson()` is false
         * and `acceptsAnyContentType()` is false; `expectsJson()` is therefore
         * false for every visit the app itself makes, and a browser navigation
         * is not close. Only a caller that explicitly asks for JSON gets JSON.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
