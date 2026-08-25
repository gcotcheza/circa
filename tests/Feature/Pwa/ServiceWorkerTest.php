<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Tests\TestCase;
use App\Services\Pwa\BuildAssets;

/**
 * The service worker script, its headers, and the precache list inside it. Two
 * failure modes are fenced off, both permanent once they reach a phone: A
 * CACHED WORKER — `/sw.js` served with a long max-age can never be updated, and
 * the only fix is asking the user to delete and re-add the app; and A STALE
 * AUTHENTICATED PAGE — caching rules that grow to cover HTML or /api can show
 * yesterday's calories as today's with nothing on screen to say so.
 */
final class ServiceWorkerTest extends TestCase
{
    public function test_the_worker_is_served_as_javascript_at_the_scope_root(): void
    {
        $response = $this->get('/sw.js');

        $response->assertOk();

        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));

        // Redundant from `/`, and what stops it losing scope the day it is not.
        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
    }

    public function test_the_worker_itself_is_never_cached_for_long(): void
    {
        $cacheControl = (string) $this->get('/sw.js')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('immutable', $cacheControl);

        // Any nonzero max-age here is an app that cannot be updated for that long.
        $this->assertDoesNotMatchRegularExpression('/max-age=[1-9]/', $cacheControl);
    }

    public function test_the_update_check_is_a_cheap_304(): void
    {
        $etag = $this->get('/sw.js')->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/sw.js')
            ->assertStatus(304);
    }

    public function test_the_precache_list_names_the_offline_page_and_the_built_entry_assets(): void
    {
        $body = $this->get('/sw.js')->getContent();
        self::assertIsString($body);

        // A worker still carrying `__SW_PRECACHE__` is a syntax error, so no worker.
        $this->assertStringNotContainsString('__SW_PRECACHE__', $body);
        $this->assertStringNotContainsString('__SW_VERSION__', $body);

        $this->assertStringContainsString('"/offline"', $body);
        $this->assertStringContainsString('"/manifest.webmanifest"', $body);
        $this->assertStringContainsString('"/icons/apple-touch-icon-180.png"', $body);

        foreach (app(BuildAssets::class)->entryUrls() as $url) {
            $this->assertStringContainsString(
                '"'.$url.'"',
                $body,
                "The worker does not precache {$url}, so a cold launch after a deploy has no app to load.",
            );
        }
    }

    public function test_the_version_moves_with_the_build_and_not_with_anything_else(): void
    {
        $first = $this->get('/sw.js')->getContent();
        $second = $this->get('/sw.js')->getContent();

        // Same build, same bytes, or the browser reinstalls on every navigation.
        $this->assertSame($first, $second);

        $missing = new BuildAssets(base_path('tests/does-not-exist.json'));

        $this->assertSame('no-build', $missing->version());

        /*
         * THE COMPARISON IS AGAINST A MANIFEST THIS TEST WRITES, NOT AGAINST
         * `public/build`. The old `assertNotSame((new BuildAssets)->version(),
         * ...)` holds until the checkout has never been built: then
         * `public/build/manifest.json` is missing too, BOTH sides are the
         * literal 'no-build', and it fails for the one reason it was never
         * about — passing on the deployed tree only because a past `npm run
         * build` left a manifest lying there, and failing first on a clean CI
         * runner. Constructing the built case is also stronger: the version is
         * pinned to the manifest's CONTENTS and shown to move with them, which
         * is the contract — a deploy busts the worker's cache, a restart does
         * not.
         */
        $path = tempnam(sys_get_temp_dir(), 'manifest').'.json';

        try {
            file_put_contents($path, json_encode([
                'resources/js/app.js' => ['file' => 'assets/app-AAAAAAAA.js', 'isEntry' => true],
            ]));

            $built = new BuildAssets($path);

            $this->assertNotSame('no-build', $built->version());
            $this->assertSame(substr((string) md5_file($path), 0, 12), $built->version());

            $before = $built->version();

            // A new build: different filename, manifest and worker version.
            file_put_contents($path, json_encode([
                'resources/js/app.js' => ['file' => 'assets/app-BBBBBBBB.js', 'isEntry' => true],
            ]));

            $this->assertNotSame($before, (new BuildAssets($path))->version());
        } finally {
            @unlink($path);
        }
    }

    public function test_the_worker_never_touches_authenticated_content(): void
    {
        $body = $this->get('/sw.js')->getContent();
        self::assertIsString($body);

        // Behavioural assertions on a file no unit test can run: the four early
        // returns that keep pages, props and API responses off the cached path.
        $this->assertStringContainsString("if (request.method !== 'GET') return", $body);
        $this->assertStringContainsString("if (url.pathname.startsWith('/api/')) return", $body);
        $this->assertStringContainsString("if (request.headers.get('X-Inertia')) return", $body);
        $this->assertStringContainsString('if (url.origin !== self.location.origin) return', $body);

        // No Background Sync: it does not exist in WebKit, so a `sync` listener
        // would be dead code that reads like a working feature.
        $this->assertStringNotContainsString("addEventListener('sync'", $body);
    }

    public function test_the_wasm_decoder_is_not_precached(): void
    {
        $urls = app(BuildAssets::class)->precacheUrls();

        foreach ($urls as $url) {
            $this->assertStringNotContainsString(
                '.wasm',
                $url,
                'Precaching the ~1 MB barcode decoder would download it on the first launch after every deploy.',
            );
        }
    }
}
