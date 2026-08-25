<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">

    {{--
        viewport-fit=cover + the safe-area padding in the layout is what keeps
        the bottom nav clear of the iPhone home indicator once the app is added
        to the Home Screen. maximum-scale is deliberately NOT set: pinch-zoom is
        an accessibility feature, and WebKit ignores the double-tap-zoom
        suppression anyway.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <meta name="robots" content="noindex, nofollow">

    {{-- Matches the two backgrounds in app.css, so the iOS status bar does not
         flash white on a dark-mode launch. The manifest carries only the light
         value — that format has one theme_color and no media form — so THESE
         are what actually make the installed app follow the phone's schedule. --}}
    <meta name="theme-color" content="{{ config('health.pwa.theme_color') }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="{{ config('health.pwa.theme_color_dark') }}" media="(prefers-color-scheme: dark)">

    {{--
        Installability (step 8).

        `apple-mobile-web-app-capable` is the one iOS reads; `mobile-web-app-capable`
        is the standardised spelling every other engine reads, and Chrome logs a
        console warning if it is missing. Both, because "Chrome on iPhone" is a
        WebKit shell that will one day not be.

        STATUS BAR: `default`, not `black-translucent`. Translucent makes the web
        view extend UP under the clock, which needs a top safe-area inset on
        every sticky header in the app — and the header here is a backdrop-blur
        bar that would then be blurring the wallpaper. `default` reserves the
        status bar and paints it with the theme-color above, which is exactly
        the two-theme behaviour already built.
    --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ config('health.pwa.short_name') }}">
    <meta name="application-name" content="{{ config('health.pwa.short_name') }}">

    {{-- iOS takes the home-screen icon from here in preference to the manifest,
         and it must be opaque: a PNG with transparency composites onto black. --}}
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">

    {{--
        WHICH BUILD DREW THIS PAGE.

        The md5 prefix of the Vite manifest — the same string the service worker
        uses as its cache name (App\Services\Pwa\BuildAssets::version). Shared
        with the root view by a composer in AppServiceProvider.

        It is here rather than in an Inertia prop because the one moment it is
        most needed is the moment Inertia did not start: resources/js/lib/report.js
        reads it out of the DOM and sends it with every client-side crash report,
        and a report without it cannot distinguish a live bug from a tab left
        open across three deploys.
    --}}
    <meta name="app-build" content="{{ $buildVersion }}">

    <title inertia>{{ config('app.name', 'Health Tracker') }}</title>

    {{--
        THE BOOT SPLASH'S STYLES, INLINE, ABOVE @vite.

        Inline because this is the stylesheet for the state in which the built
        stylesheet has not arrived — or has arrived as a 404, which after a
        deploy that deleted the build this document names is exactly what
        happens. A splash that needs the bundle to render cannot report on the
        bundle failing to load.

        Two rules earn their place:

        #app gets a MINIMUM HEIGHT. Before hydration that element is empty and
        the document is therefore about one viewport tall, which is how a
        restored scroll offset ends up pointing at nothing — the mechanism
        behind "reload while scrolled down and the page is white". A floor of
        one viewport means there is always something laid out to land on.

        #boot is hidden the instant #app has ANY child, by a sibling selector
        rather than by JavaScript. It costs nothing at runtime, it needs no
        clean-up call that could be skipped by the failure it is there for, and
        it disappears in the same frame the app first paints rather than one
        script tick later.
    --}}
    <style>
        #app { min-height: 100dvh; }

        #boot {
            position: fixed;
            inset: 0;
            z-index: 40;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 1.5rem;
            text-align: center;
            background: {{ config('health.pwa.background_color') }};
            color: #44403c;
            font: 400 0.875rem/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            #boot { background: {{ config('health.pwa.theme_color_dark') }}; color: #a8a29e; }
        }

        /* The app has painted. Everything below this line stops existing. */
        #app:not(:empty) + #boot { display: none; }

        #boot-mark {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 9999px;
            border: 3px solid rgba(13, 148, 136, 0.25);
            border-top-color: #0d9488;
            animation: boot-spin 900ms linear infinite;
        }

        @keyframes boot-spin { to { transform: rotate(360deg); } }

        /*
            After eight seconds, stop spinning reassuringly and say something
            useful. A pure-CSS delay, so this works when NO JavaScript ran at
            all — which is the case it exists for.
        */
        #boot-slow {
            visibility: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            animation: boot-reveal 0s linear 8s forwards;
        }

        @keyframes boot-reveal { to { visibility: visible; } }

        #boot-slow a {
            border-radius: 0.75rem;
            background: #0d9488;
            padding: 0.5rem 1rem;
            color: #fff;
            font-weight: 500;
            text-decoration: none;
        }

        @media (prefers-reduced-motion: reduce) {
            #boot-mark { animation: none; }
        }
    </style>

    {{-- No Ziggy: the app has five routes and they are written as plain paths
         in the Vue components. A route-name serialiser shipped on every page
         load would be more machinery than the thing it names. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans antialiased bg-stone-50 text-stone-900 dark:bg-stone-950 dark:text-stone-100">
    @inertia
    {{--
        WHAT IS ON SCREEN BEFORE THE APP IS.

        It must be the immediate next sibling of #app: the rule that hides it is
        `#app:not(:empty) + #boot`, and an element between the two would leave
        this spinner on top of a working app forever.

        The reported bug was "a completely blank white page — no header,
        nothing", and part of the answer is simply that a client-rendered app
        with an empty root IS a completely blank white page for as long as 338 KB
        of JavaScript takes to arrive and run on a phone. That window is normally
        short and invisible; when something goes wrong in it, it is permanent and
        indistinguishable from a crash. This makes both cases legible: a spinner
        while it is working, and a sentence with a way out when it is not.

        The escape is an `<a href="/">`, not a reload button. If the cause was a
        document naming build assets a deploy has since deleted, reloading that
        same document fails the same way; asking for `/` gets a fresh one. No
        JavaScript is involved in any of it.
    --}}
    <div id="boot" role="status" aria-live="polite">
        <div id="boot-mark" aria-hidden="true"></div>

        <p id="boot-slow">
            <span>This is taking longer than it should.</span>
            <a href="/">Open the app again</a>
        </p>
    </div>
</body>
</html>
