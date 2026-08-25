{{--
    The offline fallback.

    ---------------------------------------------------------------------------
    IT LOADS NOTHING. No @vite, no stylesheet link, no icon, no font, no script.

    Every asset this page referenced would be one more thing that has to already
    be in the cache for the page to render — and this is the page that renders
    when the network is gone, which is precisely when "already in the cache" is
    a bet rather than a fact. A single inline <style> block cannot half-load.

    It also carries NO DATA. It is precached, which means it is stored once and
    served for weeks; anything on it about calories or meals would be a number
    from whenever the app was last installed, presented as the truth.
    ---------------------------------------------------------------------------

    The colours are the literal values from config('health.pwa') — repeated here
    rather than interpolated, because a Blade expression in a precached page is
    still resolved at precache time and would only look dynamic.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#fafaf9" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0c0a09" media="(prefers-color-scheme: dark)">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <title>Offline · Health</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #fafaf9;
            --card: #ffffff;
            --line: #e7e5e4;
            --text: #1c1917;
            --muted: #78716c;
            --accent: #0d9488;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c0a09;
                --card: #1c1917;
                --line: #292524;
                --text: #f5f5f4;
                --muted: #a8a29e;
                --accent: #2dd4bf;
            }
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; margin: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;
            -webkit-text-size-adjust: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem calc(1rem + env(safe-area-inset-right)) calc(1.5rem + env(safe-area-inset-bottom)) calc(1rem + env(safe-area-inset-left));
        }

        main { width: 100%; max-width: 22rem; text-align: center; }

        .mark {
            width: 4.5rem;
            height: 4.5rem;
            margin: 0 auto 1.25rem;
            border-radius: 1.25rem;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        h1 { font-size: 1.125rem; font-weight: 600; letter-spacing: -0.01em; margin: 0 0 0.5rem; }

        p { font-size: 0.875rem; line-height: 1.55; color: var(--muted); margin: 0 0 0.75rem; }

        .card {
            margin-top: 1.5rem;
            border: 1px solid var(--line);
            background: var(--card);
            border-radius: 1rem;
            padding: 0.9rem 1rem;
            text-align: left;
        }

        .card p { margin: 0; font-size: 0.8125rem; }

        .card strong { color: var(--text); font-weight: 600; }

        button {
            margin-top: 1.5rem;
            width: 100%;
            border: 0;
            border-radius: 0.85rem;
            background: var(--accent);
            color: #ffffff;
            font: inherit;
            font-size: 1rem;
            font-weight: 600;
            padding: 0.8rem 1rem;
        }

        button:active { transform: scale(0.99); }
    </style>
</head>
<body>
    <main>
        {{-- The app icon's glyph, inline. Same geometry as
             App\Console\Commands\GeneratePwaIconsCommand, so the offline screen
             is recognisably the same app as the icon that opened it. --}}
        <div class="mark" aria-hidden="true">
            <svg width="44" height="44" viewBox="0 0 512 512">
                <circle cx="256" cy="256" r="168" fill="#fafaf9"/>
                <path d="M118.24 256 L198.88 256 L230.8 185.44 L266.08 329.92 L299.68 256 L393.76 256"
                      fill="none" stroke="#0d9488" stroke-width="29.9"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>

        <h1>No connection</h1>

        <p>
            The daily view needs the network — the numbers on it are worked out on the
            server, and showing you a cached copy would be showing you a different day.
        </p>

        <div class="card">
            <p>
                <strong>Anything you logged is safe.</strong>
                Meals, photos and one-tap re-logs are held on this phone and sent the
                moment there is a signal again. Nothing is lost by closing the app.
            </p>
        </div>

        <button type="button" onclick="location.reload()">Try again</button>
    </main>
</body>
</html>
