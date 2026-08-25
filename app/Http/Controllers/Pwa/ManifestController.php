<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

/**
 * `GET /manifest.webmanifest` — what "Add to Home Screen" reads.
 *
 * A route rather than a file in `public/` for the CONTENT TYPE:
 * `application/manifest+json` is correct, nginx's stock `mime.types` has no
 * `.webmanifest` entry, and the octet-stream fallback is a download rather than
 * a manifest. Declaring it here also lets the name and colours come from
 * config, so the manifest and the two `<meta name="theme-color">` tags cannot
 * drift apart.
 *
 * The only client is an iPhone, so which of these keys do anything there:
 *
 *   USED     name/short_name (the label under the icon), display:standalone
 *            (no browser chrome), start_url + scope (what counts as "in the
 *            app"), icons (a fallback if <link rel="apple-touch-icon"> is
 *            absent — it is not, so belt and braces).
 *   IGNORED  theme_color and background_color (iOS takes the status bar from
 *            the meta tags and generates no splash from these), shortcuts,
 *            categories, screenshots, and `purpose: maskable`.
 *
 * Maskable icons are declared and generated anyway, for Android and desktop
 * Chrome. There is NO install prompt on iOS — `beforeinstallprompt` does not
 * exist in WebKit; installing is Share -> Add to Home Screen by hand, from
 * Chrome's share menu as readily as Safari's, and this file is what makes the
 * result look like an app instead of a bookmark.
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $manifest = [
            'name'        => (string) config('health.pwa.name'),
            'short_name'  => (string) config('health.pwa.short_name'),
            'description' => 'Calories, protein and weight — logged by hand, by barcode, or by photograph.',

            /*
             * `/` rather than `/?source=pwa`: a tracking parameter would be a
             * query string the app has to ignore forever, and would make the
             * installed app's URL differ from the bookmarked one.
             */
            'start_url' => '/',
            'scope'     => '/',
            'id'        => '/',

            'display'     => 'standalone',
            'orientation' => 'portrait',

            'theme_color'      => (string) config('health.pwa.theme_color'),
            'background_color' => (string) config('health.pwa.background_color'),

            'lang' => 'en-GB',
            'dir'  => 'ltr',

            'icons' => [
                // Vector first: a browser that can use it never rasterises.
                [
                    'src'     => '/icons/icon.svg',
                    'sizes'   => 'any',
                    'type'    => 'image/svg+xml',
                    'purpose' => 'any',
                ],
                [
                    'src'     => '/icons/icon-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => '/icons/icon-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                // A separate drawing, not the same file tagged twice: a
                // maskable icon may be cropped to the circle inscribed in 80%
                // of the square, so its glyph is smaller. Declaring one file as
                // "any maskable" means shipping either a plain icon with a
                // needlessly small plate or a maskable one with its rim shaved.
                [
                    'src'     => '/icons/icon-maskable-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src'     => '/icons/icon-maskable-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response()
            ->json($manifest, 200, [
                // The point of this controller. `.webmanifest` is not in
                // nginx's stock mime.types, and octet-stream is a download.
                'Content-Type' => 'application/manifest+json',

                // An hour. The manifest changes about once a year and is only
                // read at install time, but it names icon paths — a day-long
                // cache would outlive a bad deploy that renamed one.
                'Cache-Control' => 'public, max-age=3600',
            ], JSON_UNESCAPED_SLASHES);
    }
}
